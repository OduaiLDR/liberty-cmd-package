<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Explicit prepared-email bridge; never publishes links or automatically sends approved history. */
class SmsRunEmailWorkflow
{
    public const TABLE = 'TblSMSEmailOutbox';
    private const COLUMNS = ['Request_ID', 'Run_Sha256', 'Payload_JSON', 'Payload_Sha256', 'State', 'Created_By',
        'Created_At', 'Attempted_At', 'Accepted_At', 'Error_Code'];

    public function __construct(private readonly SmsRunEmailGateway $gateway) {}

    /** Links are operator-approved receipts, not evidence that their remote bytes were verified. */
    public function prepare(string $id, array $links, string $actor): array
    {
        if ($actor === '' || strlen($actor) > 255 || ! filter_var($actor, FILTER_VALIDATE_EMAIL)
            || $actor !== trim($actor) || preg_match('/[\x00-\x20\x7f]/', $actor)) {
            throw new HttpException(422, 'Use the authenticated actor for SMS email preparation.');
        }
        return $this->operate($id, function ($db, PDO $pdo) use ($id, $links, $actor): array {
            $run = $this->run($id);
            $context = $this->context($run);
            $this->assertTrackedPublication($db, $id, $links);
            $existing = $this->outbox($id);
            if ($existing !== null) {
                $payload = $this->payload($existing);
                $this->matchesRun($existing, $run);
                if ($payload['links'] !== $links) throw new HttpException(409, 'The prepared SMS download-link receipts differ; nothing was replaced.');
                $this->rebuild($payload, $context);
                $this->outside($db, $pdo);
                return $this->project($existing);
            }
            // Dynamic configuration is looked up once, outside the SQL transaction.
            $configuration = $this->gateway->configuration();
            $this->outside($db, $pdo);
            $payload = ['version' => 1, 'configuration' => $configuration, 'links' => $links,
                'message' => SmsRunEmailMessage::build($configuration, $context['manifest'], $context['options'], (int) $run->Selected, $links, $this->hosts())];
            $json = $this->json($payload);
            $fingerprint = $this->fingerprint($run);
            $this->transaction($db, $pdo, function () use ($db, $id, $actor, $json, $fingerprint, $links): void {
                $current = $this->run($id, true);
                if ($this->fingerprint($current) !== $fingerprint) throw new HttpException(409, 'The approved SMS receipt changed before email preparation committed.');
                if ($this->outbox($id, true) !== null) throw new HttpException(409, 'An SMS email was prepared concurrently. Refresh before continuing.');
                $this->assertTrackedPublication($db, $id, $links);
                $db->table(self::TABLE)->insert(['Request_ID' => $id, 'Run_Sha256' => $fingerprint,
                    'Payload_JSON' => $json, 'Payload_Sha256' => hash('sha256', $json), 'State' => 'ready',
                    'Created_By' => $actor, 'Created_At' => $this->now()]);
            });
            return $this->project($this->outbox($id));
        });
    }

