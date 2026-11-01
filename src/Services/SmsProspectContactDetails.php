<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Plans a paired post-sync CID/date update. No SQL, flag resets or campaign attribution. */
final class SmsProspectContactDetails
{
    public const MAX_CANDIDATES = 1000;

    /**
     * Candidates must cover every matching Source/CID, not just TOP(1) or a partial page.
     * Each row has source, cid and date (nullable); existingSource is optional for old enrollments.
     */
    public static function plan(?string $existingCid, ?string $existingDate, ?string $existingSource,
        array $candidates, array $allowedSources, bool $coverageComplete = false): array
    {
        if (! $coverageComplete) return self::held('coverage_incomplete');
        if (count($candidates) > self::MAX_CANDIDATES) return self::held('too_many_contacts');
        if (($existingCid !== null && ! self::id($existingCid))
            || ! self::date($existingDate)
            || ($existingSource !== null && ! in_array($existingSource, $allowedSources, true))) {
            return self::held('invalid_existing_details');
        }
        $unique = [];
        foreach ($candidates as $row) {
            if (! is_array($row) || ! is_string($row['source'] ?? null)
                || ! in_array($row['source'], $allowedSources, true)
                || ! is_string($row['cid'] ?? null) || ! self::id($row['cid'])
                || ! array_key_exists('date', $row) || ! self::date($row['date'])) {
                return self::held('invalid_contact_details');
            }
            // Include source and retain exact decimal text, including leading zeros.
            $key = $row['source'].'|'.$row['cid'];
            $value = ['source' => $row['source'], 'cid' => $row['cid'], 'date' => $row['date']];
            if (isset($unique[$key]) && $unique[$key] !== $value) return self::held('conflicting_contact_dates');
            $unique[$key] = $value;
        }
        if ($existingCid === null && $existingDate !== null) return self::held('unpaired_existing_date');
        if ($existingSource !== null && $existingCid === null) return self::held('unpaired_existing_source');
        if ($existingCid !== null) {
            $unique = array_filter($unique, static fn (array $row): bool => $row['cid'] === $existingCid
                && ($existingSource === null || $row['source'] === $existingSource));
        }
        if ($unique === []) return ['state' => 'unchanged', 'reason' => 'no_matching_contact', 'details' => null];
        if (count($unique) !== 1) return self::held('ambiguous_contact');
        $winner = array_values($unique)[0];
        if ($existingDate !== null && $winner['date'] !== null && $existingDate !== $winner['date']) {
            return self::held('existing_date_conflict');
        }
        $winner['date'] ??= $existingDate;
        $state = $existingCid === $winner['cid'] && $existingDate === $winner['date']
            && ($existingSource === null || $existingSource === $winner['source']) ? 'unchanged' : 'ready';
        return ['state' => $state, 'reason' => $winner['date'] === null ? 'source_date_unknown' : null,
            'details' => $winner];
    }

    private static function held(string $reason): array
    {
        return ['state' => 'held', 'reason' => $reason, 'details' => null];
    }

    private static function id(string $value): bool
    {
        return preg_match('/\A[0-9]{1,38}\z/D', $value) === 1;
    }

    private static function date(mixed $value): bool
    {
        if ($value === null) return true;
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1
            || substr($value, 0, 4) === '0000') return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
