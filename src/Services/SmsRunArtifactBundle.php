<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;
use Throwable;
use ZipArchive;

/** Private, versioned local artifacts. This class never connects to a database. */
final class SmsRunArtifactBundle
{
    public const MAX_PARTS = 1000;
    private const ZIP_TIME = 315532800; // Fixed 1980-01-01, representable by ZIP on every host.

    public function __construct(private readonly string $generation = 'v1')
    {
        if (! in_array($generation, ['v1', 'v2'], true)) throw new RuntimeException('Use an explicit supported SMS artifact generation.');
    }

    /** @return array{path:string,bytes:int,sha256:string,manifest:array} */
    public function build(string $runDir, string $stem, iterable $cells, int $expected, array $options): array
    {
        if ($this->generation !== 'v1') throw new RuntimeException('Approved SMS artifacts must copy their verified pending publication.');
        [$root, $id] = $this->context($runDir);
        $parts = $this->options($options, $expected);
        $this->stem($stem);
        $this->matchingStem($stem, $options);
        if ($parts > 1 && (! class_exists(ZipArchive::class) || ! method_exists(ZipArchive::class, 'setMtimeName'))) {
            throw new RuntimeException('PHP ZipArchive with deterministic timestamps is required before building split SMS files.');
        }
        if (file_exists($root.'.delete-pending')) throw new RuntimeException('This SMS artifact is quarantined; reconcile deletion before building.');
        $this->ownedTree($root, $id);
        if (is_dir($root.'/published')) {
            $manifest = $this->json($root.'/published/manifest.json');
            $path = $this->verify($runDir, $manifest, $options, $expected);
            if ($manifest['download']['name'] !== $this->downloadName($stem, $parts)) {
                throw new RuntimeException('The published SMS artifact belongs to a different filename; it was not overwritten.');
            }
            return ['path' => $path, 'bytes' => $manifest['download']['bytes'], 'sha256' => $manifest['download']['sha256'], 'manifest' => $manifest];
        }
        $this->directory($root);
        $stage = $root.'/staging-'.bin2hex(random_bytes(8));
        $this->directory($stage);
        $handle = null;
        try {
            $this->writeJson($stage.'/.build.json', ['version' => 1, 'request_id' => $id, 'stem' => $stem, 'parts' => $parts]);
            $manifestParts = []; $total = 0; $partRows = 0; $part = 0; $name = '';
            foreach ($cells as $row) {
                if (++$total > $expected) throw new RuntimeException('SMS artifact row count exceeds the frozen selection.');
                if (! is_array($row) || ! array_is_list($row) || count($row) !== 4
                    || array_filter($row, static fn ($cell): bool => ! is_string($cell))) {
                    throw new RuntimeException('Each SMS artifact row must contain exactly four string cells.');
                }
                if (isset($options['send_date']) && $row[3] !== $options['send_date']) {
                    throw new RuntimeException('The SMS row date differs from the frozen planned send date.');
                }
                if ($handle === null) {
                    $name = $this->partName($stem, ++$part, $parts);
                    $handle = fopen($stage.'/'.$name, 'xb');
                    if ($handle === false) throw new RuntimeException('Unable to create a private SMS CSV part.');
                    $this->csv($handle, SmsDeliveryPolicy::headerForFormat($options['csv_format']));
                    $partRows = 0;
                }
                $safe = array_map(static fn (string $cell): string => preg_match('/^[=+@\-\t\r\n]/', $cell) ? "'".$cell : $cell, $row);
                $this->csv($handle, $safe);
                if (++$partRows === $options['rows_per_file']) {
                    $this->finish($handle); $handle = null;
                    $manifestParts[] = ['part' => $part, 'name' => $name, 'rows' => $partRows] + $this->fileInfo($stage.'/'.$name);
                }
            }
            if ($handle !== null) {
                $this->finish($handle); $handle = null;
                $manifestParts[] = ['part' => $part, 'name' => $name, 'rows' => $partRows] + $this->fileInfo($stage.'/'.$name);
            }
            if ($total !== $expected || count($manifestParts) !== $parts) throw new RuntimeException('SMS artifact row count differs from the frozen selection.');
            $format = $parts === 1 ? 'csv' : 'zip';
            $download = $this->downloadName($stem, $parts);
            if ($parts > 1) {
                $this->archive($stage, $download, $manifestParts);
                foreach ($manifestParts as $entry) {
                    if (! unlink($stage.'/'.$entry['name'])) throw new RuntimeException('Unable to remove private SMS packaging scratch.');
                }
            }
            $manifest = ['version' => 1, 'request_id' => $id, 'options_sha256' => $this->fingerprint($options),
                'rows' => $expected, 'rows_per_file' => $options['rows_per_file'], 'csv_format' => $options['csv_format'],
                'download' => ['name' => $download, 'format' => $format] + $this->fileInfo($stage.'/'.$download), 'parts' => $manifestParts];
            $this->writeJson($stage.'/manifest.json', $manifest);
            if (! unlink($stage.'/.build.json')) throw new RuntimeException('Unable to finish the private SMS packaging receipt.');
            // A directory rename publishes the primary artifact and its receipt together.
            if (file_exists($root.'/published') || ! rename($stage, $root.'/published')) throw new RuntimeException('Unable to atomically publish the SMS artifact bundle.');
            $path = $this->verify($runDir, $manifest, $options, $expected);
            return ['path' => $path, 'bytes' => $manifest['download']['bytes'], 'sha256' => $manifest['download']['sha256'], 'manifest' => $manifest];
        } catch (Throwable $error) {
            if (is_resource($handle)) fclose($handle);
            // Crashes may leave owned private scratch. Discard quarantines the entire v1 root.
            // Never remove a published bundle after an uncertain caller/SQL outcome.
            throw $error;
        }
    }

