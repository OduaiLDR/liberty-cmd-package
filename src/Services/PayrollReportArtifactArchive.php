<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use ZipArchive;

/** Private immutable source workbooks; callers select allowlisted report keys, never paths. */
final class PayrollReportArtifactArchive
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const SOURCE_KEYS = ['retention_ldr', 'retention_plaw', 'lookback_ldr', 'lookback_plaw', 'nsf_ldr', 'nsf_plaw'];
    public const LEADERSHIP_KEYS = ['customer_support_manager', 'retention_team_leader', 'nsf_team_leader'];
    private string $root;
    public function __construct(?string $root = null) { $this->root = $root ?? storage_path('app/payroll-report-artifacts'); }

    public static function captureFromEnvironment(string $family, string $source, string $period, array $file, array $context = []): ?array
    {
        if (!self::captureRequested()) return null;
        $runId = getenv('PAYROLL_COMMISSION_RUN_ID');
        return (new self)->capture($family . '_' . $source, $period, $runId, $file, $context);
    }

    /** The trusted runner opts CS in; ordinary Sales refreshes need only Lookback inputs. */
    public static function captureRequested(): bool
    {
        if (getenv('PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT') !== '1') return false;
        $runId = getenv('PAYROLL_COMMISSION_RUN_ID');
        if (!is_string($runId) || !preg_match('/^[a-f0-9]{32}$/', $runId)) {
            throw new \InvalidArgumentException('Customer Service capture requires a reserved commission run id.');
        }
        return true;
    }

    public function capture(string $key, string $period, string $identity, array $file, array $context = []): array
    {
        return $this->captureAt($key, $period, $identity, $file, $context, $this->directory($key, $period, $identity));
    }

    public function captureAmendment(string $key, string $period, string $bundleId, array $file, array $context = []): array
    {
        return $this->captureAt($key, $period, $bundleId, $file, $context, $this->amendmentDirectory($key, $period, $bundleId));
    }

    private function captureAt(string $key, string $period, string $identity, array $file, array $context, string $directory): array
    {
        $fileName = (string) ($file['filename'] ?? '');
        if ($fileName === '' || basename(str_replace('\\', '/', $fileName)) !== $fileName || !str_ends_with(strtolower($fileName), '.xlsx')) {
            throw new \InvalidArgumentException('The payroll archive requires a safe XLSX attachment name.');
        }
        $input = (string) ($file['path'] ?? '');
        $evidence = $this->verifyWorkbook($input);
        // Legacy evidence must describe these exact bytes before an immutable
        // identity is reserved. Otherwise a replaced input could poison it.
        if (array_key_exists('legacyEvidence', $context)) {
            $legacy = $context['legacyEvidence'];
            if (!is_array($legacy) || !in_array($key, ['retention_ldr', 'retention_plaw'], true)
                || ($legacy['source'] ?? null) !== substr($key, strlen('retention_'))
                || ($legacy['period'] ?? null) !== $period
                || !is_string($legacy['workbookSha256'] ?? null)
                || !hash_equals($evidence['sha256'], $legacy['workbookSha256'])
                || ($legacy['coverage'] ?? null) !== 'unverified'
                || ($legacy['historicalLinePricing'] ?? null) !== 'unverified'
                || ($legacy['executionReceipt'] ?? null) !== 'unverified'
                || ($legacy['eligibleForPayment'] ?? null) !== false
                || array_key_exists('commissionResults', $context)) {
                throw new \RuntimeException('Legacy evidence does not match the original workbook and unverified scope.');
            }
        }
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $context = json_decode($contextJson, true, flags: JSON_THROW_ON_ERROR);
        $metadata = ['key' => $key, 'period' => $period, 'identity' => $identity, 'fileName' => $fileName,
            'contentType' => self::MIME, ...$evidence, 'context' => $context, 'contextSha256' => hash('sha256', $contextJson)];
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Payroll workbook archive directory is unavailable.');
        if (is_link($directory)) throw new \RuntimeException('Payroll workbook archive cannot follow a symbolic link.');
        $lock = fopen($directory . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Payroll workbook archive lock is unavailable.');
        try {
            if (is_file($directory . '/artifact.json')) {
                $stored = $this->readAt($key, $period, $identity, $directory);
                if (array_diff_key($stored, ['path' => true]) !== $metadata) throw new \RuntimeException('An immutable payroll workbook identity already has different content.');
                return $stored;
            }
            $destination = $directory . '/report.xlsx';
            if (is_file($destination)) {
                if ($this->verifyWorkbook($destination) !== $evidence) throw new \RuntimeException('An interrupted payroll archive has different saved content.');
            } else {
                $temporary = $directory . '/report-' . bin2hex(random_bytes(8)) . '.tmp';
                try {
                    if (!copy($input, $temporary) || $this->verifyWorkbook($temporary) !== $evidence || !rename($temporary, $destination)) {
                        throw new \RuntimeException('Payroll workbook archive copy was not verified.');
                    }
                } finally { if (is_file($temporary)) unlink($temporary); }
            }
            $temporary = $directory . '/metadata-' . bin2hex(random_bytes(8)) . '.tmp';
            try {
                if (file_put_contents($temporary, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false
                    || !rename($temporary, $directory . '/artifact.json')) throw new \RuntimeException('Payroll workbook archive metadata could not be published.');
            } finally { if (is_file($temporary)) unlink($temporary); }
            return [...$metadata, 'path' => $destination];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function read(string $key, string $period, string $identity): array
    {
        return $this->readAt($key, $period, $identity, $this->directory($key, $period, $identity));
    }

    public function readAmendment(string $key, string $period, string $bundleId): array
    {
        return $this->readAt($key, $period, $bundleId, $this->amendmentDirectory($key, $period, $bundleId));
    }

    private function readAt(string $key, string $period, string $identity, string $directory): array
    {
        $path = $directory . '/report.xlsx';
        if (is_link($directory) || is_link($path) || !is_file($directory . '/artifact.json')) throw new \RuntimeException('The correlated payroll workbook archive is missing.');
        $metadata = json_decode((string) file_get_contents($directory . '/artifact.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($metadata['key'] ?? null) !== $key || ($metadata['period'] ?? null) !== $period || ($metadata['identity'] ?? null) !== $identity
            || ($metadata['contentType'] ?? null) !== self::MIME || !is_array($metadata['context'] ?? null)
            || hash('sha256', json_encode($metadata['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== ($metadata['contextSha256'] ?? null)
            || $this->verifyWorkbook($path) !== ['sha256' => $metadata['sha256'] ?? null, 'byteLength' => $metadata['byteLength'] ?? null]) {
            throw new \RuntimeException('The correlated payroll workbook archive no longer matches its immutable evidence.');
        }
        return [...$metadata, 'path' => $path];
    }

    private function directory(string $key, string $period, string $identity): string
    {
        $source = in_array($key, self::SOURCE_KEYS, true);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $period);
        if ((!$source && !in_array($key, self::LEADERSHIP_KEYS, true)) || !$date || $date->format('Y-m-d') !== $period
            || $date->format('d') !== '01' || !preg_match($source ? '/^[a-f0-9]{32}$/' : '/^[a-f0-9]{64}$/', $identity)) {
            throw new \InvalidArgumentException('Payroll artifact scope requires an approved report, exact reporting month and correlated identity.');
        }
        return rtrim($this->root, '/\\') . '/' . $period . '/' . $identity . '/' . $key;
    }

    private function amendmentDirectory(string $key, string $period, string $bundleId): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $period);
        if (!in_array($key, [...self::SOURCE_KEYS, ...self::LEADERSHIP_KEYS], true)
            || !$date || $date->format('Y-m-d') !== $period || $date->format('d') !== '01'
            || !preg_match('/^[a-f0-9]{64}$/D', $bundleId)) {
            throw new \InvalidArgumentException('Payroll amendment requires an approved report, reporting month and immutable bundle id.');
        }
        return rtrim($this->root, '/\\') . '/amendments/' . $period . '/' . $bundleId . '/' . $key;
    }

    private function verifyWorkbook(string $path): array
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        if (!$size || $size > 32 * 1024 * 1024 || is_link($path)) throw new \RuntimeException('Payroll XLSX is missing, empty or exceeds the archive limit.');
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) throw new \RuntimeException('Payroll artifact is not a readable XLSX package.');
        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) throw new \RuntimeException('Payroll artifact is missing its XLSX workbook structure.');
        } finally { $zip->close(); }
        $hash = hash_file('sha256', $path);
        if ($hash === false) throw new \RuntimeException('Payroll XLSX checksum is unavailable.');
        return ['sha256' => $hash, 'byteLength' => $size];
    }
}
