<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use InvalidArgumentException;
use Throwable;

/** Frozen ordinary approved-file handoff. No configuration, persistence or artifact I/O. */
final class SmsDebtPleteImportPlan
{
    private const MAX_PART_BYTES = 1073741824;
    private const MAX_TOTAL_BYTES = 10737418240;
    private const MAX_JSON_BYTES = 1048576;

    private function __construct(
        private readonly array $payload,
        private readonly array $identity,
        private readonly string $json,
        private readonly string $fingerprint,
    ) {}

    public static function fromApproved(object $run, array $identity): self
    {
        try {
            self::identityValid($identity);
            self::require(is_string($run->Request_ID ?? null) && self::uuid(strtolower($run->Request_ID))
                && ($run->Selection_Policy ?? null) === SmsRecordPolicy::RECORDS
                && ($run->Status ?? null) === 'approved'
                && is_string($run->Approved_By ?? null) && $run->Approved_By !== '' && $run->Approved_By === trim($run->Approved_By)
                && strlen($run->Approved_By) <= 255 && !preg_match('/[\x00-\x1f\x7f]/', $run->Approved_By)
                && in_array($run->File_Cleanup_Path ?? null, [null, ''], true));
            // SQL Server uniqueidentifier may be rendered uppercase. Only the
            // trusted source ID is canonicalized; the raw row remains fingerprinted.
            $requestId = strtolower($run->Request_ID);
            $target = self::integer($run->Target ?? null, SmsRunOptions::MAX_TARGET);
            $selected = self::integer($run->Selected ?? null, $target);
            self::require(is_string($run->Run_Options_JSON ?? null) && strlen($run->Run_Options_JSON) <= self::MAX_JSON_BYTES
                && is_string($run->Artifact_Manifest_JSON ?? null) && strlen($run->Artifact_Manifest_JSON) <= self::MAX_JSON_BYTES);
            $options = SmsRunOptions::fromStored($run->Run_Options_JSON, $target);
            self::require(is_array($options) && ($options['options_version'] ?? null) === SmsRunOptions::PLANNED_DATE_VERSION
                && ($options['filename_version'] ?? null) === SmsRunOptions::APPROVAL_FILENAME_VERSION
                && (array_key_exists('tier_selection_policy', $options) || $options['tiers'] === SmsRunOptions::TIERS));
            $manifest = json_decode($run->Artifact_Manifest_JSON, true, 16, JSON_THROW_ON_ERROR);
            self::require(is_array($manifest));
            (new SmsRunArtifactBundle('v2'))->validateManifest($manifest, $requestId, $options, $selected);
            $total = 0;
            foreach ($manifest['parts'] as $part) {
                self::require($part['bytes'] <= self::MAX_PART_BYTES);
                $total += $part['bytes'];
                self::require($total <= self::MAX_TOTAL_BYTES);
            }
            self::require($manifest['download']['bytes'] <= self::MAX_TOTAL_BYTES + 2097152
                && $manifest['download']['bytes'] >= $total
                && self::integer($run->File_Bytes ?? null, self::MAX_TOTAL_BYTES + 2097152) === $manifest['download']['bytes']
                && is_string($run->File_Sha256 ?? null) && hash_equals($manifest['download']['sha256'], $run->File_Sha256)
                && is_string($run->File_Path ?? null) && $run->File_Path !== '' && !str_contains($run->File_Path, "\0"));
            $payload = ['version' => 1, 'request_id' => $requestId,
                'approval' => ['state' => 'approved', 'at' => self::approvalInstant($run->Approved_At ?? null)],
                'options' => $options, 'manifest' => $manifest];
            // Receiver insertion order and escaping are part of its exact seal.
            $json = json_encode(['tenant_id' => $identity['tenant_id'], 'client_id' => $identity['client_id'], 'plan' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            self::require(strlen($json) <= self::MAX_JSON_BYTES);
            $values = [];
            foreach (['Request_ID', 'Status', 'Selection_Policy', 'Target', 'Selected', 'Approved_By', 'Approved_At', 'Sent_By', 'Sent_At',
                'Run_Options_JSON', 'Artifact_Manifest_JSON', 'File_Path', 'File_Bytes', 'File_Sha256', 'File_Cleanup_Path'] as $field) {
                $values[$field] = $run->$field ?? null;
            }
            $fingerprint = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return new self($payload, $identity, $json, $fingerprint);
        } catch (Throwable) {
            throw new InvalidArgumentException('The approved SMS import plan could not be verified.');
        }
    }

    public function payload(): array { return $this->payload; }
    public function json(): string { return $this->json; }
    public function sha256(): string { return hash('sha256', $this->json); }
    public function fingerprint(): string { return $this->fingerprint; }
    public function requestId(): string { return $this->payload['request_id']; }
    public function rows(): int { return $this->payload['manifest']['rows']; }
    public function parts(): array { return $this->payload['manifest']['parts']; }
    public function options(): array { return $this->payload['options']; }
    public function identity(): array { return $this->identity; }

    private static function identityValid(array $identity): void
    {
        $keys = array_keys($identity); sort($keys);
        self::require($keys === ['client_id', 'origin', 'protocol', 'tenant_id']
            && $identity['protocol'] === 'debtplete-sms-import-v1'
            && self::uuid($identity['tenant_id']) && self::uuid($identity['client_id'])
            && is_string($identity['origin']) && strlen($identity['origin']) <= 261);
        $origin = parse_url($identity['origin']);
        self::require(is_array($origin) && ($origin['scheme'] ?? null) === 'https'
            && self::host($origin['host'] ?? null) && !isset($origin['port'])
            && $identity['origin'] === 'https://'.$origin['host']);
    }

    /** Match the real Client's canonical public DNS identity; no ports, IPs or single labels. */
    private static function host(mixed $host): bool
    {
        if (!is_string($host) || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A[a-z0-9.-]+\z/D', $host) !== 1) { return false; }
        $labels = explode('.', $host);
        if (count($labels) < 2 || preg_match('/\A[a-z][a-z0-9-]*\z/D', end($labels)) !== 1) { return false; }
        foreach ($labels as $label) {
            if (strlen($label) < 1 || strlen($label) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label) !== 1) { return false; }
        }
        return true;
    }

    private static function approvalInstant(mixed $value): string
    {
        self::require(is_string($value)
            && preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})(\.[0-9]{1,7})?\z/D', $value, $match) === 1
            && checkdate((int) $match[2], (int) $match[3], (int) $match[1])
            && (int) $match[4] <= 23 && (int) $match[5] <= 59 && (int) $match[6] <= 59);

        return substr($value, 0, 10).'T'.substr($value, 11).'Z';
    }

    private static function integer(mixed $value, int $maximum): int
    {
        self::require(is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,10}\z/D', $value) === 1));
        self::require((int) $value >= 1 && (int) $value <= $maximum);

        return (int) $value;
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $value) === 1;
    }

    private static function require(bool $condition): void
    {
        if (!$condition) { throw new InvalidArgumentException('The approved SMS import plan could not be verified.'); }
    }
}