    /**
     * Rename/repackage only. CSV bytes are copied directly from the verified v1
     * publication, never rebuilt from mutable database rows or parsed CSV values.
     * The caller holds the run lock and must be outside any SQL transaction.
     *
     * @return array{path:string,bytes:int,sha256:string,manifest:array}
     */
    public function publishApproved(string $runDir, array $pendingManifest, array $options, int $expected, string $range): array
    {
        if ($this->generation !== 'v2' || ($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION
            || ! SmsRunArtifactFilename::isDropRange($range)) {
            throw new RuntimeException('Final SMS publication requires the approval-time filename version and saved drop range.');
        }
        $pending = new self;
        $source = $pending->verify($runDir, $pendingManifest, $options, $expected);
        [$root, $id] = $this->context($runDir);
        $parts = $this->options($options, $expected);
        $stem = 'SMS Export - '.$options['send_date'].' - '.$range;
        $this->matchingStem($stem, $options);
        if (file_exists($root.'.delete-pending')) throw new RuntimeException('The final SMS publication is quarantined.');
        $this->ownedTree($root, $id);
        if (is_dir($root.'/published')) {
            $manifest = $this->json($root.'/published/manifest.json');
            $path = $this->verify($runDir, $manifest, $options, $expected);
            if ($manifest['download']['name'] !== $this->downloadName($stem, $parts)) throw new RuntimeException('The final SMS publication has a different saved drop range.');
            $this->samePartContent($manifest, $pendingManifest);
            return ['path' => $path, 'bytes' => $manifest['download']['bytes'], 'sha256' => $manifest['download']['sha256'], 'manifest' => $manifest];
        }

        $this->directory($root);
        $stage = $root.'/staging-'.bin2hex(random_bytes(8));
        $this->directory($stage);
        $this->writeJson($stage.'/.build.json', ['version' => 1, 'request_id' => $id, 'stem' => $stem, 'parts' => $parts]);
        $zip = null;
        try {
            if ($parts > 1) {
                $zip = new ZipArchive;
                if ($zip->open($source) !== true) throw new RuntimeException('Unable to open the verified pending SMS archive.');
            }
            $entries = [];
            foreach ($pendingManifest['parts'] as $index => $entry) {
                $name = $this->partName($stem, $index + 1, $parts);
                $input = $zip === null ? fopen($source, 'rb') : $zip->getStream($entry['name']);
                if (! is_resource($input)) throw new RuntimeException('Unable to read a verified pending SMS part.');
                $output = null;
                try {
                    $output = fopen($stage.'/'.$name, 'xb');
                    if ($output === false) throw new RuntimeException('Unable to create a final SMS part.');
                    if (stream_copy_to_stream($input, $output, $entry['bytes'] + 1) !== $entry['bytes']) throw new RuntimeException('Pending SMS part bytes changed during finalization.');
                    $this->finish($output); $output = null;
                } finally {
                    fclose($input);
                    if (is_resource($output)) fclose($output);
                }
                $info = $this->fileInfo($stage.'/'.$name);
                if ($info !== ['bytes' => $entry['bytes'], 'sha256' => $entry['sha256']]) throw new RuntimeException('The copied SMS part differs from its pending receipt.');
                $entries[] = ['part' => $index + 1, 'name' => $name, 'rows' => $entry['rows']] + $info;
            }
            if ($zip !== null) { $zip->close(); $zip = null; }
            $download = $this->downloadName($stem, $parts);
            if ($parts > 1) {
                $this->archive($stage, $download, $entries);
                foreach ($entries as $entry) if (! unlink($stage.'/'.$entry['name'])) throw new RuntimeException('Unable to remove final SMS packaging scratch.');
            }
            $manifest = ['version' => 1, 'request_id' => $id, 'options_sha256' => $this->fingerprint($options),
                'rows' => $expected, 'rows_per_file' => $options['rows_per_file'], 'csv_format' => $options['csv_format'],
                'download' => ['name' => $download, 'format' => $parts === 1 ? 'csv' : 'zip'] + $this->fileInfo($stage.'/'.$download), 'parts' => $entries];
            $this->samePartContent($manifest, $pendingManifest);
            $this->writeJson($stage.'/manifest.json', $manifest);
            if (! unlink($stage.'/.build.json')) throw new RuntimeException('Unable to finish the final SMS packaging receipt.');
            if (file_exists($root.'/published') || ! rename($stage, $root.'/published')) throw new RuntimeException('Unable to atomically publish the final SMS artifact.');
            $path = $this->verify($runDir, $manifest, $options, $expected);
            return ['path' => $path, 'bytes' => $manifest['download']['bytes'], 'sha256' => $manifest['download']['sha256'], 'manifest' => $manifest];
        } finally {
            if ($zip !== null) $zip->close();
        }
    }

    private function samePartContent(array $final, array $pending): void
    {
        if (count($final['parts']) !== count($pending['parts'])) throw new RuntimeException('Final SMS parts differ from the pending publication.');
        foreach ($pending['parts'] as $index => $entry) {
            foreach (['part', 'rows', 'bytes', 'sha256'] as $key) {
                if ($final['parts'][$index][$key] !== $entry[$key]) throw new RuntimeException('Final SMS part content differs from the pending publication.');
            }
        }
    }

    /**
     * Verify a retained publication against its database receipt and frozen intent.
     * HTTP may skip member parsing: the primary SHA pins the same bytes already
     * deeply validated by the background publisher. It never skips primary hashing.
     */
    public function verify(string $runDir, array $manifest, array $options, int $expected, bool $deep = true): string
    {
        [$root, $id] = $this->context($runDir);
        $this->ownedTree($root, $id);
        $this->validateManifest($manifest, $id, $options, $expected);
        if ($this->json($root.'/published/manifest.json') !== $manifest) {
            throw new RuntimeException('The SMS artifact manifest differs from the frozen run options or retained receipt.');
        }
        $path = $root.'/published/'.$manifest['download']['name'];
        if ($this->fileInfo($path) !== ['bytes' => $manifest['download']['bytes'], 'sha256' => $manifest['download']['sha256']]) {
            throw new RuntimeException('The SMS artifact bytes/hash differ from the retained manifest.');
        }
        if (! $deep) return $path;
        if ($manifest['download']['format'] === 'csv') {
            $this->verifyCsvFile($path, $manifest['parts'][0]);
        } else {
            if (! class_exists(ZipArchive::class)) throw new RuntimeException('PHP ZipArchive is required to verify the SMS archive.');
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) throw new RuntimeException('The retained SMS archive cannot be opened.');
            try {
                if ($zip->numFiles !== count($manifest['parts'])) throw new RuntimeException('The SMS archive has unexpected entries.');
                foreach ($manifest['parts'] as $index => $entry) {
                    $stat = $zip->statIndex($index);
                    if (! $stat || $stat['name'] !== $entry['name'] || $stat['size'] !== $entry['bytes']) throw new RuntimeException('The SMS archive part names or sizes differ from the manifest.');
                    $stream = $zip->getStream($entry['name']);
                    if ($stream === false) throw new RuntimeException('Unable to verify an SMS archive part.');
                    try {
                        $hash = hash_init('sha256'); hash_update_stream($hash, $stream);
                        if (! hash_equals($entry['sha256'], hash_final($hash))) throw new RuntimeException('An SMS archive part hash differs from the manifest.');
                    } finally { fclose($stream); }
                    $stream = $zip->getStream($entry['name']);
                    if ($stream === false) throw new RuntimeException('Unable to count an SMS archive part.');
                    try { $this->verifyCsvStream($stream, $entry['rows']); } finally { fclose($stream); }
                }
            } finally { $zip->close(); }
        }
        return $path;
    }

