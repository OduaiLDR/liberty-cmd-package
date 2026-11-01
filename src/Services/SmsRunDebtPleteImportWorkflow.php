<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/** Durable approved-file outbox. Every network result is reconciled against the same sealed plan. */
class SmsRunDebtPleteImportWorkflow
{
    public const TABLE = 'TblSMSDebtPleteImports';
    private const STATES = ['queued', 'working', 'pending', 'completed', 'held'];
    private const ERRORS = [null, 'pending_recovery', 'receipt_invalid', 'source_unconfirmed', 'remote_held'];
    private const LEASE_SECONDS = 1500;

    public function __construct(private readonly SmsDebtPleteImportGateway $gateway, private readonly SmsRunArtifactPartReader $reader) {}

    /** Called only inside the approval owner's existing transaction; no files or network are opened. */
    public function captureApproved(object $run): void
    {
        if (!$this->enabled() || config('sms-exports.debtplete_import_automatic', false) !== true) { return; }
        try {
            $pin = $this->pin(true);
            $identity = $this->identity();
            $plan = $this->approved($run, $identity);
            $this->guard($pin, $pin['level']);
            $fresh = $this->run($pin, $plan->requestId(), true);
            $this->sameApproved($plan, $fresh, $identity);
            $this->insert($pin, $plan, (string) $run->Approved_By, $identity);
            $this->guard($pin, $pin['level']);
        } catch (Throwable) { throw $this->failure(); }
    }

    public function prepare(string $id, string $actor): array
    {
        $this->requireEnabled(); $this->id($id);
        if (!filter_var($actor, FILTER_VALIDATE_EMAIL) || strlen($actor) > 255 || $actor !== trim($actor)
            || preg_match('/[\x00-\x20\x7f]/', $actor)) { throw $this->failure(); }
        return $this->operate($id, function (array $pin) use ($id, $actor): array {
            $identity = $this->identity(); $run = $this->run($pin, $id);
            $plan = $this->approved($run, $identity);
            $existing = $this->row($pin, $id);
            if ($existing !== null) {
                $this->sameStored($existing, $plan, $identity);
                return $this->project($existing);
            }
            $this->verifyWhole($pin, $plan);
            $this->transaction($pin, function () use ($pin, $id, $actor, $plan, $identity): void {
                $this->sameApproved($plan, $this->run($pin, $id, true), $identity);
                $this->insert($pin, $plan, $actor, $identity);
            });
            return $this->project($this->requiredRow($pin, $id));
        });
    }

