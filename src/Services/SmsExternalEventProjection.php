<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read-only projection of a separately verified historical event. */
final class SmsExternalEventProjection
{
    public static function header(object $run): object
    {
        if (! SmsRecordPolicy::isExternal($run)) throw new HttpException(409, 'This is not an external SMS event.');
        $db = DB::connection('sqlsrv');
        if (! $db->getSchemaBuilder()->hasTable('TblSMSExternalEvents')) throw new HttpException(503, 'External SMS event metadata is unavailable.');
        $header = $db->table('TblSMSExternalEvents')->where('Request_ID', $run->Request_ID)->first();
        if ($header === null || ($header->Policy_Version ?? null) !== SmsRecordPolicy::EXTERNAL
            || ! in_array($header->State, ['staging','verified','registering','held','completed'], true)
            || ! in_array($run->Status, ['external_registering','external_sent'], true)
            || ($run->Run_Options_JSON ?? null) !== null || ($run->Approved_At ?? null) !== null || ($run->Approved_By ?? null) !== null
            || (int) $header->Expected_Rows < 1 || (int) $header->Expected_Rows > SmsRunService::MAX_TARGET
            || (int) $run->Target !== (int) $header->Expected_Rows || (int) $header->Occurred_Precision !== 0
            || (int) $header->Staged_Rows < 0 || (int) $header->Staged_Rows > (int) $header->Expected_Rows
            || (int) $header->Applied_Rows < 0 || (int) $header->Applied_Rows > (int) $header->Staged_Rows) {
            throw new HttpException(409, 'External SMS event metadata does not reconcile.');
        }
        $complete = $header->State === 'completed';
        if (($run->Status === 'external_sent') !== $complete
            || ($complete && ((int) $run->Selected !== (int) $header->Expected_Rows
                || (int) $header->Applied_Rows !== (int) $header->Expected_Rows || empty($header->Completed_At_UTC)))
            || (! $complete && $run->Selected !== null)) throw new HttpException(409, 'External completion receipt does not reconcile.');
        $occurred = self::utc($header->Occurred_At_UTC);
        if (preg_match('/\.0*[1-9]/', (string) $header->Occurred_At_UTC)
            || ($complete && preg_match('/\.0*[1-9]/', (string) ($run->Sent_At ?? '')))) throw new HttpException(409, 'External second-resolution occurrence contains unsupported fractions.');
        if (Carbon::parse($occurred, 'UTC')->setTimezone(SmsDeliveryPolicy::TIMEZONE)->toDateString() !== (string) $header->Business_Date
            || ($complete && self::utc($run->Sent_At ?? null) !== $occurred)) throw new HttpException(409, 'External occurrence receipt changed.');
        return $header;
    }

    public static function assertComplete(object $run): object
    {
        $header = self::header($run);
        if ($header->State !== 'completed') throw new HttpException(409, 'Historical registration is not complete.');
        return $header;
    }

    public static function forRun(object $run): array
    {
        $header = self::header($run);
        try { $file = SmsExternalArtifact::fileView($run, $header); }
        catch (\Throwable $error) { throw new HttpException(409, 'Original artifact metadata does not reconcile.', $error); }
        return ['request_id' => strtolower((string) $run->Request_ID), 'status' => $run->Status,
            'selection_policy' => SmsRecordPolicy::EXTERNAL, 'target' => (int) $header->Expected_Rows,
            'selected' => $header->State === 'completed' ? (int) $header->Applied_Rows : null, 'options' => null, 'debt' => null,
            'never_texted' => null, 'previously_texted' => null, 'shortfall' => null,
            'approved_at' => null, 'approved_by' => null, 'can_correct_send' => false, 'drops' => self::drops($run), 'handoff' => null,
            'created_at' => self::utc($header->Started_At_UTC), 'created_by' => $header->Registered_By,
            'updated_at' => self::utc($header->Updated_At_UTC), 'sent_at' => $header->State === 'completed' ? self::utc($header->Occurred_At_UTC) : null,
            'sent_by' => null, 'file' => $file, 'file_cleanup_pending' => false, 'failed_step' => null, 'error' => null,
            'busy' => $header->State !== 'completed', 'stalled' => false, 'progress_step' => 'external_registration',
            'external_event' => ['version' => 1, 'business_key' => $header->Event_Key, 'state' => $header->State,
                'occurred_at' => self::utc($header->Occurred_At_UTC), 'timestamp_precision' => 0,
                'registered_at' => self::utc($header->Started_At_UTC), 'registered_by' => $header->Registered_By,
                'reported_submitter' => $header->Reported_Submitter ?? null, 'expected' => (int) $header->Expected_Rows,
                'staged' => (int) $header->Staged_Rows, 'applied' => (int) $header->Applied_Rows,
                'original_tier_known' => false, 'original_source_known' => false, 'error_code' => $header->Error_Code ?? null]];
    }