    /** Pure receipt validation for status DTOs; never reads artifact bytes or paths. */
    public function validateManifest(array $manifest, string $id, array $options, int $expected): void
    {
        $this->options($options, $expected);
        $this->manifest($manifest, $id);
        if ($manifest['rows'] !== $expected || $manifest['rows_per_file'] !== $options['rows_per_file']
            || $manifest['csv_format'] !== $options['csv_format'] || ! hash_equals($this->fingerprint($options), $manifest['options_sha256'])) {
            throw new RuntimeException('The SMS artifact manifest differs from the frozen run options or retained receipt.');
        }
        $this->matchingStem($this->manifestStem($manifest['download']['name'], $manifest['download']['format']), $options);
    }

    /** Adopt a retained publication's exact name on retries, never derive a new one. */
    public function publishedStem(string $runDir, array $options, int $expected): string
    {
        [$root, $id] = $this->context($runDir);
        $this->ownedTree($root, $id);
        $manifest = $this->json($root.'/published/manifest.json');
        $this->validateManifest($manifest, $id, $options, $expected);
        return $this->manifestStem($manifest['download']['name'], $manifest['download']['format']);
    }

    /** Quarantine published AND abandoned private build scratch in one atomic rename. */
    public function quarantine(string $runDir): ?string
    {
        [$root, $id] = $this->context($runDir);
        $quarantine = $root.'.delete-pending';
        if (file_exists($root) && file_exists($quarantine)) throw new RuntimeException('Conflicting SMS artifact quarantine evidence; nothing was moved.');
        if (file_exists($quarantine)) { $this->ownedTree($quarantine, $id); return $quarantine; }
        if (! file_exists($root)) return null;
        $this->ownedTree($root, $id);
        if (! rename($root, $quarantine)) throw new RuntimeException('The SMS artifact bundle could not be quarantined.');
        return $quarantine;
    }

