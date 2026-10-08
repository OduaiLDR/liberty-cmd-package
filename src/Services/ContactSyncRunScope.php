<?php

namespace Cmd\Reports\Services;

/** Private, short-lived child results; never persist credentials or sensitive source proofs. */
final class ContactSyncRunScope
{
    public static function create(string $root): string
    {
        $directory = $root . '/contact-sync-' . bin2hex(random_bytes(16));
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new \RuntimeException('Could not create contact scope directory.');
        }
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Could not create isolated contact scope.');
        return $directory;
    }

    public static function write(string $path, array $result): void
    {
        self::validate($result, (string) ($result['source'] ?? ''));
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || !rename($temporary, $path)) throw new \RuntimeException('Could not publish completed contact scope.');
    }

    public static function read(string $path, string $source): array
    {
        if (!is_file($path)) throw new \RuntimeException("Missing completed {$source} contact scope.");
        $result = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::validate($result, $source);
        return $result;
    }

    public static function cleanup(string $directory): void
    {
        foreach (['LT', 'LDR', 'PLAW'] as $source) {
            foreach (['.json', '.json.tmp'] as $suffix) {
                $path = $directory . '/' . $source . $suffix;
                if (is_file($path)) unlink($path);
            }
        }
        if (is_dir($directory)) rmdir($directory);
    }

    private static function validate(mixed $result, string $source): void
    {
        if (!is_array($result) || !in_array($source, ['LT', 'LDR', 'PLAW'], true)
            || ($result['source'] ?? null) !== $source || ($result['complete'] ?? null) !== true
            || !is_array($result['excluded_ids'] ?? null) || !is_array($result['campaigns'] ?? null)
            || !is_array($result['campaign_hold_ids'] ?? null)
            || !is_array($result['enrollment_changes']['categories'] ?? null)
            || !is_array($result['enrollment_changes']['affiliates'] ?? null)
            || !is_string($result['started_at'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $result['started_at'])) {
            throw new \RuntimeException('Invalid or incomplete contact scope.');
        }
        foreach ($result['excluded_ids'] as $id) self::validateId($id);
        foreach ($result['campaign_hold_ids'] as $id) self::validateId($id);
        foreach (['categories' => 'category', 'affiliates' => 'agent'] as $kind => $field) {
            foreach ($result['enrollment_changes'][$kind] as $change) {
                self::validateId($change['llg_id'] ?? null);
                foreach ($change['related_ids'] ?? [] as $id) self::validateId($id);
                foreach (['client', 'email', 'phone', 'before', $field] as $key) {
                    if (!array_key_exists($key, $change) || (!is_string($change[$key]) && $change[$key] !== null)) {
                        throw new \RuntimeException('Invalid deferred enrollment change.');
                    }
                }
            }
        }
        foreach ($result['campaigns'] as $id => $campaign) {
            self::validateId($id);
            if (!is_string($campaign) || trim($campaign) === '' || strlen($campaign) > 255) {
                throw new \RuntimeException('Invalid verified campaign scope.');
            }
        }
    }

    private static function validateId(mixed $id): void
    {
        if (!is_string($id) || !preg_match('/^LLG-[1-9][0-9]*$/D', $id)) {
            throw new \RuntimeException('Invalid contact scope ID.');
        }
    }
}