    /** One status reconciliation and at most one CSV upload. Receiver processing is asynchronous. */
    public function step(string $id): array
    {
        $this->requireEnabled(); $this->id($id);
        return $this->operate($id, function (array $pin) use ($id): array {
            $identity = $this->identity(); $plan = $this->approved($this->run($pin, $id), $identity);
            $row = $this->requiredRow($pin, $id); $this->sameStored($row, $plan, $identity);
            if (in_array($row->State, ['completed', 'held'], true)) { return $this->project($row); }
            $lease = $this->transaction($pin, function () use ($pin, $id, $plan, $identity): ?array {
                $row = $this->requiredRow($pin, $id, true);
                $this->sameApproved($plan, $this->run($pin, $id, true), $identity);
                $this->sameStored($row, $plan, $identity);
                if ($row->State === 'working' && $this->stamp($row->Lease_Until) > $this->stamp($this->now())) { return null; }
                // Keep expired wait evidence before replacing the lease. A saved uploading
                // observation (or uncertain PUT marker) permits one retry after that wait.
                $waitedPart = null;
                if ($row->State === 'working') {
                    foreach (($this->receipt($row, $plan)['parts'] ?? []) as $part) {
                        if (!$part['accepted']) {
                            if ($row->Error_Code === 'pending_recovery') { $waitedPart = $part['part']; }
                            break;
                        }
                    }
                }
                $lease = ['token' => bin2hex(random_bytes(32)), 'version' => (int) $row->Lease_Version + 1, 'waited_part' => $waitedPart];
                if ($pin['db']->table(self::TABLE)->where('Request_ID', $id)->where('Lease_Version', $row->Lease_Version)
                    ->whereIn('State', ['queued', 'pending', 'working'])->update([
                        'State' => 'working', 'Lease_Token' => $lease['token'], 'Lease_Version' => $lease['version'],
                        'Lease_Until' => Carbon::now('UTC')->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s.u'),
                        'Updated_At' => $this->now(), 'Error_Code' => null,
                    ]) !== 1) { throw $this->failure(); }
                return $lease;
            });
            if ($lease === null) { return $this->project($this->requiredRow($pin, $id)); }

            try {
                $this->assertCurrent($pin, $id, $plan, $identity, $lease, false);
                try { $remote = $this->gateway->status($id); }
                catch (RuntimeException $error) {
                    if ($error->getCode() !== 404) { throw $error; }
                    $this->assertCurrent($pin, $id, $plan, $identity, $lease, false);
                    SmsFrozenEligibility::assertHandoff($id);
                    $remote = $this->gateway->register($plan->payload());
                }
                $this->assertCurrent($pin, $id, $plan, $identity, $lease, false);
            } catch (SmsFrozenEligibilityHold) {
                $this->hold($pin, $id, $plan, $identity, $lease, 'source_unconfirmed');
                return $this->project($this->requiredRow($pin, $id));
            } catch (Throwable) { return $this->pendingResult($pin, $id, $plan, $identity, $lease); }
            $remote = $this->validatedRemote($pin, $id, $plan, $identity, $lease, $remote);
            if ($remote === null) { return $this->project($this->requiredRow($pin, $id)); }
            if (in_array($remote['state'], ['completed', 'held'], true)) {
                return $this->finishRemote($pin, $id, $plan, $identity, $lease, $remote);
            }
            $number = null; $uploading = false;
            foreach ($remote['parts'] as $part) {
                if (!$part['accepted']) { $number = $part['part']; $uploading = $part['state'] === 'uploading'; break; }
            }
            if ($uploading && $lease['waited_part'] !== $number) {
                $this->saveRemote($pin, $id, $plan, $identity, $lease, $remote, 'working', true);
                return $this->project($this->requiredRow($pin, $id));
            }
            $this->saveRemote($pin, $id, $plan, $identity, $lease, $remote, 'working');
            if ($number === null) {
                $this->saveRemote($pin, $id, $plan, $identity, $lease, $remote, 'pending');
                return $this->project($this->requiredRow($pin, $id));
            }
            $this->outside($pin);
            try {
                $this->reader->oneVerified($this->directory($id), $plan->payload()['manifest'], $plan->options(), $plan->rows(), $number,
                    function (array $part, mixed $stream) use ($pin, $id, $plan, $identity, $lease, &$uploaded): void {
                        SmsFrozenEligibility::assertHandoff($id);
                        try {
                            $this->assertCurrent($pin, $id, $plan, $identity, $lease, false);
                            $uploaded = $this->gateway->upload($id, $part['part'], $plan->sha256(), $stream);
                            $this->assertCurrent($pin, $id, $plan, $identity, $lease, false);
                        }
                        catch (Throwable) { throw new RuntimeException('SMS receiver acknowledgement is uncertain.', 502); }
                    });
            } catch (Throwable $error) {
                if ($error->getCode() === 502) { return $this->pendingResult($pin, $id, $plan, $identity, $lease, true); }
                $this->hold($pin, $id, $plan, $identity, $lease, 'source_unconfirmed');
                return $this->project($this->requiredRow($pin, $id));
            }
            $this->outside($pin);
            $uploaded = $this->validatedRemote($pin, $id, $plan, $identity, $lease, $uploaded ?? []);
            if ($uploaded === null) { return $this->project($this->requiredRow($pin, $id)); }
            return $this->finishRemote($pin, $id, $plan, $identity, $lease, $uploaded);
        });
    }

    /** Bounded saved receipt/source metadata reads; no filesystem, network or queue access. */
    public function status(string $id): ?array
    {
        if (!$this->enabled()) { return null; }
        $this->id($id);
        try {
            $pin = $this->pin(false);
            $row = $this->row($pin, $id);
            $result = $row === null ? null : $this->project($row);
            $this->outside($pin);
            return $result;
        } catch (Throwable) { throw $this->failure(); }
    }

    /** Ledger-only recovery discovery; never retroactively scans approved source runs. */
    public function pending(int $limit = 10): array
    {
        if (!$this->enabled()) { return []; }
        if ($limit < 1 || $limit > 100) { throw $this->failure(); }
        try {
            $pin = $this->pin(false);
            $ids = $pin['db']->table(self::TABLE)->useWritePdo()
                ->whereIn('State', ['queued', 'pending', 'working'])
                ->where(fn ($query) => $query->whereNull('Lease_Until')->orWhere('Lease_Until', '<=', $this->now()))
                ->orderBy('State')->orderBy('Lease_Until')->orderBy('Request_ID')->limit($limit)->pluck('Request_ID')->all();
            foreach ($ids as $id) { $this->id($id); }
            $this->outside($pin);
            return $ids;
        } catch (Throwable) { throw $this->failure(); }
    }