    /** Caller must have established that the database delete rolled back. */
    public function restore(string $runDir): void
    {
        [$root, $id] = $this->context($runDir);
        $quarantine = $root.'.delete-pending';
        if (! file_exists($quarantine)) return;
        if (file_exists($root)) throw new RuntimeException('Conflicting SMS artifact restore target; nothing was overwritten.');
        $this->ownedTree($quarantine, $id);
        if (! rename($quarantine, $root)) throw new RuntimeException('Unable to restore the quarantined SMS artifact bundle.');
    }

    /** Delete only a completely prevalidated, UUID-owned quarantine tree. */
    public function cleanup(string $runDir): bool
    {
        [$root, $id] = $this->context($runDir);
        $quarantine = $root.'.delete-pending';
        if (! file_exists($quarantine)) return true;
        if (file_exists($root)) throw new RuntimeException('Conflicting SMS artifact cleanup evidence; nothing was deleted.');
        [$files, $directories] = $this->ownedTree($quarantine, $id, true);
        // Keep ownership receipts until every data file has been removed, so an
        // interrupted cleanup can still prevalidate and finish on a later retry.
        usort($files, static fn (string $a, string $b): int => (int) in_array(basename($a), ['manifest.json', '.build.json'], true)
            <=> (int) in_array(basename($b), ['manifest.json', '.build.json'], true));
        foreach ($files as $path) if (! @unlink($path)) return false;
        foreach (array_reverse($directories) as $path) if (! @rmdir($path)) return false;
        return true;
    }

