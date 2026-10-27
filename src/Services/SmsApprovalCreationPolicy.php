<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Creation fence only; an existing run keeps its saved workflow on recovery. */
final class SmsApprovalCreationPolicy
{
    public static function enabled(): bool
    {
        return (bool) config('sms-exports.approval_only_creation_enabled', false);
    }

    public static function assertNewRun(?array $options): void
    {
        if (! self::enabled()) return;
        SmsRecordPolicy::assertReady();
        if (($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION
            || ($options['options_version'] ?? null) !== SmsRunOptions::PLANNED_DATE_VERSION
            || ($options['csv_format'] ?? null) !== SmsRunOptions::CSV_FORMAT) {
            throw new HttpException(409, 'New SMS batches require the approval workflow. Refresh the SMS page before creating a batch.');
        }
    }

    public static function assertLegacyRegistrationAllowed(): void
    {
        if (self::enabled()) {
            throw new HttpException(409, 'Legacy SMS export registration is closed. Create an approval-workflow batch; existing artifacts remain available through history.');
        }
    }
}