    /** One explicit attempt only. An ambiguous acceptance must be reconciled, never blindly resent. */
    public function send(string $id, string $expectedPayloadSha256): array
    {
        if (! $this->hash($expectedPayloadSha256)) throw new HttpException(422, 'Confirm the exact prepared SMS email fingerprint.');
        return $this->operate($id, function ($db, PDO $pdo) use ($id, $expectedPayloadSha256): array {
            $row = $this->outbox($id);
            if ($row === null) throw new HttpException(409, 'Prepare and review the SMS email before sending it.');
            if (! hash_equals((string) $row->Payload_Sha256, $expectedPayloadSha256)) throw new HttpException(409, 'The prepared SMS email changed. Refresh and review it again.');
            $payload = $this->payload($row);
            if ($row->State === 'attempting') {
                // The previous process may have died after Graph accepted the email.
                $this->finish($db, $pdo, $row, 'needs_review', 'interrupted_attempt');
                return $this->project($this->outbox($id));
            }
            if (in_array($row->State, ['accepted', 'needs_review'], true)) return $this->project($row);
            if ($row->State !== 'ready') throw new RuntimeException('Invalid retained SMS email state.');

            $run = $this->run($id);
            $context = $this->context($run);
            $this->matchesRun($row, $run);
            $this->rebuild($payload, $context);
            $this->assertTrackedPublication($db, $id, $payload['links']);
            SmsDeliveryPolicy::assertNotPast($context['options']['send_date']);
            $this->outside($db, $pdo);
            $this->gateway->assertReady();
            $this->outside($db, $pdo);
            // All local prerequisites pass before the durable non-retryable boundary.
            $this->transaction($db, $pdo, function () use ($db, $id, $row, $run, $context, $payload): void {
                if ($this->fingerprint($this->run($id, true)) !== $this->fingerprint($run)) throw new HttpException(409, 'The approved SMS receipt changed before the email attempt.');
                $current = $this->outbox($id, true);
                $this->sameRow($current, $row, 'ready');
                $this->assertTrackedPublication($db, $id, $payload['links']);
                // Hashing/readiness may cross California midnight; validate again at acceptance.
                SmsDeliveryPolicy::assertNotPast($context['options']['send_date']);
                if ($db->table(self::TABLE)->where('Request_ID', $id)->where('State', 'ready')
                    ->where('Payload_Sha256', $row->Payload_Sha256)->update(['State' => 'attempting', 'Attempted_At' => $this->now(), 'Error_Code' => null]) !== 1) {
                    throw new RuntimeException('The SMS email attempt was not committed.');
                }
            });
            $this->outside($db, $pdo);
            $accepted = false;
            $errorCode = 'provider_not_accepted';
            try {
                $accepted = $this->gateway->send($payload['message']);
            } catch (Throwable $error) {
                // Never persist a provider exception: it may contain recipient data or tokens.
                $errorCode = 'provider_exception';
            }
            // If this commit fails, durable attempting remains. A subsequent explicit
            // send only changes it to needs_review and cannot call the provider again.
            $this->finish($db, $pdo, $row, $accepted ? 'accepted' : 'needs_review', $accepted ? null : $errorCode);
            return $this->project($this->outbox($id));
        });
    }

    /** Safe bounded read only; no configuration-table lookups, artifact I/O, provider calls or writes. */
    public function status(string $id): ?array
    {
        if (! $this->enabled()) return null;
        $this->id($id);
        if (! $this->schemaReady()) return null;
        $row = DB::connection('sqlsrv')->table(self::TABLE)->useWritePdo()->where('Request_ID', $id)
            ->first(['Request_ID', 'State', 'Payload_Sha256', 'Created_At', 'Attempted_At', 'Accepted_At', 'Error_Code']);
        return $row === null ? null : $this->project($row);
    }

    /** Authorized callers only: returns the frozen message, including its private links. */
    public function preparedMessage(string $id): ?array
    {
        if (! $this->enabled()) return null;
        $this->id($id);
        if (! $this->schemaReady()) return null;
        $row = $this->outbox($id);
        if ($row === null) return null;
        $payload = $this->payload($row);
        $run = $this->run($id);
        $this->matchesRun($row, $run);
        $this->rebuild($payload, $this->context($run, false));
        return $payload['message'];
    }

    private function operate(string $id, callable $work): mixed
    {
        $this->id($id);
        if (! $this->enabled()) throw new HttpException(503, 'SMS email handoff is not enabled.');
        $db = DB::connection('sqlsrv');
        $this->outside($db);
        if (! $this->schemaReady()) throw new HttpException(503, 'Apply the explicit SMS email-outbox migration before enabling handoff.');
        $pdo = $db->getPdo();
        return SmsWorkflowLock::run('run:'.$id, function () use ($db, $pdo, $work): mixed {
            $this->outside($db, $pdo);
            return $work($db, $pdo);
        });
    }