    private function options(array $options, int $expected): int
    {
        if ($this->generation === 'v2' && ($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) {
            throw new RuntimeException('The v2 SMS publication requires the explicit approval-time filename version.');
        }
        if (! isset($options['target'], $options['rows_per_file'], $options['tiers'], $options['csv_format'])
            || ! is_int($options['target']) || ! is_int($options['rows_per_file']) || ! is_array($options['tiers'])
            || (array_key_exists('tier_selection_policy', $options) && ! SmsRunOptions::isTierSelectionPolicy($options['tier_selection_policy']))
            || $options !== SmsRunOptions::normalize($options['target'], $options['rows_per_file'], $options['tiers'], $options['tier_selection_policy'] ?? null,
                $options['options_version'] ?? null, $options['send_date'] ?? null, $options['filename_version'] ?? null)) {
            throw new RuntimeException('The SMS artifact requires complete canonical versioned run options.');
        }
        if ($expected < 1 || $expected > $options['target']) throw new RuntimeException('The SMS artifact requires a positive selected count no greater than its target.');
        $parts = intdiv($expected - 1, $options['rows_per_file']) + 1;
        if ($parts > self::MAX_PARTS) throw new RuntimeException('An SMS artifact may contain at most '.self::MAX_PARTS.' files. Increase rows per file.');
        return $parts;
    }

    /** @return array{string,string} */
    private function context(string $runDir): array
    {
        $path = str_replace('\\', '/', rtrim($runDir, '/\\'));
        if (str_contains($path, "\0") || preg_match('/[\r\n]/', $path)
            || ! preg_match('~^(?:[A-Za-z]:/|/)(?!/).*/runs/([0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12})$~D', $path, $match)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || str_contains($path, '//')) {
            throw new RuntimeException('Use an absolute private SMS runs/UUID directory.');
        }
        $ancestor = $path;
        while (true) {
            if (is_link($ancestor)) throw new RuntimeException('SMS artifact paths cannot contain symlinks.');
            if (file_exists($ancestor) && ! is_dir($ancestor)) throw new RuntimeException('SMS artifact directory has an unexpected file ancestor.');
            $parent = str_replace('\\', '/', dirname($ancestor));
            if ($parent === $ancestor || $parent === '.') break;
            $ancestor = $parent;
        }
        foreach ([$path.'/v1', $path.'/v1.delete-pending', $path.'/v2', $path.'/v2.delete-pending'] as $candidate) {
            if (is_link($candidate)) throw new RuntimeException('SMS artifact roots cannot be symlinks.');
        }
        return [$path.'/'.$this->generation, $match[1]];
    }

    /** Prepare only the two trusted original names; a partial restore stays unreadable until verify succeeds. */
    public function prepareArchiveRestore(string $runDir, array $manifest): string
    {
        [$root, $id] = $this->context($runDir);
        $this->manifest($manifest, $id);
        if (file_exists($root.'.delete-pending')) throw new RuntimeException('Cannot restore a quarantined SMS run.');
        // Preserve validated abandoned generation stages. They can legitimately
        // coexist with a recovered publication and must not block archive restore.
        $this->ownedTree($root, $id, false, $manifest);
        $published = $root.'/published';
        if (is_link($published) || (file_exists($published) && ! is_dir($published))) {
            throw new RuntimeException('Unexpected SMS archive restore publication path.');
        }
        foreach (is_dir($published) ? array_diff(scandir($published), ['.', '..']) : [] as $entry) {
            if (! in_array($entry, ['manifest.json', $manifest['download']['name']], true)
                || is_link($published.'/'.$entry) || ! is_file($published.'/'.$entry)) {
                throw new RuntimeException('Unexpected SMS archive restore file.');
            }
        }
        $this->directory($published);
        return $published;
    }

    /** Outside the published v1 tree, so interrupted private copies never invalidate downloads/retries. */
    public function archiveRestoreScratch(string $runDir): string
    {
        $this->context($runDir);
        $scratch = str_replace('\\', '/', rtrim($runDir, '/\\')).'/archive-restore'.($this->generation === 'v2' ? '-v2' : '');
        $this->directory($scratch);
        return $scratch;
    }

    /** Prevalidate every entry before any deletion. Does not follow links. */
    private function ownedTree(string $root, string $id, bool $cleanup = false, ?array $restoreManifest = null): array
    {
        if (! file_exists($root)) return [[], []];
        if (is_link($root) || ! is_dir($root)) throw new RuntimeException('Unexpected SMS artifact root.');
        $files = []; $directories = [$root];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $dir = $root.'/'.$name;
            if (is_link($dir) || ! is_dir($dir) || ($name !== 'published' && ! preg_match('/^staging-[a-f0-9]{16}$/D', $name))) {
                throw new RuntimeException('Unexpected SMS artifact bundle entry; nothing was deleted.');
            }
            $directories[] = $dir;
            $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
            foreach ($entries as $entry) if (is_link($dir.'/'.$entry) || ! is_file($dir.'/'.$entry)) throw new RuntimeException('Unexpected SMS artifact file or symlink.');
            if ($name === 'published') {
                if ($cleanup && $entries === []) continue;
                $manifest = $restoreManifest ?? $this->json($dir.'/manifest.json'); $this->manifest($manifest, $id);
                $allowed = ['manifest.json', $manifest['download']['name']];
                if (! $cleanup && $restoreManifest === null && count($entries) !== 2) throw new RuntimeException('Published SMS bundle has unexpected or missing files.');
            } elseif (in_array('.build.json', $entries, true)) {
                $receipt = $this->json($dir.'/.build.json');
                if (($receipt['version'] ?? null) !== 1 || ($receipt['request_id'] ?? null) !== $id
                    || ! is_string($receipt['stem'] ?? null) || ! is_int($receipt['parts'] ?? null)
                    || $receipt['parts'] < 1 || $receipt['parts'] > self::MAX_PARTS) throw new RuntimeException('Unrecognized SMS build receipt.');
                $this->stem($receipt['stem']);
                $allowed = ['.build.json', 'manifest.json', $this->downloadName($receipt['stem'], $receipt['parts'])];
                for ($part = 1; $part <= $receipt['parts']; $part++) $allowed[] = $this->partName($receipt['stem'], $part, $receipt['parts']);
            } elseif (in_array('manifest.json', $entries, true)) {
                $manifest = $this->json($dir.'/manifest.json'); $this->manifest($manifest, $id);
                $allowed = ['manifest.json', $manifest['download']['name']];
            } elseif ($entries === []) $allowed = [];
            else throw new RuntimeException('Unrecognized private SMS build scratch; nothing was deleted.');
            foreach ($entries as $entry) {
                if (! in_array($entry, $allowed, true)) throw new RuntimeException('Unexpected SMS artifact scratch file; nothing was deleted.');
                $files[] = $dir.'/'.$entry;
            }
        }
        return [$files, $directories];
    }

