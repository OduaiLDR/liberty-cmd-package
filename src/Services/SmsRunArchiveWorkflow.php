<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Explicit operator operations. No automatic archive timing, deletion or business-state changes. */
final class SmsRunArchiveWorkflow
{
    public function __construct(private readonly SmsRunArchiveStore $store) {}

    public function archive(string $requestId): array
    {
        return $this->operate($requestId, function ($db, $pdo, object $run, array $context): array {
            $archive = new SmsRunArtifactArchive($this->store);
            if ($run->Archive_Receipt_JSON !== null) {
                $receipt = $this->receipt($run, $context);
                $this->verifyObjects($archive, $receipt, $context['manifest']);
                $this->unchanged($db, $pdo, $run, $context);
                return $receipt;
            }
            $bundle = new SmsRunArtifactBundle($context['generation']);
            $path = $bundle->verify($context['directory'], $context['manifest'], $context['options'], (int) $run->Selected);
            $this->outsideTransaction($db, $pdo);
            $base = $context['prefix'].'/'.strtolower($run->Request_ID).'/'.$context['generation'].'/';
            $manifest = $archive->archiveManifest(strtolower($run->Request_ID), dirname($path).'/manifest.json', $context['manifest'], $base.'manifest.json');
            $this->outsideTransaction($db, $pdo);
            $primary = $archive->archive(strtolower($run->Request_ID), $path, $context['manifest'], $base.$context['manifest']['download']['name']);
            $receipt = ['version' => 1, 'request_id' => strtolower($run->Request_ID), 'store_id' => $context['store_id'],
                'policy_sha256' => $context['policy_sha256'], 'prefix' => $context['prefix'],
                'run_sha256' => $this->fingerprint($run), 'manifest' => $manifest, 'primary' => $primary];
            $this->unchanged($db, $pdo, $run, $context);
            // Provider I/O is complete before this short receipt-only transaction.
            return $db->transaction(function () use ($db, $pdo, $run, $receipt): array {
                if ($db->getPdo() !== $pdo) throw new RuntimeException('The SMS archive SQL session changed.');
                $current = $db->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $run->Request_ID)->lockForUpdate()->first();
                if (! $current || $this->fingerprint($current) !== $this->fingerprint($run)) {
                    throw new RuntimeException('The SMS run changed before its archive receipt committed.');
                }
                if ($current->Archive_Receipt_JSON !== null) {
                    if ($this->decode($current->Archive_Receipt_JSON) !== $receipt) throw new RuntimeException('Conflicting SMS archive receipt; nothing was replaced.');
                    return $receipt;
                }
                if ($db->table('TblSMSRuns')->where('Request_ID', $run->Request_ID)->whereNull('Archive_Receipt_JSON')
                    ->update(['Archive_Receipt_JSON' => json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]) !== 1) {
                    throw new RuntimeException('The SMS archive receipt was not committed.');
                }
                return $receipt;
            });
        });
    }

    public function verify(string $requestId): array
    {
        return $this->operate($requestId, function ($db, $pdo, object $run, array $context): array {
            $receipt = $this->receipt($run, $context);
            $this->verifyObjects(new SmsRunArtifactArchive($this->store), $receipt, $context['manifest']);
            $this->unchanged($db, $pdo, $run, $context);
            return $receipt;
        });
    }

    public function restore(string $requestId): string
    {
        return $this->operate($requestId, function ($db, $pdo, object $run, array $context): string {
            $receipt = $this->receipt($run, $context);
            $archive = new SmsRunArtifactArchive($this->store);
            $this->verifyObjects($archive, $receipt, $context['manifest']);
            $this->unchanged($db, $pdo, $run, $context);
            $bundle = new SmsRunArtifactBundle($context['generation']);
            $directory = $bundle->prepareArchiveRestore($context['directory'], $context['manifest']);
            $scratch = $bundle->archiveRestoreScratch($context['directory']);
            $archive->restoreManifest($receipt['manifest'], $context['manifest'], $directory.'/manifest.json', $scratch);
            $this->outsideTransaction($db, $pdo);
            $archive->restore($receipt['primary'], $context['manifest'], $directory.'/'.$context['manifest']['download']['name'], $scratch);
            $this->unchanged($db, $pdo, $run, $context);
            return $bundle->verify($context['directory'], $context['manifest'], $context['options'], (int) $run->Selected);
        });
    }

    private function operate(string $id, callable $work): mixed
    {
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)) throw new HttpException(422, 'Use a lower-case SMS request UUID.');
        $db = DB::connection('sqlsrv');
        $this->outsideTransaction($db);
        $this->settings();
        if (! $db->getSchemaBuilder()->hasColumns('TblSMSRuns', ['Run_Options_JSON', 'Artifact_Manifest_JSON', 'Archive_Receipt_JSON'])) {
            throw new HttpException(503, 'Apply the explicit SMS archive-receipt migration first.');
        }
        return SmsWorkflowLock::run('run:'.$id, function () use ($db, $id, $work): mixed {
            $pdo = $db->getPdo();
            $run = $db->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $id)->first();
            if (! $run) throw new HttpException(404, 'SMS run not found.');
            $context = $this->context($run);
            $this->outsideTransaction($db, $pdo);
            return $work($db, $pdo, $run, $context);
        });
    }

    private function settings(): array
    {
        $policy = config('sms-exports.run_archive_policy_sha256');
        $prefix = config('sms-exports.run_archive_prefix');
        if (! config('sms-exports.run_archive_enabled', false) || ! is_string($policy)
            || ! preg_match('/^[a-f0-9]{64}$/D', $policy) || ! is_string($prefix)
            || strlen($prefix) > 250 || ! preg_match('~^[A-Za-z0-9][A-Za-z0-9_/-]*$~D', $prefix)
            || str_contains($prefix, '//') || str_ends_with($prefix, '/')) {
            throw new HttpException(503, 'SMS archival requires an explicitly enabled provider, approved policy hash and private prefix.');
        }
        $identity = $this->store->identity();
        if ($identity === '' || strlen($identity) > 255 || preg_match('/[^\x21-\x7e]/', $identity)) throw new RuntimeException('Invalid SMS archive store identity.');
        return ['prefix' => $prefix, 'policy_sha256' => $policy, 'store_id' => $identity];
    }

    private function context(object $run): array
    {
        if (! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approved' || ! $run->Approved_By || ! $run->Approved_At || (int) $run->Selected < 1) {
            throw new HttpException(409, 'Only approved versioned SMS record batches may be archived or restored.');
        }
        $options = SmsRunOptions::fromStored($run->Run_Options_JSON, (int) $run->Target);
        $manifest = $this->decode($run->Artifact_Manifest_JSON);
        $generation = ($options['filename_version'] ?? null) === SmsRunOptions::APPROVAL_FILENAME_VERSION ? 'v2' : 'v1';
        if ($options === null || ($manifest['request_id'] ?? null) !== strtolower($run->Request_ID)
            || ! is_array($manifest['download'] ?? null) || ! is_string($manifest['download']['name'] ?? null)
            || (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}\.(?:csv|zip)$/D', $manifest['download']['name'])
                && ! SmsRunArtifactBundle::isPlannedDownloadName($manifest['download']['name']))) {
            throw new RuntimeException('The SMS archive requires a frozen versioned manifest.');
        }
        $root = str_replace('\\', '/', rtrim((string) (config('sms-exports.local_root') ?: storage_path('app/private/sms-exports')), '/\\'));
        $directory = $root.'/runs/'.strtolower($run->Request_ID);
        (new SmsRunArtifactBundle($generation))->validateManifest($manifest, strtolower($run->Request_ID), $options, (int) $run->Selected);
        if ($run->File_Path !== $directory.'/'.$generation.'/published/'.$manifest['download']['name']
            || (int) $run->File_Bytes !== ($manifest['download']['bytes'] ?? null)
            || $run->File_Sha256 !== ($manifest['download']['sha256'] ?? null) || $run->File_Cleanup_Path) {
            throw new RuntimeException('The SMS archive run and original artifact receipt differ.');
        }
        return $this->settings() + compact('directory', 'options', 'manifest', 'generation');
    }

    private function receipt(object $run, array $context): array
    {
        $receipt = $this->decode($run->Archive_Receipt_JSON);
        $base = $context['prefix'].'/'.strtolower($run->Request_ID).'/'.$context['generation'].'/';
        if (($receipt['version'] ?? null) !== 1 || ($receipt['request_id'] ?? null) !== strtolower($run->Request_ID)
            || ($receipt['store_id'] ?? null) !== $context['store_id'] || ($receipt['policy_sha256'] ?? null) !== $context['policy_sha256']
            || ($receipt['prefix'] ?? null) !== $context['prefix'] || ($receipt['run_sha256'] ?? null) !== $this->fingerprint($run)
            || ! is_array($receipt['manifest'] ?? null) || ! is_array($receipt['primary'] ?? null)
            || ($receipt['manifest']['key'] ?? null) !== $base.'manifest.json'
            || ($receipt['primary']['key'] ?? null) !== $base.$context['manifest']['download']['name']
            || ($receipt['manifest']['request_id'] ?? null) !== strtolower($run->Request_ID)) {
            throw new RuntimeException('The SMS archive receipt, store or approved policy changed.');
        }
        return $receipt;
    }

    private function verifyObjects(SmsRunArtifactArchive $archive, array $receipt, array $manifest): void
    {
        $archive->verifyManifest($receipt['manifest'], $manifest);
        $this->outsideTransaction(DB::connection('sqlsrv'));
        $archive->verify($receipt['primary'], $manifest);
    }

    private function unchanged($db, \PDO $pdo, object $run, array $context): void
    {
        $this->outsideTransaction($db, $pdo);
        if ($this->settings() !== array_intersect_key($context, array_flip(['prefix', 'policy_sha256', 'store_id']))) {
            throw new RuntimeException('SMS archive configuration changed during the operation.');
        }
        $current = $db->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $run->Request_ID)->first();
        if (! $current || $this->fingerprint($current) !== $this->fingerprint($run)) throw new RuntimeException('The SMS run changed during the archive operation.');
    }

    private function outsideTransaction($db, ?\PDO $pdo = null): void
    {
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction() || ($pdo !== null && $db->getPdo() !== $pdo)) {
            throw new RuntimeException('SMS archive provider I/O requires the original SQL session outside any transaction.');
        }
    }

    private function fingerprint(object $run): string
    {
        $fields = ['Request_ID', 'Status', 'Selection_Policy', 'Target', 'Selected', 'Approved_By', 'Approved_At',
            'Sent_By', 'Sent_At', 'Run_Options_JSON', 'Artifact_Manifest_JSON', 'File_Path', 'File_Bytes', 'File_Sha256', 'File_Cleanup_Path'];
        return hash('sha256', json_encode(array_intersect_key((array) $run, array_flip($fields)), JSON_THROW_ON_ERROR));
    }

    private function decode(?string $json): array
    {
        try { $value = $json === null ? null : json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RuntimeException('Invalid SMS archive receipt JSON.', 0, $error); }
        if (! is_array($value)) throw new RuntimeException('Missing SMS archive receipt JSON.');
        return $value;
    }
}
