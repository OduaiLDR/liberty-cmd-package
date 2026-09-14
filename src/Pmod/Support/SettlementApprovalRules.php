<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

final class SettlementApprovalRules
{
    public const ACCEPTED_STATUS_ID = '7';
    public const LDR_OUT_OF_TIMESTAMP_STATUS_ID = '2590';

    /** @var list<string> */
    public const LDR_ELIGIBLE_STATUS_IDS = ['2075', '2093'];

    public static function isLdrEligibleStatus(string $statusId): bool
    {
        return in_array($statusId, self::LDR_ELIGIBLE_STATUS_IDS, true);
    }

    /**
     * Path A = complete to Accepted. Path B = Accepted out of timestamp.
     * Same calendar day is in timestamp. Missing dates cannot be auto-completed.
     */
    public static function path(?string $approvalYmd, ?string $validUntilYmd, ?string $startYmd): ?string
    {
        if ($approvalYmd === null || $validUntilYmd === null || $startYmd === null) {
            return null;
        }

        if ($approvalYmd > $validUntilYmd) {
            return 'B';
        }

        if ($approvalYmd > $startYmd) {
            return 'B';
        }

        return 'A';
    }

    public static function failedCheck(?string $approvalYmd, ?string $validUntilYmd, ?string $startYmd): ?string
    {
        if ($approvalYmd === null || $validUntilYmd === null || $startYmd === null) {
            return 'missing_dates';
        }

        if ($approvalYmd > $validUntilYmd) {
            return 'valid_until';
        }

        if ($approvalYmd > $startYmd) {
            return 'start_date';
        }

        return null;
    }

    public static function daysPast(string $approvalYmd, string $comparedYmd): int
    {
        $approval = \DateTimeImmutable::createFromFormat('Y-m-d', $approvalYmd);
        $compared = \DateTimeImmutable::createFromFormat('Y-m-d', $comparedYmd);

        if ($approval === false || $compared === false) {
            return 0;
        }

        return (int) $compared->diff($approval)->format('%r%a');
    }
}