    private function context(object $run, bool $deep = true): array
    {
        if (! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approved' || ! $run->Approved_By
            || ! $run->Approved_At || (int) $run->Selected < 1 || ! empty($run->File_Cleanup_Path)) {
            throw new HttpException(409, 'Email handoff requires an approved SMS record batch with its final files.');
        }
        $options = SmsRunOptions::fromStored($run->Run_Options_JSON, (int) $run->Target);
        if (($options['options_version'] ?? null) !== SmsRunOptions::PLANNED_DATE_VERSION
            || ($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) {
            throw new HttpException(409, 'Only explicit approval-time SMS files support this email handoff.');
        }
        $manifest = $this->decode((string) $run->Artifact_Manifest_JSON);
        $root = str_replace('\\', '/', rtrim((string) (config('sms-exports.local_root') ?: storage_path('app/private/sms-exports')), '/\\'));
        $directory = $root.'/runs/'.strtolower((string) $run->Request_ID);
        $bundle = new SmsRunArtifactBundle('v2');
        $bundle->validateManifest($manifest, strtolower((string) $run->Request_ID), $options, (int) $run->Selected);
        $path = $directory.'/v2/published/'.$manifest['download']['name'];
        if ($deep) $path = $bundle->verify($directory, $manifest, $options, (int) $run->Selected);
        if ($run->File_Path !== $path || (int) $run->File_Bytes !== $manifest['download']['bytes']
            || $run->File_Sha256 !== $manifest['download']['sha256']) {
            throw new RuntimeException('The approved SMS file and retained publication receipt differ.');
        }
        return compact('options', 'manifest');
    }

    private function rebuild(array $payload, array $context): void
    {
        $message = SmsRunEmailMessage::build($payload['configuration'], $context['manifest'], $context['options'],
            $context['manifest']['rows'], $payload['links'], $this->hosts());
        if ($message !== $payload['message']) throw new RuntimeException('The prepared SMS email differs from its frozen approved context.');
    }

    private function payload(object $row): array
    {
        $this->assertState($row);
        if (! is_string($row->Payload_JSON ?? null) || ! $this->hash($row->Payload_Sha256 ?? null)
            || ! hash_equals($row->Payload_Sha256, hash('sha256', $row->Payload_JSON))) {
            throw new RuntimeException('The retained SMS email payload failed its integrity check.');
        }
        $payload = $this->decode($row->Payload_JSON);
        if (array_keys($payload) !== ['version', 'configuration', 'links', 'message'] || $payload['version'] !== 1
            || ! is_array($payload['configuration']) || ! is_array($payload['links']) || ! array_is_list($payload['links'])
            || ! is_array($payload['message']) || $this->json($payload) !== $row->Payload_JSON) {
            throw new RuntimeException('The retained SMS email payload is incomplete or noncanonical.');
        }
        return $payload;
    }

    private function matchesRun(object $row, object $run): void
    {
        if (! $this->hash($row->Run_Sha256 ?? null) || ! hash_equals($row->Run_Sha256, $this->fingerprint($run))) {
            throw new HttpException(409, 'The approved SMS receipt differs from the prepared email.');
        }
    }

    /** Saved evidence only, under the common run lock. A known held publication must not be emailed. */
    private function assertTrackedPublication($db, string $id, array $links): void
    {
        // Preserve the explicit operator-link bridge for runs with no publication ledger.
        if (! $db->getSchemaBuilder()->hasTable(SmsRunPublicationWorkflow::TABLE)
            || ! $db->table(SmsRunPublicationWorkflow::TABLE)->useWritePdo()->where('Request_ID', $id)->exists()) return;
        if (config('sms-exports.file_publication_enabled', false) !== true) {
            throw new HttpException(409, 'The tracked SMS publication must remain enabled and verified before email preparation or sending.');
        }
        if (app(SmsRunPublicationWorkflow::class)->links($id) !== $links) {
            throw new HttpException(409, 'The prepared SMS email links differ from the tracked approved publication.');
        }
    }

    protected function finish($db, PDO $pdo, object $row, string $state, ?string $code): void
    {
        $this->transaction($db, $pdo, function () use ($db, $row, $state, $code): void {
            $this->sameRow($this->outbox((string) $row->Request_ID, true), $row, 'attempting');
            if ($db->table(self::TABLE)->where('Request_ID', $row->Request_ID)->where('State', 'attempting')
                ->where('Payload_Sha256', $row->Payload_Sha256)->update(['State' => $state, 'Error_Code' => $code,
                    'Accepted_At' => $state === 'accepted' ? $this->now() : null]) !== 1) {
                throw new RuntimeException('The SMS email outcome was not committed; reconcile before another action.');
            }
        });
    }

    private function sameRow(?object $current, object $expected, string $state): void
    {
        if ($current === null || $current->State !== $state) throw new HttpException(409, 'The SMS email state changed. Refresh before continuing.');
        $this->assertState($current);
        foreach (['Request_ID', 'Run_Sha256', 'Payload_JSON', 'Payload_Sha256', 'Created_By', 'Created_At'] as $key) {
            if ($current->$key !== $expected->$key) throw new RuntimeException('The immutable SMS email receipt changed.');
        }
    }

    private function project(object $row): array
    {
        $this->assertState($row);
        $status = match ($row->State) {
            'ready' => 'ready', 'attempting' => 'sending', 'accepted' => 'accepted', 'needs_review' => 'uncertain',
            default => throw new RuntimeException('Invalid retained SMS email state.'),
        };
        if (! $this->hash($row->Payload_Sha256 ?? null)) throw new RuntimeException('Invalid retained SMS email fingerprint.');
        return ['status' => $status, 'payload_sha256' => $row->Payload_Sha256, 'note' => match ($status) {
            'ready' => 'Prepared for review. No email has been attempted.',
            'sending' => 'An email attempt started. Do not resend without confirming its outcome.',
            'accepted' => 'The email provider accepted this message for sending. This is not delivery confirmation.',
            'uncertain' => 'Email outcome needs manual confirmation. Automatic resend is blocked.',
        }];
    }

    /** A state label cannot erase evidence that a remote attempt already started. */
    private function assertState(object $row): void
    {
        $attempt = $row->Attempted_At ?? null;
        $accepted = $row->Accepted_At ?? null;
        $error = $row->Error_Code ?? null;
        $valid = match ($row->State ?? null) {
            'ready' => $attempt === null && $accepted === null && $error === null,
            'attempting' => $this->stamp($attempt) !== null && $accepted === null && $error === null,
            'accepted' => $this->stamp($attempt) !== null && $this->stamp($accepted) !== null && $error === null
                && $this->stamp($accepted) >= $this->stamp($attempt),
            'needs_review' => $this->stamp($attempt) !== null && $accepted === null
                && in_array($error, ['interrupted_attempt', 'provider_not_accepted', 'provider_exception'], true),
            default => false,
        };
        if (! $valid) throw new RuntimeException('The retained SMS email state and attempt evidence are inconsistent; reconcile before continuing.');
    }

    /** Comparable UTC SQL timestamp, including SQL Server's optional fractional precision. */
    private function stamp(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,7}))?$/D', $value, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) return null;
        return substr($value, 0, 19).'.'.str_pad($m[7] ?? '', 7, '0');
    }

    private function run(string $id, bool $locked = false): object
    {
        $query = DB::connection('sqlsrv')->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $id);
        if ($locked) $query->lockForUpdate();
        return $query->first() ?? throw new HttpException(404, 'SMS run not found.');
    }

    private function outbox(string $id, bool $locked = false): ?object
    {
        $query = DB::connection('sqlsrv')->table(self::TABLE)->useWritePdo()->where('Request_ID', $id);
        if ($locked) $query->lockForUpdate();
        return $query->first();
    }

    private function fingerprint(object $run): string
    {
        $fields = ['Request_ID', 'Status', 'Selection_Policy', 'Target', 'Selected', 'Approved_By', 'Approved_At',
            'Sent_By', 'Sent_At', 'Run_Options_JSON', 'Artifact_Manifest_JSON', 'File_Path', 'File_Bytes', 'File_Sha256', 'File_Cleanup_Path'];
        $values = [];
        foreach ($fields as $field) $values[$field] = $run->$field ?? null;
        return hash('sha256', $this->json($values));
    }

    private function schemaReady(): bool
    {
        $db = DB::connection('sqlsrv');
        return $db->getTablePrefix() === '' && in_array($db->getDriverName(), ['sqlite', 'sqlsrv'], true)
            && $db->getSchemaBuilder()->hasTable(self::TABLE) && $db->getSchemaBuilder()->hasColumns(self::TABLE, self::COLUMNS);
    }

    private function transaction($db, PDO $pdo, callable $work): mixed
    {
        $this->outside($db, $pdo);
        return $db->transaction(function () use ($db, $pdo, $work): mixed {
            // Laravel may reconnect during beginTransaction; never continue on a session without our applock.
            if ($db->getPdo() !== $pdo) throw new RuntimeException('The SMS email SQL session changed before commit.');
            $value = $work();
            if ($db->getPdo() !== $pdo) throw new RuntimeException('The SMS email SQL session changed during commit.');
            return $value;
        });
    }

    private function outside($db, ?PDO $pdo = null): void
    {
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction() || ($pdo !== null && $db->getPdo() !== $pdo)) {
            throw new RuntimeException('SMS email I/O requires the pinned SQL session outside every transaction.');
        }
    }

    private function hosts(): array
    {
        $hosts = config('sms-exports.email_allowed_link_hosts', []);
        if (! is_array($hosts) || ! array_is_list($hosts) || $hosts === []) throw new HttpException(503, 'Configure approved SMS download-link hosts first.');
        return $hosts;
    }

    private function enabled(): bool { return config('sms-exports.email_handoff_enabled', false) === true; }
    private function id(string $id): void
    {
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)) throw new HttpException(422, 'Use a lower-case SMS request UUID.');
    }
    private function hash(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    private function decode(string $json): array
    {
        try { $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RuntimeException('Invalid retained SMS email JSON.', 0, $error); }
        if (! is_array($value)) throw new RuntimeException('Invalid retained SMS email JSON.');
        return $value;
    }
    private function now(): string { return Carbon::now('UTC')->format('Y-m-d H:i:s'); }
}
