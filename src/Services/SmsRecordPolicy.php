<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Explicit, coordinated rollout. Never reinterpret a previously selected phone-based run. */
final class SmsRecordPolicy
{
    public const RECORDS = 'prospect-record-v1';
    public const LEGACY = 'legacy-phone-v1';

    public static function enabled(): bool
    {
        return (bool) config('sms-exports.record_workflow_enabled', false);
    }

    public static function current(): string
    {
        return self::enabled() ? self::RECORDS : self::LEGACY;
    }

    public static function isRecord(object $run): bool
    {
        return ($run->Selection_Policy ?? self::LEGACY) === self::RECORDS;
    }

    public static function assertReady(): void
    {
        if (! self::enabled() || ! config('sms-exports.durable_availability_enabled', false)) {
            throw new HttpException(503, 'Record-based SMS approval requires the coordinated record-workflow and durable-summary rollout.');
        }
        $schema = DB::connection('sqlsrv')->getSchemaBuilder();
        foreach (['TblProspects' => ['Last_SMS_Date', 'Contacted_Date'],
            'TblSMSRuns' => ['Selection_Policy', 'Never_Texted', 'Previously_Texted', 'Approved_At', 'Approved_By', 'File_Cleanup_Path'],
            'TblProspectsExport' => ['Was_Previously_Texted', 'Last_SMS_Date_At_Selection'],
            'TblSMSHistory' => ['Was_Previously_Texted', 'Last_SMS_Date_At_Selection']] as $table => $columns) {
            if (! $schema->hasTable($table) || ! $schema->hasColumns($table, $columns)) {
                throw new HttpException(503, 'Apply the explicit SMS record-approval migration before enabling this workflow.');
            }
        }
        if (! $schema->hasTable('TblSMSResponseAttribution') || ! $schema->hasTable(SmsAvailabilityLedger::TABLE)) {
            throw new HttpException(503, 'SMS record approval requires its attribution and durable-summary tables.');
        }
    }
}
