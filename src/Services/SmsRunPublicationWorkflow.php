<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Explicit recoverable file publication; independent of approval, archival and email sending. */
class SmsRunPublicationWorkflow
{
    public const TABLE = 'TblSMSFilePublications';
    public const PARTS = 'TblSMSFilePublicationParts';

    public function __construct(private readonly SmsRunLinkPublisher $publisher, private readonly SmsRunArtifactPartReader $reader) {}

    /** Queue preflight only: metadata and explicit local settings, no artifact/provider I/O or writes. */
    public function assertPublishable(string $id): void
    {
        $this->id($id);
        if (! $this->enabled()) throw new HttpException(503, 'SMS file publication is not enabled.');
        $db = DB::connection('sqlsrv');
        $this->outside($db);
        if (! $this->schemaReady()) throw new HttpException(503, 'Apply the explicit SMS publication migration first.');
        $pdo = $db->getPdo();
        $run = $this->run($id);
        $context = $this->context($run, false);
        SmsDeliveryPolicy::assertNotPast($context['options']['send_date']);
        $identity = $this->publisher->identity();
        $policy = $this->publisher->policy($context['options']['send_date']);
        $this->outside($db, $pdo);
        if ($identity === '' || strlen($identity) > 255 || preg_match('/[^\x21-\x7e]/', $identity)
            || $policy === [] || strlen($this->json($policy)) > 65536) throw new RuntimeException('Invalid explicit SMS publication provider policy.');
        $row = $this->publication($id);
        if ($row !== null) {
            $plan = $this->plan($row, $run, $context);
            if ($plan['provider_identity'] !== $identity || $plan['policy'] !== $policy) throw new RuntimeException('The frozen SMS publication provider policy changed.');
            $this->project($row, $plan, $this->parts($id, $plan));
        }
    }

