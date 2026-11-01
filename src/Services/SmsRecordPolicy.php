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

    public const EXTERNAL = 'prospect-external-sent-v1';

    public static function policy(object $run): string
    {
        $policy = $run->Selection_Policy ?? self::LEGACY;
        if (! in_array($policy, [self::LEGACY, self::RECORDS, self::EXTERNAL], true)) {
            throw new HttpException(409, 'Unknown SMS run policy; no workflow action was performed.');
        }
        return $policy;
    }

    public static function isExternal(object $run): bool { return self::policy($run) === self::EXTERNAL; }

    public static function isLegacy(object $run): bool { return self::policy($run) === self::LEGACY; }

    public static function assertOrdinary(object $run): void
    {
        if (self::isExternal($run)) throw new HttpException(409, 'Historical external events are immutable and cannot use ordinary SMS workflow actions.');
    }

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
        return self::policy($run) === self::RECORDS;
    }

    public static function assertReady(): void
    {
        if (! self::enabled() || ! config('sms-exports.durable_availability_enabled', false)) {
            throw new HttpException(503, 'Record-based SMS approval requires the coordinated record-workflow and durable-summary rollout.');
        }
        $db = DB::connection('sqlsrv');
        $schema = $db->getSchemaBuilder();
        SmsProspectSchema::assertResponseReady($db);
        if (SmsProspectSchema::responseEnabled() && config('sms-exports.contact_attribution_enabled', false)
            && ! $schema->hasColumn('TblProspects', 'Contacted_Date')) {
            throw new HttpException(503, 'Original-contact attribution requires its separate Contacted_Date evidence column.');
        }
        foreach (['TblProspects' => SmsProspectSchema::recordColumns(),
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
