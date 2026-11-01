<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/** Independent approval intent. Existing publication/email ledgers own all provider receipts. */
class SmsRunAutomaticHandoffWorkflow
{
    public const TABLE = 'TblSMSAutomaticHandoffs';
    private const LEASE_SECONDS = 7800;
    private const COLUMNS = ['Request_ID','Plan_JSON','Plan_Sha256','State','Phase','Publication_Sha256','Email_Sha256',
        'Lease_Token','Lease_Version','Lease_Until','Created_At','Updated_At','Completed_At','Error_Code'];

    private readonly SmsRunPublicationWorkflow $publications;
    private readonly SmsRunEmailWorkflow $emails;
    private ?array $operation = null;
    public function __construct(private readonly SmsRunLinkPublisher $publisher,
        SmsRunArtifactPartReader $reader, SmsRunEmailGateway $emailGateway)
    {
        $guard = fn () => $this->providerBoundary();
        $this->publications = new SmsRunPublicationWorkflow(new SmsAutomaticHandoffPublisher($publisher, $guard), $reader);
        $this->emails = new SmsRunEmailWorkflow(new SmsAutomaticHandoffEmailGateway($emailGateway, $guard));
    }

    /** Called only at the successful approval boundary, inside that owner's transaction. */
    public function captureApproved(object $run): void
    {
        $this->scope(function () use ($run): void {
            if (!$this->enabled()) return;
            try {
                $pin = $this->pin(true); $plan = SmsAutomaticHandoffPlan::build($run, $this->publisher); $id = $plan['request_id'];
                $fresh = $this->source($pin, $id, true);
                if (SmsAutomaticHandoffPlan::build($fresh, $this->publisher) !== $plan) throw $this->failure();
                $existing = $this->row($pin, $id, true);
                if ($existing !== null) {
                    if ($this->plan($existing) !== $plan) throw $this->failure();
                    $this->guard($pin, $pin['level']); return;
                }
                foreach ([SmsRunPublicationWorkflow::TABLE, SmsRunEmailWorkflow::TABLE] as $table) {
                    if ($pin['db']->table($table)->useWritePdo()->where('Request_ID', $id)->exists()) throw $this->failure();
                }
                $json = SmsAutomaticHandoffPlan::json($plan);
                $pin['db']->table(self::TABLE)->insert(['Request_ID' => $id, 'Plan_JSON' => $json, 'Plan_Sha256' => hash('sha256', $json),
                    'State' => 'queued', 'Phase' => 'publish', 'Lease_Version' => 0, 'Created_At' => $this->now(), 'Updated_At' => $this->now()]);
                $this->guard($pin, $pin['level']);
            } catch (Throwable) { throw $this->failure(); }
        });
    }

    public function status(string $id): ?array
    {
        return $this->scope(function () use ($id): ?array {
            if (!$this->enabled()) return null;
            try { $this->id($id); $pin = $this->pin(false); $row = $this->row($pin, $id); $result = $row === null ? null : $this->project($row);
                $this->guard($pin, 0); return $result; } catch (Throwable) { throw $this->failure(); }
        });
    }