    private function operate(string $id, callable $operation): array
    {
        try {
            $pin = $this->pin(false);
            return SmsWorkflowLock::run('run:'.$id, function () use ($pin, $operation): array {
                $this->outside($pin); $result = $operation($pin); $this->outside($pin);
                return $result;
            });
        } catch (Throwable) { throw $this->failure(); }
    }

    private function pin(bool $inside): array
    {
        $this->requireEnabled();
        $db = DB::connection('sqlsrv'); $pdo = $db->getPdo(); $level = $db->transactionLevel();
        if ($db->getTablePrefix() !== '' || !in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true)
            || ($inside ? ($level < 1 || !$pdo->inTransaction()) : ($level !== 0 || $pdo->inTransaction()))) { throw $this->failure(); }
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable(self::TABLE) || !$schema->hasColumns(self::TABLE, [
            'Request_ID', 'Run_Sha256', 'Destination_JSON', 'Destination_Sha256', 'Plan_JSON', 'Plan_Sha256', 'State',
            'Remote_Receipt_JSON', 'Remote_Receipt_Sha256', 'Lease_Token', 'Lease_Version', 'Lease_Until',
            'Created_By', 'Created_At', 'Updated_At', 'Completed_At', 'Error_Code',
        ])) { throw $this->failure(); }
        $pin = ['db' => $db, 'pdo' => $pdo, 'level' => $level, 'driver' => $db->getDriverName(), 'identity' => $this->sqlIdentity($pdo, $db->getDriverName())];
        $this->guard($pin, $level);
        return $pin;
    }

    private function sqlIdentity(PDO $pdo, string $driver): string
    {
        $query = $driver === 'sqlsrv' ? 'SELECT DB_NAME() AS database_name, @@SPID AS session_id' : 'PRAGMA database_list';
        return $this->json($pdo->query($query)->fetchAll(PDO::FETCH_ASSOC));
    }

    private function guard(array $pin, int $level): void
    {
        $this->requireEnabled(); $db = $pin['db'];
        if (DB::connection('sqlsrv') !== $db || $db->getPdo() !== $pin['pdo'] || $db->getTablePrefix() !== '' || $db->getDriverName() !== $pin['driver']
            || $db->transactionLevel() !== $level || $pin['pdo']->inTransaction() !== ($level > 0)
            || $this->sqlIdentity($pin['pdo'], $db->getDriverName()) !== $pin['identity']) { throw $this->failure(); }
    }

    private function outside(array $pin): void { $this->guard($pin, 0); }

    private function transaction(array $pin, callable $operation): mixed
    {
        $this->outside($pin);
        try {
            return $pin['db']->transaction(function () use ($pin, $operation): mixed {
                $this->guard($pin, 1); $result = $operation(); $this->guard($pin, 1);
                return $result;
            });
        } catch (Throwable) {
            // A reconnect during BEGIN must never run a callback on the replacement session.
            if ($pin['db']->getPdo() !== $pin['pdo']) {
                foreach ([$pin['pdo'], $pin['db']->getPdo()] as $connection) {
                    try { if ($connection instanceof PDO && $connection->inTransaction()) { $connection->rollBack(); } } catch (Throwable) {}
                }
                $pin['db']->disconnect();
            }
            throw $this->failure();
        }
    }

    private function approved(object $run, array $identity): SmsDebtPleteImportPlan
    {
        $plan = SmsDebtPleteImportPlan::fromApproved($run, $identity);
        $expected = $this->directory($plan->requestId()).'/v2/published/'.$plan->payload()['manifest']['download']['name'];
        if (($run->File_Path ?? null) !== $expected) { throw $this->failure(); }
        return $plan;
    }

    private function directory(string $id): string
    {
        $root = config('sms-exports.local_root') ?: storage_path('app/private/sms-exports');
        if (!is_string($root) || $root === '' || preg_match('/[\x00\r\n]/', $root)) { throw $this->failure(); }
        return str_replace('\\', '/', rtrim($root, '/\\')).'/runs/'.$id;
    }

    private function verifyWhole(array $pin, SmsDebtPleteImportPlan $plan): void
    {
        $this->outside($pin);
        $this->verifyWholeFiles($plan);
        $this->outside($pin);
    }

    private function verifyWholeFiles(SmsDebtPleteImportPlan $plan): void
    {
        (new SmsRunArtifactBundle('v2'))->verify($this->directory($plan->requestId()), $plan->payload()['manifest'], $plan->options(), $plan->rows());
    }

    private function identity(): array
    {
        $identity = $this->gateway->identity();
        if (count($identity) !== 4 || array_diff(['protocol', 'origin', 'tenant_id', 'client_id'], array_keys($identity)) !== []) { throw $this->failure(); }
        return ['protocol' => $identity['protocol'], 'origin' => $identity['origin'], 'tenant_id' => $identity['tenant_id'], 'client_id' => $identity['client_id']];
    }

    private function sameDestination(array $identity): void { if ($this->identity() !== $identity) { throw $this->failure(); } }

    private function sameApproved(SmsDebtPleteImportPlan $plan, object $run, array $identity): void
    {
        $this->sameDestination($identity); $fresh = $this->approved($run, $identity);
        if ($fresh->fingerprint() !== $plan->fingerprint() || $fresh->json() !== $plan->json()) { throw $this->failure(); }
    }

    private function insert(array $pin, SmsDebtPleteImportPlan $plan, string $actor, array $identity): void
    {
        $existing = $this->row($pin, $plan->requestId(), true);
        if ($existing !== null) { $this->sameStored($existing, $plan, $identity); return; }
        $destination = $this->json($identity); $now = $this->now();
        $pin['db']->table(self::TABLE)->insert(['Request_ID' => $plan->requestId(), 'Run_Sha256' => $plan->fingerprint(),
            'Destination_JSON' => $destination, 'Destination_Sha256' => hash('sha256', $destination),
            'Plan_JSON' => $plan->json(), 'Plan_Sha256' => $plan->sha256(), 'State' => 'queued',
            'Lease_Version' => 0, 'Created_By' => $actor, 'Created_At' => $now, 'Updated_At' => $now]);
    }

    private function stored(object $row): SmsDebtPleteImportPlan
    {
        $this->id($row->Request_ID ?? '');
        foreach (['Destination', 'Plan', 'Remote_Receipt'] as $prefix) {
            $json = $row->{$prefix.'_JSON'} ?? null; $sha = $row->{$prefix.'_Sha256'} ?? null;
            if ($prefix === 'Remote_Receipt' && $json === null && $sha === null) { continue; }
            if (!is_string($json) || strlen($json) > 1048576 || !$this->hash($sha) || !hash_equals($sha, hash('sha256', $json))) { throw $this->failure(); }
        }
        $identity = $this->decode($row->Destination_JSON);
        $this->sameDestination($identity);
        $run = DB::connection('sqlsrv')->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $row->Request_ID)->first();
        if ($run === null) { throw $this->failure(); }
        $plan = $this->approved($run, $identity);
        if ($row->Plan_JSON !== $plan->json() || $row->Plan_Sha256 !== $plan->sha256() || $row->Run_Sha256 !== $plan->fingerprint()) { throw $this->failure(); }
        if ($plan->requestId() !== $row->Request_ID || !$this->hash($row->Run_Sha256 ?? null)
            || !in_array($row->State ?? null, self::STATES, true) || !in_array($row->Error_Code ?? null, self::ERRORS, true)
            || !(is_int($row->Lease_Version ?? null) || is_string($row->Lease_Version ?? null))
            || !is_numeric($row->Lease_Version) || (string) (int) $row->Lease_Version !== (string) $row->Lease_Version
            || (int) $row->Lease_Version < 0 || (int) $row->Lease_Version >= PHP_INT_MAX) { throw $this->failure(); }
        if ($row->State === 'working') {
            if (!$this->hash($row->Lease_Token ?? null) || (int) $row->Lease_Version < 1 || $this->stamp($row->Lease_Until ?? null) === null) { throw $this->failure(); }
        } elseif (($row->Lease_Token ?? null) !== null || ($row->Lease_Until ?? null) !== null) { throw $this->failure(); }
        $remote = $this->receipt($row, $plan);
        if ($row->State === 'completed' && (($remote['state'] ?? null) !== 'completed' || $this->stamp($row->Completed_At ?? null) === null)) { throw $this->failure(); }
        return $plan;
    }

    private function sameStored(object $row, SmsDebtPleteImportPlan $plan, array $identity): void
    {
        $saved = $this->stored($row);
        if ($saved->json() !== $plan->json() || $row->Run_Sha256 !== $plan->fingerprint()
            || $row->Destination_JSON !== $this->json($identity)) { throw $this->failure(); }
    }

    private function receipt(object $row, SmsDebtPleteImportPlan $plan): ?array
    {
        return $row->Remote_Receipt_JSON === null ? null : SmsDebtPleteImportReceipt::validate($this->decode($row->Remote_Receipt_JSON), $plan);
    }

    private function assertCurrent(array $pin, string $id, SmsDebtPleteImportPlan $plan, array $identity, array $lease, bool $locked): object
    {
        $this->guard($pin, $locked ? 1 : 0);
        $this->sameApproved($plan, $this->run($pin, $id, $locked), $identity);
        $row = $this->requiredRow($pin, $id, $locked); $this->sameStored($row, $plan, $identity);
        if ($row->State !== 'working' || $row->Lease_Token !== $lease['token'] || (int) $row->Lease_Version !== $lease['version']
            || $this->stamp($row->Lease_Until) <= $this->stamp($this->now())) { throw $this->failure(); }
        return $row;
    }

    private function saveRemote(array $pin, string $id, SmsDebtPleteImportPlan $plan, array $identity, array $lease, array $remote, string $state, bool $waitForUpload = false): void
    {
        $this->transaction($pin, function () use ($pin, $id, $plan, $identity, $lease, $remote, $state, $waitForUpload): void {
            $row = $this->assertCurrent($pin, $id, $plan, $identity, $lease, true);
            $safe = SmsDebtPleteImportReceipt::validate($remote, $plan); $previous = $this->receipt($row, $plan);
            if ($previous !== null) { SmsDebtPleteImportReceipt::assertNotRegressed($previous, $safe); }
            $json = $this->json($safe);
            $values = ['Remote_Receipt_JSON' => $json, 'Remote_Receipt_Sha256' => hash('sha256', $json),
                'State' => $state, 'Updated_At' => $this->now(), 'Error_Code' => $state === 'held' ? 'remote_held' : ($waitForUpload ? 'pending_recovery' : null)];
            if ($state !== 'working') { $values += ['Lease_Token' => null, 'Lease_Until' => null]; }
            elseif ($waitForUpload) { $values['Lease_Until'] = Carbon::now('UTC')->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s.u'); }
            if ($state === 'completed') { $values['Completed_At'] = $this->now(); }
            if ($pin['db']->table(self::TABLE)->where('Request_ID', $id)->where('Lease_Token', $lease['token'])->where('Lease_Version', $lease['version'])->update($values) !== 1) { throw $this->failure(); }
        });
    }

    private function validatedRemote(array $pin, string $id, SmsDebtPleteImportPlan $plan, array $identity, array $lease, array $remote): ?array
    {
        // A failed local read is uncertainty, not evidence that the remote receipt is malformed.
        $previous = $this->receipt($this->requiredRow($pin, $id), $plan);
        try {
            $safe = SmsDebtPleteImportReceipt::validate($remote, $plan);
            if ($previous !== null) { SmsDebtPleteImportReceipt::assertNotRegressed($previous, $safe); }
            return $safe;
        } catch (Throwable) {
            $this->hold($pin, $id, $plan, $identity, $lease, 'receipt_invalid');
            return null;
        }
    }

    private function finishRemote(array $pin, string $id, SmsDebtPleteImportPlan $plan, array $identity, array $lease, array $remote): array
    {
        $state = $remote['state'] === 'held' ? 'held' : 'pending';
        if ($remote['state'] === 'completed') {
            $this->outside($pin);
            try { $this->verifyWholeFiles($plan); }
            catch (Throwable) {
                $this->hold($pin, $id, $plan, $identity, $lease, 'source_unconfirmed');
                return $this->project($this->requiredRow($pin, $id));
            }
            $this->outside($pin);
            $state = 'completed';
        }
        $waitForUpload = $state === 'pending' && array_filter($remote['parts'], static fn (array $part): bool => !$part['accepted'] && $part['state'] === 'uploading') !== [];
        if ($waitForUpload) { $state = 'working'; }
        try { $this->saveRemote($pin, $id, $plan, $identity, $lease, $remote, $state, $waitForUpload); }
        catch (Throwable) { return $this->pendingResult($pin, $id, $plan, $identity, $lease); }
        return $this->project($this->requiredRow($pin, $id));
    }

    private function hold(array $pin, string $id, SmsDebtPleteImportPlan $plan, array $identity, array $lease, string $code): void
    {
        $this->transaction($pin, function () use ($pin, $id, $plan, $identity, $lease, $code): void {
            $this->assertCurrent($pin, $id, $plan, $identity, $lease, true);
            if ($pin['db']->table(self::TABLE)->where('Request_ID', $id)->where('Lease_Token', $lease['token'])->where('Lease_Version', $lease['version'])
                ->update(['State' => 'held', 'Error_Code' => $code, 'Lease_Token' => null, 'Lease_Until' => null, 'Updated_At' => $this->now()]) !== 1) { throw $this->failure(); }
        });
    }

    private function pendingResult(array $pin, string $id, SmsDebtPleteImportPlan $plan, array $identity, array $lease, bool $uncertainUpload = false): array
    {
        try {
            $this->transaction($pin, function () use ($pin, $id, $plan, $identity, $lease, $uncertainUpload): void {
                $row = $this->requiredRow($pin, $id, true);
                if (in_array($row->State, ['completed', 'held'], true)) { return; }
                $this->assertCurrent($pin, $id, $plan, $identity, $lease, true);
                $values = ['State' => $uncertainUpload ? 'working' : 'pending', 'Error_Code' => 'pending_recovery', 'Updated_At' => $this->now(),
                    'Lease_Token' => $uncertainUpload ? $lease['token'] : null,
                    'Lease_Until' => $uncertainUpload ? Carbon::now('UTC')->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s.u') : null];
                if ($pin['db']->table(self::TABLE)->where('Request_ID', $id)->where('Lease_Token', $lease['token'])->where('Lease_Version', $lease['version'])
                    ->update($values) !== 1) { throw $this->failure(); }
            });
        } catch (Throwable) { /* Unknown commit/session results retain the lease and durable last known receipt. */ }
        $this->outside($pin);
        return $this->project($this->requiredRow($pin, $id));
    }

    private function project(object $row): array
    {
        $plan = $this->stored($row); $remote = $this->receipt($row, $plan);
        return ['request_id' => $plan->requestId(), 'status' => $row->State, 'processed' => $remote['processed'] ?? null,
            'total' => $plan->rows(), 'files_accepted' => $remote === null ? null : count(array_filter($remote['parts'], static fn (array $part): bool => $part['accepted'])),
            'files_total' => count($plan->parts()), 'remote_status' => $remote['status'] ?? null,
            'error_code' => $row->Error_Code, 'retry_pending' => in_array($row->State, ['queued', 'working', 'pending'], true)];
    }

    private function row(array $pin, string $id, bool $locked = false): ?object
    {
        $query = $pin['db']->table(self::TABLE)->useWritePdo()->where('Request_ID', $id);
        if ($locked) { $query->lockForUpdate(); }
        return $query->first();
    }
    private function requiredRow(array $pin, string $id, bool $locked = false): object { return $this->row($pin, $id, $locked) ?? throw $this->failure(); }
    private function run(array $pin, string $id, bool $locked = false): object
    {
        $query = $pin['db']->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $id);
        if ($locked) { $query->lockForUpdate(); }
        return $query->first() ?? throw $this->failure();
    }
    private function now(): string { return Carbon::now('UTC')->format('Y-m-d H:i:s.u'); }
    private function stamp(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,7}))?\z/D', $value, $match) !== 1
            || !checkdate((int) $match[2], (int) $match[3], (int) $match[1]) || (int) $match[4] > 23 || (int) $match[5] > 59 || (int) $match[6] > 59) { return null; }
        return substr($value, 0, 19).'.'.str_pad($match[7] ?? '', 7, '0');
    }
    private function enabled(): bool { return config('sms-exports.debtplete_import_enabled', false) === true; }
    private function requireEnabled(): void { if (!$this->enabled()) { throw $this->failure(); } }
    private function id(mixed $id): void { if (!is_string($id) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id) !== 1) { throw $this->failure(); } }
    private function hash(mixed $value): bool { return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1; }
    private function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
    private function decode(string $json): array { $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR); if (!is_array($value)) { throw $this->failure(); } return $value; }
    private function failure(): RuntimeException { return new RuntimeException('The saved SMS import handoff could not be confirmed; inspect its durable receipts.'); }
}