    public function publish(string $id, string $actor): array
    {
        if (! filter_var($actor, FILTER_VALIDATE_EMAIL) || strlen($actor) > 255 || $actor !== trim($actor)
            || preg_match('/[\x00-\x20\x7f]/', $actor)) throw new HttpException(422, 'An explicit valid operator email is required.');
        return $this->operate($id, function ($db, PDO $pdo) use ($id, $actor): array {
            $run = $this->run($id);
            $context = $this->context($run, true);
            $identity = $this->publisher->identity();
            $policy = $this->publisher->policy($context['options']['send_date']);
            $this->outside($db, $pdo);
            if ($identity === '' || strlen($identity) > 255 || preg_match('/[^\x21-\x7e]/', $identity)
                || $policy === [] || strlen($this->json($policy)) > 65536) throw new RuntimeException('Invalid explicit SMS publication provider policy.');
            $plan = ['version' => 1, 'provider_identity' => $identity, 'policy' => $policy,
                'send_date' => $context['options']['send_date'], 'rows' => (int) $run->Selected, 'parts' => $context['manifest']['parts']];
            $row = $this->publication($id);
            if ($row === null) {
                SmsDeliveryPolicy::assertNotPast($plan['send_date']);
                $json = $this->json($plan);
                $this->transaction($db, $pdo, function () use ($db, $id, $actor, $run, $plan, $json): void {
                    if ($this->fingerprint($this->run($id, true)) !== $this->fingerprint($run)) throw new RuntimeException('The approved SMS run changed before publication preparation committed.');
                    if ($this->publication($id, true) !== null) throw new RuntimeException('An SMS publication was prepared concurrently.');
                    $db->table(self::TABLE)->insert(['Request_ID' => $id, 'Run_Sha256' => $this->fingerprint($run),
                        'Plan_JSON' => $json, 'Plan_Sha256' => hash('sha256', $json), 'State' => 'ready', 'Created_By' => $actor, 'Created_At' => $this->now()]);
                    foreach ($plan['parts'] as $part) $db->table(self::PARTS)->insert(['Request_ID' => $id, 'Part_Number' => $part['part'], 'State' => 'ready']);
                });
                $row = $this->publication($id);
            }
            $saved = $this->plan($row, $run, $context);
            if ($saved !== $plan) throw new RuntimeException('The frozen SMS publication provider, policy or approved parts changed; nothing was replaced.');
            $parts = $this->parts($id, $saved);
            $this->project($row, $saved, $parts);
            if ($row->State === 'published') {
                try {
                    foreach ($parts as $index => $part) {
                        $receipt = $this->receipt($part);
                        $this->outside($db, $pdo);
                        $this->publisher->verify($id, $plan['parts'][$index], $receipt, $plan['policy']);
                        $this->outside($db, $pdo);
                    }
                    $this->unchanged($row, $run);
                } catch (Throwable $error) {
                    $this->hold($db, $pdo, $row, $run);
                    throw new RuntimeException('The saved SMS external publication could not be reverified. Its links are held until the same publication is explicitly reconciled.', 0, $error);
                }
                return $this->project($row, $plan, $parts);
            }
            SmsDeliveryPolicy::assertNotPast($plan['send_date']);
            $this->transaction($db, $pdo, function () use ($db, $row, $run, $plan): void {
                $this->unchanged($row, $run, true);
                SmsDeliveryPolicy::assertNotPast($plan['send_date']);
                if ($db->table(self::TABLE)->where('Request_ID', $row->Request_ID)->where('Plan_Sha256', $row->Plan_Sha256)
                    ->whereIn('State', ['ready', 'publishing', 'failed', 'uncertain'])->update(['State' => 'publishing', 'Error_Code' => null, 'Completed_At' => null]) !== 1) {
                    throw new RuntimeException('The SMS publication could not begin.');
                }
            });
            try {
                $this->reader->eachVerified($context['directory'], $context['manifest'], $context['options'], (int) $run->Selected,
                    function (array $part, mixed $stream) use ($db, $pdo, $id, $row, $run, $plan): void {
                        $current = $this->part($id, $part['part']);
                        $this->assertPart($current);
                        if ($current->State === 'complete') {
                            $receipt = $this->receipt($current);
                            $this->outside($db, $pdo);
                            $this->publisher->verify($id, $part, $receipt, $plan['policy']);
                            $this->outside($db, $pdo);
                            return;
                        }
                        $this->transaction($db, $pdo, function () use ($db, $id, $row, $run, $part, $plan): void {
                            $this->unchanged($row, $run, true);
                            SmsDeliveryPolicy::assertNotPast($plan['send_date']);
                            $before = $this->part($id, $part['part'], true);
                            $this->assertPart($before);
                            if (! in_array($before->State, ['ready', 'attempting'], true)) throw new RuntimeException('The SMS publication part changed before upload.');
                            if ($db->table(self::PARTS)->where('Request_ID', $id)->where('Part_Number', $part['part'])
                                ->whereIn('State', ['ready', 'attempting'])->update(['State' => 'attempting',
                                    'Attempted_At' => $before->Attempted_At ?? $this->now(), 'Error_Code' => null]) !== 1) {
                                throw new RuntimeException('The SMS publication attempt was not committed.');
                            }
                        });
                        $this->outside($db, $pdo);
                        // Re-entering this provider operation reconciles the same deterministic
                        // create-only path/link. It does not blindly overwrite an uncertain upload.
                        $receipt = $this->publisher->publish($id, $part, $stream, $plan['policy']);
                        $this->outside($db, $pdo);
                        $this->assertReceipt($receipt, $part, $id, $plan['policy']);
                        $this->publisher->verify($id, $part, $receipt, $plan['policy']);
                        $this->outside($db, $pdo);
                        $this->commitPart($db, $pdo, $row, $run, $part['part'], $receipt);
                    });
                $this->transaction($db, $pdo, function () use ($db, $id, $row, $run, $plan): void {
                    $this->unchanged($row, $run, true);
                    $parts = $this->parts($id, $plan, true);
                    if (array_filter($parts, static fn (object $part): bool => $part->State !== 'complete')) throw new RuntimeException('SMS publication is incomplete.');
                    if ($db->table(self::TABLE)->where('Request_ID', $id)->where('Plan_Sha256', $row->Plan_Sha256)
                        ->where('State', 'publishing')->update(['State' => 'published', 'Completed_At' => $this->now(), 'Error_Code' => null]) !== 1) {
                        throw new RuntimeException('The complete SMS publication receipt was not committed.');
                    }
                });
            } catch (Throwable $error) {
                $this->hold($db, $pdo, $row, $run);
                throw new RuntimeException('SMS file publication did not complete. Its original files and tracked remote receipts were retained; retry only the same publication.', 0, $error);
            }
            return $this->status($id) ?? throw new RuntimeException('SMS publication status is unavailable.');
        });
    }