    /** One publication/prepare/send phase; unknown provider outcomes retain a non-immediate recovery lease. */
    public function step(string $id): array
    {
        return $this->scope(function () use ($id): array {
            try {
                $this->id($id); $pin = $this->pin(false);
                return SmsWorkflowLock::run('automatic-handoff:'.$id, function () use ($pin, $id): array {
                    $this->guard($pin, 0); $row = $this->required($pin, $id); $plan = $this->plan($row);
                    if (in_array($row->State, ['completed', 'held'], true)) return $this->project($row);
                    if ($row->State === 'working' && $this->stamp($row->Lease_Until) > $this->stamp($this->now())) return $this->project($row);
                    $expectedPlan = $row->Plan_Sha256;
                    $lease = $this->transaction($pin, function () use ($pin, $id, $expectedPlan): array {
                        $row = $this->required($pin, $id, true); $this->plan($row);
                        if ($row->Plan_Sha256 !== $expectedPlan) throw $this->failure();
                        if (in_array($row->State, ['completed', 'held'], true)
                            || ($row->State === 'working' && $this->stamp($row->Lease_Until) > $this->stamp($this->now()))) throw $this->failure();
                        $lease = ['token' => bin2hex(random_bytes(32)), 'version' => (int) $row->Lease_Version + 1,
                            'plan_sha256' => $row->Plan_Sha256, 'phase' => $row->Phase];
                        if ($pin['db']->table(self::TABLE)->where('Request_ID', $id)->where('Lease_Version', $row->Lease_Version)->update([
                            'State' => 'working', 'Lease_Token' => $lease['token'], 'Lease_Version' => $lease['version'],
                            'Lease_Until' => Carbon::now('UTC')->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s.u'),
                            'Error_Code' => null, 'Updated_At' => $this->now()]) !== 1) throw $this->failure();
                        return $lease;
                    });
                    $previous = $this->operation; $this->operation = compact('pin', 'id', 'plan', 'lease');
                    try { try {
                        $this->context($pin, $id, $plan, $lease);
                        $row = $this->required($pin, $id);
                        $mail = $this->emails->status($id); $this->guard($pin, 0);
                        if (in_array($mail['status'] ?? null, ['sending', 'uncertain'], true)) return $this->hold($pin, $id, $lease, 'email_uncertain');
                        if ($row->Phase === 'publish') {
                            if ($mail !== null) return $this->hold($pin, $id, $lease, 'manual_email_exists');
                            $this->verifyPublication($pin, $id, $plan, $lease, $row);
                            return $this->save($pin, $id, $lease, ['Phase' => 'prepare', 'Publication_Sha256' => $this->publicationSha($pin, $id, $plan)]);
                        }
                        if ($row->Phase === 'prepare') {
                            if ($mail !== null) return $this->hold($pin, $id, $lease, 'email_origin_unconfirmed');
                            $this->verifyPublication($pin, $id, $plan, $lease, $row);
                            $links = $this->publications->links($id); $this->context($pin, $id, $plan, $lease);
                            // Serialize the final absence check, preparation and own SHA capture
                            // with manual email callers. Nested EmailWorkflow uses this same reentrant session lock.
                            return SmsWorkflowLock::run('run:'.$id, function () use ($pin, $id, $plan, $lease, $links, $row): array {
                                $this->context($pin, $id, $plan, $lease);
                                if ($this->emails->status($id) !== null) return $this->hold($pin, $id, $lease, 'email_origin_unconfirmed');
                                $prepared = $this->emails->prepare($id, $links, $plan['actor']);
                                $this->context($pin, $id, $plan, $lease);
                                $this->publicationSha($pin, $id, $plan, $row->Publication_Sha256);
                                if (($prepared['status'] ?? null) !== 'ready' || !$this->hash($prepared['payload_sha256'] ?? null)) throw $this->drift();
                                return $this->save($pin, $id, $lease, ['Phase' => 'send', 'Email_Sha256' => $prepared['payload_sha256']]);
                            });
                        }
                        if ($row->Phase !== 'send' || !$this->hash($row->Email_Sha256)
                            || ($mail['payload_sha256'] ?? null) !== $row->Email_Sha256) throw $this->drift();
                        if (($mail['status'] ?? null) === 'accepted') return $this->complete($pin, $id, $lease);
                        if (($mail['status'] ?? null) !== 'ready') throw $this->drift();
                        $this->verifyPublication($pin, $id, $plan, $lease, $row);
                        $sent = $this->emails->send($id, $row->Email_Sha256);
                        $this->context($pin, $id, $plan, $lease);
                        return ($sent['status'] ?? null) === 'accepted' ? $this->complete($pin, $id, $lease) : $this->hold($pin, $id, $lease, 'email_uncertain');
                    } catch (Throwable $error) {
                        if ($error instanceof RuntimeException && $error->getCode() === 409) return $this->hold($pin, $id, $lease, 'context_changed');
                        // send() itself never retries attempting/needs_review. Inspect saved metadata only.
                        try {
                            $this->guard($pin, 0); $mail = $this->emails->status($id); $this->guard($pin, 0);
                            if (in_array($mail['status'] ?? null, ['sending', 'uncertain'], true)) return $this->hold($pin, $id, $lease, 'email_uncertain');
                            return $this->save($pin, $id, $lease, ['Error_Code' => 'pending_recovery'], true);
                        } catch (Throwable) { throw $this->failure(); }
                    } } finally { $this->operation = $previous; }
                });
            } catch (Throwable) { throw $this->failure(); }
        });
    }