    public static function details(object $run): array
    {
        $header = self::assertComplete($run);
        $rows = DB::connection('sqlsrv')->table('TblSMSHistory as h')->leftJoin('TblProspects as p', 'p.PK', '=', 'h.Prospect_PK')
            ->where('h.Request_ID', $run->Request_ID)->groupBy('h.Debt_Tier')->selectRaw('h.Debt_Tier,COUNT(*) AS Records,
                COALESCE(SUM(CASE WHEN p.Opt_Out=1 THEN 1 ELSE 0 END),0) AS Opt_Out,
                COALESCE(SUM(CASE WHEN p.Invalid_Phone=1 THEN 1 ELSE 0 END),0) AS Invalid')->get();
        $empty = ['records' => 0, 'opt_out' => 0, 'invalid' => 0, 'contacted' => null, 'response_rate' => null];
        // Jude's file had no tier column; tiers assigned later to this run's own drops are reported per tier.
        $tiers = array_fill_keys(array_map(static fn (int $i): string => 'T'.$i, range(1, 9)), $empty);
        $unknown = $empty;
        $total = $empty;
        foreach ($rows as $row) {
            $tier = $row->Debt_Tier === null ? null : (string) $row->Debt_Tier;
            if ($tier !== null && ! isset($tiers[$tier])) throw new HttpException(409, 'External history has an unknown tier.');
            foreach (['records' => 'Records', 'opt_out' => 'Opt_Out', 'invalid' => 'Invalid'] as $key => $column) {
                if ($tier === null) $unknown[$key] += (int) $row->{$column}; else $tiers[$tier][$key] += (int) $row->{$column};
                $total[$key] += (int) $row->{$column};
            }
        }
        if ($total['records'] !== (int) $header->Expected_Rows) throw new HttpException(409, 'External history membership count does not reconcile.');
        return ['request_id' => strtolower((string) $run->Request_ID), 'reporting' => 'Live Status',
            'tiers' => array_map(static fn (string $tier, array $counts): array => ['tier' => $tier] + $counts, array_keys($tiers), $tiers),
            'unknown_tier' => ['tier' => 'Unknown original tier'] + $unknown, 'total' => $total,
            'response_metrics_available' => false, 'response_metrics_reason' => 'Historical registration does not establish contact response attribution.'];
    }

    /** Drops assigned to this event's history later, in the same shape ordinary runs return. */
    private static function drops(object $run): array
    {
        return DB::connection('sqlsrv')->table('TblSMSDrops')->where('Request_ID', $run->Request_ID)->orderBy('Drop_Name')
            ->get(['PK', 'Drop_Name', 'Debt_Tier', 'Amount_Dropped'])
            ->map(fn ($d) => ['PK' => (int) $d->PK, 'Drop_Name' => (string) $d->Drop_Name, 'Debt_Tier' => $d->Debt_Tier, 'Amount_Dropped' => (int) $d->Amount_Dropped])
            ->all();
    }

    private static function utc(mixed $text): string
    {
        if (! is_string($text) || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?Z?$/D', $text)) throw new HttpException(409, 'Invalid external event timestamp.');
        try {
            $date = Carbon::parse($text, 'UTC');
            if ($date->format('Y-m-d H:i:s') !== str_replace('T', ' ', substr($text, 0, 19))) throw new RuntimeException('Invalid calendar timestamp.');
            return $date->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $error) { throw new HttpException(409, 'Invalid external event timestamp.', $error); }
    }
}
