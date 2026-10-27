<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use InvalidArgumentException;

/**
 * Canonical options for NEW runs only; no configuration, persistence or IO.
 *
 * Store the complete result when a new run is created and compare that frozen
 * result on request-ID retries. Missing stored options/format denotes a legacy
 * run: do not normalize historical rows to infer or upgrade their file format.
 */
final class SmsRunOptions
{
    public const MAX_TARGET = 10000000;

    public const CSV_FORMAT = 'sms-four-column-v1';

    public const TIER_SELECTION_POLICY = 'current-source-tier-v1';

    /** Exact-priority keyset windows; v1 remains readable/replayable for frozen runs. */
    public const WINDOWED_TIER_SELECTION_POLICY = 'current-source-tier-windowed-v2';

    /** New lean provenance: TU/mailer keep their PK; Tier deliberately has no second source PK. */
    public const LEAN_WINDOWED_TIER_SELECTION_POLICY = 'current-source-tier-windowed-lean-v3';

    public const TIER_SELECTION_POLICIES = [
        self::TIER_SELECTION_POLICY,
        self::WINDOWED_TIER_SELECTION_POLICY,
        self::LEAN_WINDOWED_TIER_SELECTION_POLICY,
    ];

    public const PLANNED_DATE_VERSION = 'sms-planned-date-v1';

    public const DROP_RANGE_FILENAME_VERSION = 'sms-drop-range-v1';

    public const APPROVAL_FILENAME_VERSION = 'sms-approval-drop-range-v1';

    public const TIERS = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'];

    /**
     * Null optional values mean omitted choices; an explicit empty tier list is
     * invalid. Transport callers must validate integer input before calling.
     *
     * @param array<int, string>|null $tiers
     * @return array{target:int, rows_per_file:int, tiers:list<string>, csv_format:string}
     */
    public static function normalize(int $target, ?int $rowsPerFile = null, ?array $tiers = null, ?string $tierSelectionPolicy = null, ?string $optionsVersion = null, ?string $sendDate = null, ?string $filenameVersion = null): array
    {
        if ($filenameVersion !== null && (! in_array($filenameVersion, [self::DROP_RANGE_FILENAME_VERSION, self::APPROVAL_FILENAME_VERSION], true) || $optionsVersion !== self::PLANNED_DATE_VERSION)) {
            throw new InvalidArgumentException('SMS drop-range filenames require the explicit supported filename and planned-date versions.');
        }
        if (($optionsVersion !== null || $sendDate !== null)
            && ($optionsVersion !== self::PLANNED_DATE_VERSION || $sendDate === null)) {
            throw new InvalidArgumentException('A planned SMS date requires the explicit supported options version and frozen send date.');
        }
        if ($sendDate !== null) SmsDeliveryPolicy::plannedDate($sendDate);
        if ($tierSelectionPolicy !== null && ! in_array($tierSelectionPolicy, self::TIER_SELECTION_POLICIES, true)) {
            throw new InvalidArgumentException('Unknown SMS source-tier selection policy.');
        }
        if ($target < 1 || $target > self::MAX_TARGET) {
            throw new InvalidArgumentException('Target must be from 1 to 10,000,000 records.');
        }

        $rowsPerFile ??= $target;
        if ($rowsPerFile < 1 || $rowsPerFile > self::MAX_TARGET) {
            throw new InvalidArgumentException('Rows per file must be from 1 to 10,000,000 records.');
        }

        $tiers ??= self::TIERS;
        if (! array_is_list($tiers) || $tiers === []) {
            throw new InvalidArgumentException('Choose a nonempty list of tiers from T1 to T9.');
        }
        $seen = [];
        foreach ($tiers as $tier) {
            if (! is_string($tier) || ! in_array($tier, self::TIERS, true)) {
                throw new InvalidArgumentException('Choose only tiers from T1 to T9.');
            }
            if (isset($seen[$tier])) {
                throw new InvalidArgumentException('Each tier may be selected only once.');
            }
            $seen[$tier] = true;
        }

        $options = [
            'target' => $target,
            'rows_per_file' => $rowsPerFile,
            'tiers' => array_values(array_filter(self::TIERS, static fn (string $tier): bool => isset($seen[$tier]))),
            'csv_format' => self::CSV_FORMAT,
        ];
        // Never add a default selector to old four-key options or their hashes.
        if ($tierSelectionPolicy !== null) $options['tier_selection_policy'] = $tierSelectionPolicy;
        if ($optionsVersion !== null) {
            $options['options_version'] = $optionsVersion;
            $options['send_date'] = $sendDate;
        }
        if ($filenameVersion !== null) $options['filename_version'] = $filenameVersion;
        return $options;
    }

    /** NULL alone means legacy. Corrupt/incomplete stored options never acquire defaults. */
    public static function fromStored(?string $json, int $target): ?array
    {
        if ($json === null) return null;
        try {
            $stored = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new InvalidArgumentException('Stored SMS run options are invalid.', 0, $error);
        }
        $keys = ['target', 'rows_per_file', 'tiers', 'csv_format'];
        if (is_array($stored) && array_key_exists('tier_selection_policy', $stored)) {
            $keys[] = 'tier_selection_policy';
            if (! in_array($stored['tier_selection_policy'], self::TIER_SELECTION_POLICIES, true)) {
                throw new InvalidArgumentException('Stored SMS selector version is invalid.');
            }
        }
        if (is_array($stored) && (array_key_exists('options_version', $stored) || array_key_exists('send_date', $stored))) {
            $keys[] = 'options_version';
            $keys[] = 'send_date';
            if (($stored['options_version'] ?? null) !== self::PLANNED_DATE_VERSION || ! is_string($stored['send_date'] ?? null)) {
                throw new InvalidArgumentException('Stored SMS planned-date version or date is invalid.');
            }
        }
        if (is_array($stored) && array_key_exists('filename_version', $stored)) {
            $keys[] = 'filename_version';
            if (! in_array($stored['filename_version'], [self::DROP_RANGE_FILENAME_VERSION, self::APPROVAL_FILENAME_VERSION], true)) throw new InvalidArgumentException('Stored SMS filename version is invalid.');
        }
        if (! is_array($stored) || array_keys($stored) !== $keys
            || ! is_int($stored['target']) || $stored['target'] !== $target
            || ! is_int($stored['rows_per_file']) || ! is_array($stored['tiers'])
            || $stored['csv_format'] !== self::CSV_FORMAT) {
            throw new InvalidArgumentException('Stored SMS run options are incomplete or inconsistent.');
        }
        $canonical = self::normalize($target, $stored['rows_per_file'], $stored['tiers'], $stored['tier_selection_policy'] ?? null,
            $stored['options_version'] ?? null, $stored['send_date'] ?? null, $stored['filename_version'] ?? null);
        if ($stored !== $canonical) throw new InvalidArgumentException('Stored SMS run options are not canonical.');
        return $canonical;
    }

    public static function isTierSelectionPolicy(mixed $policy): bool
    {
        return is_string($policy) && in_array($policy, self::TIER_SELECTION_POLICIES, true);
    }

    public static function isWindowedTierSelectionPolicy(mixed $policy): bool
    {
        return in_array($policy, [self::WINDOWED_TIER_SELECTION_POLICY, self::LEAN_WINDOWED_TIER_SELECTION_POLICY], true);
    }

    public static function usesLeanDebtSources(mixed $policy): bool
    {
        return $policy === self::LEAN_WINDOWED_TIER_SELECTION_POLICY;
    }
}