    public function pending(int $limit = 10): array
    {
        return $this->scope(function () use ($limit): array {
            if (!$this->enabled()) return [];
            try {
                if ($limit < 1 || $limit > 100) throw $this->failure();
                $pin = $this->pin(false);
                $ids = $pin['db']->table(self::TABLE)->useWritePdo()->whereIn('State', ['queued','pending','working'])
                    ->where(fn ($q) => $q->whereNull('Lease_Until')->orWhere('Lease_Until', '<=', $this->now()))
                    ->orderBy('State')->orderBy('Lease_Until')->orderBy('Request_ID')->limit($limit)->pluck('Request_ID')->all();
                foreach ($ids as $id) $this->id($id); $this->guard($pin, 0); return $ids;
            } catch (Throwable) { throw $this->failure(); }
        });
    }

    private function verifyPublication(array $pin, string $id, array $plan, array $lease, object $row): void
    {
        $this->context($pin, $id, $plan, $lease);
        if ($row->Publication_Sha256 !== null) $this->publicationSha($pin, $id, $plan, $row->Publication_Sha256);
        $published = $this->publications->publish($id, $plan['actor']);
        $this->context($pin, $id, $plan, $lease);
        if (($published['status'] ?? null) !== 'published') throw $this->failure();
        $this->publicationSha($pin, $id, $plan, $row->Publication_Sha256);
    }

    private function providerBoundary(): void
    {
        if ($this->operation === null) throw $this->failure();
        $op = $this->operation; $this->context($op['pin'], $op['id'], $op['plan'], $op['lease']);
    }

    private function publicationSha(array $pin, string $id, array $plan, ?string $expected = null): string
    {
        $row = $pin['db']->table(SmsRunPublicationWorkflow::TABLE)->useWritePdo()->where('Request_ID', $id)->first();
        if ($row === null || $row->State !== 'published' || !$this->hash($row->Plan_Sha256)
            || hash('sha256', $row->Plan_JSON) !== $row->Plan_Sha256 || $row->Run_Sha256 !== $plan['run_sha256']
            || ($expected !== null && $row->Plan_Sha256 !== $expected)) throw $this->drift();
        $saved = json_decode($row->Plan_JSON, true, 32, JSON_THROW_ON_ERROR);
        if (($saved['provider_identity'] ?? null) !== $plan['publication']['identity'] || ($saved['policy'] ?? null) !== $plan['publication']['policy']) throw $this->drift();
        return $row->Plan_Sha256;
    }

    private function context(array $pin, string $id, array $plan, array $lease): void
    {
        $this->guard($pin, 0); $this->fenced($pin, $id, $lease);
        if (SmsAutomaticHandoffPlan::build($this->source($pin, $id), $this->publisher) !== $plan) throw $this->drift();
        try { SmsDeliveryPolicy::assertNotPast($plan['send_date']); } catch (Throwable) { throw $this->drift(); }
    }