    private function manifest(array $manifest, string $id): void
    {
        if (array_keys($manifest) !== ['version','request_id','options_sha256','rows','rows_per_file','csv_format','download','parts']
            || $manifest['version'] !== 1 || $manifest['request_id'] !== $id || ! $this->hash($manifest['options_sha256'])
            || ! is_int($manifest['rows']) || $manifest['rows'] < 1 || $manifest['rows'] > SmsRunOptions::MAX_TARGET
            || ! is_int($manifest['rows_per_file']) || $manifest['rows_per_file'] < 1 || $manifest['rows_per_file'] > SmsRunOptions::MAX_TARGET
            || $manifest['csv_format'] !== SmsRunOptions::CSV_FORMAT || ! is_array($manifest['parts']) || ! array_is_list($manifest['parts'])
            || ! is_array($manifest['download']) || array_keys($manifest['download']) !== ['name','format','bytes','sha256']) throw new RuntimeException('Invalid SMS artifact manifest.');
        $parts = intdiv($manifest['rows'] - 1, $manifest['rows_per_file']) + 1;
        $format = $parts === 1 ? 'csv' : 'zip';
        $download = $manifest['download'];
        if ($parts > self::MAX_PARTS || count($manifest['parts']) !== $parts || $download['format'] !== $format
            || ! is_string($download['name']) || ! str_ends_with($download['name'], '.'.$format)
            || ! is_int($download['bytes']) || $download['bytes'] < 1 || ! $this->hash($download['sha256'])) throw new RuntimeException('Invalid SMS artifact download manifest.');
        $stem = $this->manifestStem($download['name'], $format); $this->stem($stem);
        if ($download['name'] !== $this->downloadName($stem, $parts)) throw new RuntimeException('Invalid SMS artifact download filename.');
        foreach ($manifest['parts'] as $index => $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['part','name','rows','bytes','sha256']
                || $entry['part'] !== $index + 1 || $entry['name'] !== $this->partName($stem, $index + 1, $parts)
                || $entry['rows'] !== min($manifest['rows_per_file'], $manifest['rows'] - $index * $manifest['rows_per_file'])
                || ! is_int($entry['bytes']) || $entry['bytes'] < 1 || ! $this->hash($entry['sha256'])) throw new RuntimeException('Invalid SMS artifact part manifest.');
        }
        if ($parts === 1 && ($manifest['parts'][0]['bytes'] !== $download['bytes'] || $manifest['parts'][0]['sha256'] !== $download['sha256'])) throw new RuntimeException('Single CSV manifest does not reconcile.');
    }

    private function archive(string $stage, string $name, array $parts): void
    {
        $zip = new ZipArchive;
        if ($zip->open($stage.'/'.$name, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Unable to create the SMS archive.');
        try {
            foreach ($parts as $entry) {
                if (! $zip->addFile($stage.'/'.$entry['name'], $entry['name'])
                    || ! $zip->setCompressionName($entry['name'], ZipArchive::CM_STORE)
                    || ! $zip->setMtimeName($entry['name'], self::ZIP_TIME)
                    || ! $zip->setExternalAttributesName($entry['name'], ZipArchive::OPSYS_UNIX, 0100640 << 16)) throw new RuntimeException('Unable to add a deterministic SMS archive part.');
            }
        } catch (Throwable $error) { $zip->close(); throw $error; }
        if (! $zip->close()) throw new RuntimeException('Unable to finish the SMS archive.');
        $handle = fopen($stage.'/'.$name, 'ab');
        if ($handle === false) throw new RuntimeException('Unable to flush the SMS archive.');
        $this->finish($handle);
    }

    private function verifyCsvFile(string $path, array $entry): void
    {
        if ($this->fileInfo($path) !== ['bytes' => $entry['bytes'], 'sha256' => $entry['sha256']]) throw new RuntimeException('An SMS CSV part hash differs from the manifest.');
        $stream = fopen($path, 'rb');
        if ($stream === false) throw new RuntimeException('Unable to read the retained SMS CSV.');
        try { $this->verifyCsvStream($stream, $entry['rows']); } finally { fclose($stream); }
    }

    private function verifyCsvStream($stream, int $expected): void
    {
        if (fgetcsv($stream, escape: '') !== SmsDeliveryPolicy::headerForFormat(SmsRunOptions::CSV_FORMAT)) throw new RuntimeException('The SMS CSV header differs from its explicit format.');
        $rows = 0;
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            if (count($row) !== 4 || ++$rows > $expected) throw new RuntimeException('SMS CSV part count or columns do not reconcile.');
        }
        if (! feof($stream) || $rows !== $expected) throw new RuntimeException('SMS CSV part count does not reconcile.');
    }

    private function stem(string $stem): void
    {
        if (SmsRunArtifactFilename::parseStem($stem) !== null) return;
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $stem)) throw new RuntimeException('Invalid deterministic SMS artifact filename.');
    }

    private function partName(string $stem, int $part, int $parts): string
    {
        if (str_starts_with($stem, 'SMS Export - ')) return $stem.' - Part '.$part.' of '.$parts.'.csv';
        return $parts === 1 ? $stem.'.csv' : $stem.'_part'.sprintf('%04d', $part).'.csv';
    }

    private function downloadName(string $stem, int $parts): string
    {
        return $parts === 1 ? $this->partName($stem, 1, 1) : $stem.'.zip';
    }

    private function manifestStem(string $name, string $format): string
    {
        return SmsRunArtifactFilename::stemFromDownload($name, $format);
    }

    private function matchingStem(string $stem, array $options): void
    {
        $planned = SmsRunArtifactFilename::parseStem($stem);
        if (($options['filename_version'] ?? null) === SmsRunOptions::APPROVAL_FILENAME_VERSION) {
            if ($planned === null || $planned['send_date'] !== $options['send_date']
                || ($this->generation === 'v1'
                    ? ($planned['id_pending'] ?? false) !== true
                    : ($planned['drop_name_range'] === null || ($planned['id_pending'] ?? false)))) {
                throw new RuntimeException('The SMS artifact filename does not match its pending or approved publication phase.');
            }
            return;
        }
        if (isset($options['send_date'])
            ? ($planned === null || $planned['send_date'] !== $options['send_date']
                || ($planned['id_pending'] ?? false)
                || (isset($options['filename_version']) ? $planned['drop_name_range'] === null : $planned['drop_name_range'] !== null))
            : str_starts_with($stem, 'SMS Export - ')) {
            throw new RuntimeException('The SMS artifact filename does not match its frozen options version and date.');
        }
    }

    /** The only newly accepted spaces are in Jacob's exact calendar-date filename. */
    public static function isPlannedDownloadName(string $name): bool
    {
        foreach ([' - Part 1 of 1.csv', '.zip'] as $suffix) {
            if (str_ends_with($name, $suffix)) return SmsRunArtifactFilename::parseStem(substr($name, 0, -strlen($suffix))) !== null;
        }
        return false;
    }

    /** Existing provider alphabet, or that same safe prefix plus the exact new filename. */
    public static function isSafeArchiveKey(string $key): bool
    {
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._=+/@-]*$~D', $key)) return true;
        $offset = strrpos($key, '/');
        $prefix = $offset === false ? '' : substr($key, 0, $offset + 1);
        $name = $offset === false ? $key : substr($key, $offset + 1);
        return ($prefix === '' || preg_match('~^[A-Za-z0-9][A-Za-z0-9._=+/@-]*/$~D', $prefix))
            && self::isPlannedDownloadName($name);
    }

    private function fingerprint(array $options): string { return hash('sha256', json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
    private function hash(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }

    private function json(string $path): array
    {
        if (is_link($path) || ! is_file($path)) throw new RuntimeException('The SMS artifact receipt is missing or is a symlink.');
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) throw new RuntimeException('Invalid SMS artifact receipt JSON.');
        return $value;
    }

    private function fileInfo(string $path): array
    {
        if (is_link($path) || ! is_file($path)) throw new RuntimeException('The SMS artifact file is missing or is a symlink.');
        clearstatcache(true, $path); $size = filesize($path); $hash = hash_file('sha256', $path);
        if ($size === false || $size < 1 || $hash === false) throw new RuntimeException('Unable to verify the SMS artifact file.');
        return ['bytes' => $size, 'sha256' => $hash];
    }

    private function csv($handle, array $row): void
    {
        if (fputcsv($handle, $row, ',', '"', '', "\r\n") === false) throw new RuntimeException('Unable to write an SMS CSV part.');
    }

    private function finish($handle): void
    {
        try {
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) throw new RuntimeException('Unable to flush an SMS artifact file.');
            $meta = stream_get_meta_data($handle);
            $this->permissions($meta['uri'], false);
        } finally { fclose($handle); }
    }

    private function writeJson(string $path, array $value): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) throw new RuntimeException('Unable to create an SMS artifact receipt.');
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($handle, $json) !== strlen($json)) throw new RuntimeException('Unable to write an SMS artifact receipt.');
        } catch (Throwable $error) { fclose($handle); throw $error; }
        $this->finish($handle);
    }

    private function directory(string $path): void
    {
        if (is_link($path)) throw new RuntimeException('Private SMS directories cannot be symlinks.');
        if (is_dir($path)) return;
        $parent = dirname($path);
        if ($parent === $path) throw new RuntimeException('Invalid private SMS directory.');
        $this->directory($parent);
        // The queue and web workers may have different UIDs but share the configured
        // private storage group. Both need directory write access for atomic quarantine
        // and cleanup; files remain group-readable only (0640), never public.
        if (! mkdir($path, PHP_OS_FAMILY === 'Windows' ? 0700 : 02770) && ! is_dir($path)) throw new RuntimeException('Unable to create a private SMS artifact directory.');
        $this->permissions($path, true);
    }

    private function permissions(string $path, bool $directory): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $group = filegroup(dirname($path));
            if ($group === false || ! chgrp($path, $group)) throw new RuntimeException('Unable to preserve the private SMS storage group.');
        }
        if (! chmod($path, PHP_OS_FAMILY === 'Windows' ? ($directory ? 0700 : 0600) : ($directory ? 02770 : 0640))) throw new RuntimeException('Unable to protect private SMS artifact permissions.');
    }
}