    /** Bounded read only: never provider calls, filesystem reads or recovery writes. */
    public function status(string $id): ?array
    {
        if (! $this->enabled()) return null;
        $this->id($id);
        if (! $this->schemaReady()) return null;
        $row = $this->publication($id);
        if ($row === null) return null;
        $run = $this->run($id);
        $plan = $this->plan($row, $run, $this->context($run, false));
        return $this->project($row, $plan, $this->parts($id, $plan));
    }

    /** Private bridge for explicit email preparation; URLs must not be exposed by public status DTOs. */
    public function links(string $id): array
    {
        if (! $this->enabled()) throw new HttpException(503, 'SMS file publication is not enabled.');
        $this->id($id);
        if (! $this->schemaReady()) throw new HttpException(503, 'Apply the explicit SMS publication migration first.');
        $row = $this->publication($id);
        if ($row === null || $row->State !== 'published') throw new HttpException(409, 'Publish and verify every SMS file before preparing its email.');
        $run = $this->run($id);
        $plan = $this->plan($row, $run, $this->context($run, false));
        $parts = $this->parts($id, $plan);
        $this->project($row, $plan, $parts);
        $links = [];
        foreach ($parts as $index => $part) {
            $receipt = $this->receipt($part);
            $expected = $plan['parts'][$index];
            $this->assertReceipt($receipt, $expected, $id, $plan['policy']);
            $links[] = ['name' => $expected['name'], 'url' => $receipt['url'], 'sha256' => $expected['sha256'], 'bytes' => $expected['bytes'], 'rows' => $expected['rows']];
        }
        return $links;
    }

    protected function commitPart($db, PDO $pdo, object $row, object $run, int $number, array $receipt): void
    {
        $json = $this->json($receipt);
        $this->transaction($db, $pdo, function () use ($db, $row, $run, $number, $json): void {
            $this->unchanged($row, $run, true);
            $part = $this->part((string) $row->Request_ID, $number, true);
            $this->assertPart($part);
            if ($part->State !== 'attempting') throw new RuntimeException('The SMS publication part changed before its receipt committed.');
            if ($db->table(self::PARTS)->where('Request_ID', $row->Request_ID)->where('Part_Number', $number)->where('State', 'attempting')
                ->update(['State' => 'complete', 'Receipt_JSON' => $json, 'Receipt_Sha256' => hash('sha256', $json), 'Completed_At' => $this->now(), 'Error_Code' => null]) !== 1) {
                throw new RuntimeException('The verified SMS publication part receipt was not committed.');
            }
        });
    }

    private function operate(string $id, callable $work): mixed
    {
        $this->id($id);
        if (! $this->enabled()) throw new HttpException(503, 'SMS file publication is not enabled.');
        $db = DB::connection('sqlsrv');
        $this->outside($db);
        if (! $this->schemaReady()) throw new HttpException(503, 'Apply the explicit SMS publication migration first.');
        $pdo = $db->getPdo();
        return SmsWorkflowLock::run('run:'.$id, function () use ($db, $pdo, $work): mixed { $this->outside($db, $pdo); return $work($db, $pdo); });
    }