    private function complete(array $pin, string $id, array $lease): array
    { return $this->save($pin, $id, $lease, ['State' => 'completed', 'Completed_At' => $this->now()]); }
    private function hold(array $pin, string $id, array $lease, string $code): array
    { return $this->save($pin, $id, $lease, ['State' => 'held', 'Error_Code' => $code]); }
    private function save(array $pin, string $id, array $lease, array $values, bool $backoff = false): array
    {
        $this->transaction($pin, function () use ($pin, $id, $lease, $values, $backoff): void {
            $this->fenced($pin, $id, $lease, true);
            $values += ['State' => $backoff ? 'working' : 'pending', 'Error_Code' => null, 'Updated_At' => $this->now(),
                'Lease_Token' => $backoff ? $lease['token'] : null,
                'Lease_Until' => $backoff ? Carbon::now('UTC')->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s.u') : null];
            if ($pin['db']->table(self::TABLE)->where('Request_ID', $id)->where('Lease_Token', $lease['token'])
                ->where('Lease_Version', $lease['version'])->update($values) !== 1) throw $this->failure();
        });
        return $this->project($this->required($pin, $id));
    }

    private function pin(bool $inside): array
    {
        $this->requireEnabled(); $db = DB::connection('sqlsrv'); $pdo = $db->getPdo(); $level = $db->transactionLevel();
        if (!in_array($db->getDriverName(), ['sqlsrv','sqlite'], true) || $db->getTablePrefix() !== ''
            || ($inside ? ($level < 1 || !$pdo->inTransaction()) : ($level !== 0 || $pdo->inTransaction()))
            || !$db->getSchemaBuilder()->hasColumns(self::TABLE, self::COLUMNS)) throw $this->failure();
        $pin = ['db' => $db, 'pdo' => $pdo, 'level' => $level, 'identity' => $this->identity($pdo, $db->getDriverName())];
        $this->guard($pin, $level); return $pin;
    }
    private function identity(PDO $pdo, string $driver): string
    { return SmsAutomaticHandoffPlan::json($pdo->query($driver === 'sqlsrv' ? 'SELECT DB_NAME() AS db,@@SPID AS session' : 'PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)); }
    private function guard(array $pin, int $level): void
    {
        $this->requireEnabled(); $db = $pin['db'];
        if (DB::connection('sqlsrv') !== $db || $db->getPdo() !== $pin['pdo'] || $db->getRawReadPdo() !== $pin['pdo'] || $db->getTablePrefix() !== ''
            || $db->transactionLevel() !== $level || $pin['pdo']->inTransaction() !== ($level > 0)
            || $this->identity($pin['pdo'], $db->getDriverName()) !== $pin['identity']) throw $this->failure();
    }
    private function transaction(array $pin, callable $work): mixed
    {
        $this->guard($pin, 0);
        try { return $pin['db']->transaction(function () use ($pin, $work): mixed {
            $this->guard($pin, 1); $result = $work(); $this->guard($pin, 1); return $result;
        }); } catch (Throwable) {
            if ($pin['db']->getPdo() !== $pin['pdo']) {
                foreach ([$pin['pdo'], $pin['db']->getPdo()] as $pdo) try { if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable) {}
                $pin['db']->disconnect();
            }
            throw $this->failure();
        }
    }
    private function fenced(array $pin, string $id, array $lease, bool $locked = false): object
    {
        $row = $this->required($pin, $id, $locked); $this->plan($row);
        if ($row->State !== 'working' || $row->Lease_Token !== $lease['token'] || (int) $row->Lease_Version !== $lease['version']
            || $row->Plan_Sha256 !== $lease['plan_sha256'] || $row->Phase !== $lease['phase']
            || $this->stamp($row->Lease_Until) <= $this->stamp($this->now())) throw $this->failure();
        return $row;
    }
    private function plan(object $row): array
    {
        if (!$this->hash($row->Plan_Sha256 ?? null) || !is_string($row->Plan_JSON ?? null) || strlen($row->Plan_JSON) > 131072
            || hash('sha256', $row->Plan_JSON) !== $row->Plan_Sha256) throw $this->failure();
        $plan = json_decode($row->Plan_JSON, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($plan) || SmsAutomaticHandoffPlan::json($plan) !== $row->Plan_JSON || ($plan['version'] ?? null) !== 1
            || ($plan['request_id'] ?? null) !== $row->Request_ID || !$this->hash($plan['run_sha256'] ?? null)
            || !in_array($row->State, ['queued','working','pending','completed','held'], true)
            || !in_array($row->Phase, ['publish','prepare','send'], true)
            || !in_array($row->Error_Code, [null,'pending_recovery','context_changed','email_uncertain','manual_email_exists','email_origin_unconfirmed'], true)
            || !is_numeric($row->Lease_Version) || (int) $row->Lease_Version < 0 || (int) $row->Lease_Version >= PHP_INT_MAX
            || ($row->State === 'working' ? (!$this->hash($row->Lease_Token) || $this->stamp($row->Lease_Until) === null)
                : ($row->Lease_Token !== null || $row->Lease_Until !== null))
            || ($row->State === 'completed' && ($row->Phase !== 'send' || !$this->hash($row->Email_Sha256) || $this->stamp($row->Completed_At) === null))) throw $this->failure();
        return $plan;
    }
    private function project(object $row): array
    {
        $this->plan($row);
        if ($row->State === 'completed') {
            $mail = $this->emails->status($row->Request_ID);
            if (($mail['status'] ?? null) !== 'accepted' || ($mail['payload_sha256'] ?? null) !== $row->Email_Sha256) throw $this->failure();
        }
        return ['request_id' => $row->Request_ID, 'status' => $row->State, 'phase' => $row->Phase,
            'payload_sha256' => $row->Email_Sha256, 'error_code' => $row->Error_Code,
            'again' => $row->State === 'pending' && $row->Error_Code === null,
            'note' => $row->State === 'completed' ? 'Email provider accepted the message; delivery is not confirmed.' : null];
    }
    private function row(array $pin, string $id, bool $locked = false): ?object
    { $q = $pin['db']->table(self::TABLE)->useWritePdo()->where('Request_ID', $id); if ($locked) $q->lockForUpdate(); return $q->first(); }
    private function required(array $pin, string $id, bool $locked = false): object { return $this->row($pin, $id, $locked) ?? throw $this->failure(); }
    private function source(array $pin, string $id, bool $locked = false): object
    { $q = $pin['db']->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $id); if ($locked) $q->lockForUpdate(); return $q->first() ?? throw $this->drift(); }
    private function scope(callable $work): mixed
    {
        return SmsDebtPleteImportPrivacy::run(function () use ($work): mixed {
            $restore = false;
            try {
                if (!$this->enabled()) return $work();
                $db = DB::connection('sqlsrv'); $pdo = $db->getPdo(); $read = $db->getRawReadPdo();
                // Includes metadata and the existing nested run-applock implementation.
                // Never resolve/open the configured read replica, and restore its exact lazy handle.
                $restore = true; $db->setReadPdo($pdo);
                return $work();
            } catch (Throwable) {
                if ($restore && $db->getRawPdo() !== $pdo) {
                    foreach ([$pdo, $db->getRawPdo()] as $handle) try { if ($handle instanceof PDO && $handle->inTransaction()) $handle->rollBack(); } catch (Throwable) {}
                    $db->disconnect();
                }
                throw $this->failure();
            } finally { if ($restore) $db->setReadPdo($read); }
        });
    }
    private function enabled(): bool
    { return config('sms-exports.handoff_automatic', false) === true && config('sms-exports.file_publication_enabled', false) === true && config('sms-exports.email_handoff_enabled', false) === true; }
    private function requireEnabled(): void { if (!$this->enabled()) throw $this->failure(); }
    private function id(string $id): void { if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id)) throw $this->failure(); }
    private function hash(mixed $s): bool { return is_string($s) && preg_match('/\A[a-f0-9]{64}\z/D', $s) === 1; }
    private function stamp(mixed $s): ?string
    { if (!is_string($s) || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,7})?\z/D', $s)) return null; return substr($s, 0, 19).'.'.str_pad(explode('.', $s, 2)[1] ?? '', 7, '0'); }
    private function now(): string { return Carbon::now('UTC')->format('Y-m-d H:i:s.u'); }
    private function failure(): RuntimeException { return new RuntimeException('The automatic SMS handoff is unconfirmed; inspect its saved receipts.'); }
    private function drift(): RuntimeException { return new RuntimeException('The automatic SMS handoff context changed.', 409); }
}