    private function context(object $run, bool $deep): array
    {
        if (! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approved' || ! $run->Approved_By || ! $run->Approved_At
            || (int) $run->Selected < 1 || ! empty($run->File_Cleanup_Path)) throw new HttpException(409, 'Publish only approved SMS record runs with their final files.');
        $options = SmsRunOptions::fromStored($run->Run_Options_JSON, (int) $run->Target);
        if (($options['options_version'] ?? null) !== SmsRunOptions::PLANNED_DATE_VERSION || ($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) {
            throw new HttpException(409, 'Only explicit approval-time SMS files support external publication.');
        }
        $manifest = $this->decode((string) $run->Artifact_Manifest_JSON);
        $bundle = new SmsRunArtifactBundle('v2');
        $bundle->validateManifest($manifest, strtolower((string) $run->Request_ID), $options, (int) $run->Selected);
        $directory = str_replace('\\', '/', rtrim((string) (config('sms-exports.local_root') ?: storage_path('app/private/sms-exports')), '/\\')).'/runs/'.strtolower((string) $run->Request_ID);
        $path = $directory.'/v2/published/'.$manifest['download']['name'];
        if ($run->File_Path !== $path || (int) $run->File_Bytes !== $manifest['download']['bytes'] || $run->File_Sha256 !== $manifest['download']['sha256']) {
            throw new RuntimeException('The approved SMS file and retained publication receipt differ.');
        }
        if ($deep && $bundle->verify($directory, $manifest, $options, (int) $run->Selected) !== $path) throw new RuntimeException('The verified approved SMS path changed.');
        return compact('directory', 'manifest', 'options');
    }

    private function plan(object $row, object $run, array $context): array
    {
        if (! $this->hash($row->Run_Sha256 ?? null) || ! hash_equals($row->Run_Sha256, $this->fingerprint($run))
            || ! $this->hash($row->Plan_Sha256 ?? null) || ! is_string($row->Plan_JSON ?? null)
            || ! hash_equals($row->Plan_Sha256, hash('sha256', $row->Plan_JSON))) throw new RuntimeException('The frozen SMS publication plan failed its integrity check.');
        $plan = $this->decode($row->Plan_JSON);
        if (array_keys($plan) !== ['version', 'provider_identity', 'policy', 'send_date', 'rows', 'parts'] || $plan['version'] !== 1
            || ! is_string($plan['provider_identity']) || $plan['provider_identity'] === '' || strlen($plan['provider_identity']) > 255
            || ! is_array($plan['policy']) || $plan['policy'] === [] || strlen($this->json($plan['policy'])) > 65536
            || $plan['send_date'] !== $context['options']['send_date'] || $plan['rows'] !== (int) $run->Selected
            || $plan['parts'] !== $context['manifest']['parts'] || $this->json($plan) !== $row->Plan_JSON) throw new RuntimeException('The frozen SMS publication plan differs from its approved run.');
        return $plan;
    }

    private function parts(string $id, array $plan, bool $locked = false): array
    {
        $query = DB::connection('sqlsrv')->table(self::PARTS)->useWritePdo()->where('Request_ID', $id)->orderBy('Part_Number')->limit(SmsRunArtifactBundle::MAX_PARTS + 1);
        if ($locked) $query->lockForUpdate();
        $parts = $query->get()->all();
        if (count($parts) !== count($plan['parts'])) throw new RuntimeException('The tracked SMS publication part count changed.');
        foreach ($parts as $index => $part) {
            if ((int) $part->Part_Number !== $index + 1) throw new RuntimeException('The tracked SMS publication part order changed.');
            $this->assertPart($part);
            if ($part->State === 'complete') $this->assertReceipt($this->receipt($part), $plan['parts'][$index], $id, $plan['policy']);
        }
        return $parts;
    }

    private function receipt(object $part): array
    {
        if ($part->State !== 'complete' || ! is_string($part->Receipt_JSON ?? null) || ! $this->hash($part->Receipt_Sha256 ?? null)
            || ! hash_equals($part->Receipt_Sha256, hash('sha256', $part->Receipt_JSON))) throw new RuntimeException('The tracked SMS publication part receipt failed its integrity check.');
        $receipt = $this->decode($part->Receipt_JSON);
        if ($this->json($receipt) !== $part->Receipt_JSON) throw new RuntimeException('The tracked SMS publication receipt is noncanonical.');
        return $receipt;
    }

    private function assertReceipt(array $receipt, array $part, string $id, array $policy): void
    {
        if (array_keys($receipt) !== ['version', 'request_id', 'part', 'name', 'url', 'sha256', 'bytes', 'rows', 'remote', 'policy']
            || $receipt['version'] !== 1 || $receipt['request_id'] !== $id || $receipt['part'] !== $part['part']
            || $receipt['name'] !== $part['name'] || $receipt['sha256'] !== $part['sha256'] || $receipt['bytes'] !== $part['bytes']
            || $receipt['rows'] !== $part['rows'] || ! is_array($receipt['remote']) || $receipt['remote'] === []
            || $receipt['policy'] !== $policy) throw new RuntimeException('The verified SMS publication receipt differs from its frozen run, part or policy.');
        $url = $receipt['url'] ?? null;
        $parsed = is_string($url) ? parse_url($url) : false;
        if (! is_string($url) || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || ! is_array($parsed) || ($parsed['scheme'] ?? null) !== 'https' || ! is_string($parsed['host'] ?? null)
            || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])
            || (isset($parsed['port']) && $parsed['port'] !== 443)) throw new RuntimeException('Invalid verified SMS publication link receipt.');
    }

    private function assertPart(?object $part): void
    {
        if ($part === null) throw new RuntimeException('Missing SMS publication part evidence.');
        $attempt = $this->stamp($part->Attempted_At ?? null);
        $complete = $this->stamp($part->Completed_At ?? null);
        $valid = match ($part->State) {
            'ready' => $part->Attempted_At === null && $part->Completed_At === null && $part->Receipt_JSON === null && $part->Receipt_Sha256 === null && $part->Error_Code === null,
            'attempting' => $attempt !== null && $part->Completed_At === null && $part->Receipt_JSON === null && $part->Receipt_Sha256 === null
                && in_array($part->Error_Code, [null, 'publication_exception'], true),
            'complete' => $attempt !== null && $complete !== null && $complete >= $attempt && is_string($part->Receipt_JSON)
                && $this->hash($part->Receipt_Sha256) && $part->Error_Code === null,
            default => false,
        };
        if (! $valid) throw new RuntimeException('The SMS publication part state and evidence are inconsistent.');
    }

    private function project(object $row, array $plan, array $parts): array
    {
        $done = 0; $rows = 0;
        foreach ($parts as $index => $part) if ($part->State === 'complete') { $done++; $rows += $plan['parts'][$index]['rows']; }
        $complete = $this->stamp($row->Completed_At ?? null);
        $valid = match ($row->State) {
            'ready' => $row->Completed_At === null && $row->Error_Code === null && $done === 0
                && ! array_filter($parts, static fn (object $part): bool => $part->State !== 'ready'),
            'publishing' => $row->Completed_At === null && $row->Error_Code === null,
            'published' => $complete !== null && $row->Error_Code === null && $done === count($plan['parts']),
            'failed', 'uncertain' => $row->Completed_At === null && $row->Error_Code === 'publication_exception',
            default => false,
        };
        if (! $valid) throw new RuntimeException('The SMS publication state and evidence are inconsistent.');
        return ['status' => $row->State, 'files_done' => $done, 'files_total' => count($plan['parts']), 'rows_done' => $rows, 'rows_total' => $plan['rows'],
            'note' => match ($row->State) {
                'ready' => 'Approved files are prepared for explicit external publication. No email was sent.',
                'publishing' => 'SMS file publication is in progress; completed file receipts are retained.',
                'published' => 'All approved SMS files have verified external links. Email preparation and sending are separate.',
                default => 'SMS file publication needs an explicit retry of the same frozen plan. Completed files were retained; no email was sent.',
            }];
    }

    private function unchanged(object $row, object $run, bool $locked = false): void
    {
        $current = $this->publication((string) $row->Request_ID, $locked);
        if ($current === null || $this->fingerprint($this->run((string) $row->Request_ID, $locked)) !== $this->fingerprint($run)) throw new RuntimeException('The approved SMS publication context changed.');
        foreach (['Request_ID', 'Run_Sha256', 'Plan_JSON', 'Plan_Sha256', 'Created_By', 'Created_At'] as $field) {
            if ($current->$field !== $row->$field) throw new RuntimeException('The frozen SMS publication plan changed.');
        }
    }

    private function hold($db, PDO $pdo, object $row, object $run): void
    {
        try {
            $this->transaction($db, $pdo, function () use ($db, $row, $run): void {
                $this->unchanged($row, $run, true);
                $attempts = $db->table(self::PARTS)->where('Request_ID', $row->Request_ID)->where('State', 'attempting')->exists();
                $db->table(self::PARTS)->where('Request_ID', $row->Request_ID)->where('State', 'attempting')->update(['Error_Code' => 'publication_exception']);
                $db->table(self::TABLE)->where('Request_ID', $row->Request_ID)->whereIn('State', ['publishing', 'published'])
                    ->update(['State' => $attempts || $row->State === 'published' ? 'uncertain' : 'failed',
                        'Completed_At' => null, 'Error_Code' => 'publication_exception']);
            });
        } catch (Throwable) {
            // A lost session/receipt leaves publishing/attempting; explicit retry reconciles the same remote keys.
        }
    }

    private function publication(string $id, bool $locked = false): ?object
    {
        $query = DB::connection('sqlsrv')->table(self::TABLE)->useWritePdo()->where('Request_ID', $id);
        if ($locked) $query->lockForUpdate();
        return $query->first();
    }
    private function part(string $id, int $number, bool $locked = false): ?object
    {
        $query = DB::connection('sqlsrv')->table(self::PARTS)->useWritePdo()->where('Request_ID', $id)->where('Part_Number', $number);
        if ($locked) $query->lockForUpdate();
        return $query->first();
    }
    private function run(string $id, bool $locked = false): object
    {
        $query = DB::connection('sqlsrv')->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $id);
        if ($locked) $query->lockForUpdate();
        return $query->first() ?? throw new HttpException(404, 'SMS run not found.');
    }
    private function fingerprint(object $run): string
    {
        $values = [];
        foreach (['Request_ID', 'Status', 'Selection_Policy', 'Target', 'Selected', 'Approved_By', 'Approved_At', 'Sent_By', 'Sent_At',
            'Run_Options_JSON', 'Artifact_Manifest_JSON', 'File_Path', 'File_Bytes', 'File_Sha256', 'File_Cleanup_Path'] as $field) $values[$field] = $run->$field ?? null;
        return hash('sha256', $this->json($values));
    }
    private function schemaReady(): bool
    {
        $db = DB::connection('sqlsrv'); $schema = $db->getSchemaBuilder();
        return $db->getTablePrefix() === '' && in_array($db->getDriverName(), ['sqlite', 'sqlsrv'], true)
            && $schema->hasTable(self::TABLE) && $schema->hasColumns(self::TABLE, ['Request_ID', 'Run_Sha256', 'Plan_JSON', 'Plan_Sha256', 'State', 'Created_By', 'Created_At', 'Completed_At', 'Error_Code'])
            && $schema->hasTable(self::PARTS) && $schema->hasColumns(self::PARTS, ['Request_ID', 'Part_Number', 'State', 'Attempted_At', 'Completed_At', 'Receipt_JSON', 'Receipt_Sha256', 'Error_Code']);
    }
    private function transaction($db, PDO $pdo, callable $work): mixed
    {
        $this->outside($db, $pdo);
        return $db->transaction(function () use ($db, $pdo, $work): mixed {
            if ($db->getPdo() !== $pdo) throw new RuntimeException('The SMS publication SQL session changed before commit.');
            $result = $work();
            if ($db->getPdo() !== $pdo) throw new RuntimeException('The SMS publication SQL session changed during commit.');
            return $result;
        });
    }
    private function outside($db, ?PDO $pdo = null): void
    {
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction() || ($pdo !== null && $db->getPdo() !== $pdo)) throw new RuntimeException('SMS publication I/O requires the pinned SQL session outside every transaction.');
    }
    private function stamp(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,7}))?$/D', $value, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) return null;
        return substr($value, 0, 19).'.'.str_pad($m[7] ?? '', 7, '0');
    }
    private function enabled(): bool { return config('sms-exports.file_publication_enabled', false) === true; }
    private function id(string $id): void { if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)) throw new HttpException(422, 'Use a lower-case SMS request UUID.'); }
    private function hash(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    private function decode(string $json): array
    {
        try { $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RuntimeException('Invalid retained SMS publication JSON.', 0, $error); }
        if (! is_array($value)) throw new RuntimeException('Invalid retained SMS publication JSON.');
        return $value;
    }
    private function now(): string { return Carbon::now('UTC')->format('Y-m-d H:i:s'); }
}
