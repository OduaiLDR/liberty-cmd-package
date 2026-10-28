<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Internal original-event writer only. There is deliberately no HTTP endpoint,
 * Snowflake reader, scheduler or response-metrics activation in this prerequisite.
 */
final class SmsContactAttribution
{
    public const MAX_MEMBERSHIPS = 1000;
    public const EVIDENCE_COLUMNS = ['Contact_At_UTC_Exact', 'Source_System', 'Source_Contact_ID',
        'Source_Contract_SHA256', 'Source_Candidate_Count'];

    public static function enabled(): bool
    {
        return (bool) config('sms-exports.contact_attribution_enabled', false);
    }

    /** All accepted approval timestamps/publications use the same outer lock as attribution. */
    public static function coordinate(callable $work): mixed
    {
        if (! self::enabled()) return $work(null);
        $session = DB::connection('sqlsrv')->getPdo();
        return SmsWorkflowLock::run('contact-attribution', static function () use ($session, $work): mixed {
            self::assertPinnedSession($session);
            return $work($session);
        });
    }

    /** Laravel may reconnect while beginning a transaction; the new session has no applock. */
    public static function assertPinnedSession(?\PDO $session): void
    {
        if ($session !== null && DB::connection('sqlsrv')->getPdo() !== $session) {
            throw new RuntimeException('The SMS coordination session changed; retry without publishing contact or approval changes.');
        }
    }

    public static function assertReady(): void
    {
        if (! self::enabled()) throw new HttpException(503, 'Original-contact attribution is not enabled.');
        SmsRecordPolicy::assertReady();
        $system = config('sms-exports.contact_source_system');
        $hash = config('sms-exports.contact_source_contract_sha256');
        if (! is_string($system) || ! preg_match('/\A[a-z][a-z0-9._-]{0,39}\z/', $system)
            || ! is_string($hash) || ! preg_match('/\A[0-9a-f]{64}\z/', $hash)) {
            throw new HttpException(503, 'Verify and configure the original-contact source contract before activation.');
        }
        $db = DB::connection('sqlsrv');
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasColumn('TblProspects', 'Contacted_Date')) {
            throw new HttpException(503, 'Original-contact attribution requires its separate Contacted_Date evidence column.');
        }
        if (! $schema->hasColumn('TblSMSRuns', 'Approved_At_Precision')
            || ! $schema->hasColumns('TblSMSResponseAttribution', self::EVIDENCE_COLUMNS)) {
            throw new HttpException(503, 'Apply the explicit contact-attribution evidence migration before activation.');
        }
        foreach (['TblSMSHistory', 'TblProspectsExport'] as $table) {
            $matches = array_filter($schema->getIndexes($table), static fn (array $index): bool =>
                strtolower($index['name']) === strtolower('IX_'.$table.'_Contact_Attribution')
                && array_map('strtolower', $index['columns']) === ['prospect_pk', 'phone', 'request_id']);
            if (count($matches) !== 1) {
                throw new HttpException(503, 'Original-contact attribution requires its reviewed lookup indexes.');
            }
        }
    }

    /** One bounded, atomic event. A future verified source worker must supply complete original-event coverage. */
    public function record(SmsContactEvent $event): array
    {
        self::assertReady();
        if ($event->sourceSystem !== config('sms-exports.contact_source_system')
            || ! hash_equals(config('sms-exports.contact_source_contract_sha256'), $event->sourceContractSha256)) {
            return $this->hold('source_contract_mismatch');
        }
        if (! $event->coverageComplete) return $this->hold('source_coverage_incomplete');
        if ($event->candidateCount !== 1) return $this->hold('ambiguous_original_contact');
        $now = Carbon::now('UTC');
        if ($event->utcExact > SmsContactEvent::canonicalUtc($now->format('Y-m-d\TH:i:s.u\Z'))) {
            return $this->hold('future_original_contact');
        }
        $db = DB::connection('sqlsrv');
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Original-contact attribution must own its writer transaction.');
        }

        return self::coordinate(fn (?\PDO $session) => $db->transaction(function () use ($db, $event, $now, $session): array {
            self::assertPinnedSession($session);
            // A partial backdated registration may not have reached this exact PK
            // yet. Keep its original contact retryable instead of persisting NULL.
            if (SmsExternalEventService::pending()) return $this->hold('historical_registration_in_progress');
            // The outer coordination lock precedes both approval run locks and
            // prospect locks. Do not acquire an approval lock after this row lock.
            $prospect = $db->table('TblProspects')->useWritePdo()->where('PK', $event->prospectPk)->lockForUpdate()->first();
            if ($prospect === null) return $this->hold('prospect_missing');
            $existing = $db->table('TblSMSResponseAttribution')->useWritePdo()->where('Prospect_PK', $event->prospectPk)->first();
            if ($existing !== null) return ['outcome' => 'already_recorded', 'request_id' => $existing->Request_ID,
                'contacted_date' => $existing->Contacted_Date];
            if (in_array($prospect->Contacted, [1, '1', true], true) || $prospect->Contacted_Date !== null) {
                return ['outcome' => 'already_contacted', 'request_id' => null];
            }
            if (! in_array($prospect->Contacted, [0, '0', false], true)) return $this->hold('contact_state_unknown');
            if ($prospect->Phone !== $event->phone) return $this->hold('current_phone_changed');

            $approvedRows = $pendingRows = [];
            foreach (['TblSMSHistory', 'TblProspectsExport'] as $table) {
                // Do not filter out unproven metadata with an inner join. There
                // is intentionally no run FK in the legacy membership tables.
                $rows = $db->table($table.' as m')->useWritePdo()
                    ->leftJoin('TblSMSRuns as r', 'r.Request_ID', '=', 'm.Request_ID')
                    ->where('m.Prospect_PK', $event->prospectPk)->where('m.Phone', $event->phone)
                    ->limit(self::MAX_MEMBERSHIPS + 1)
                    ->get(['r.Request_ID', 'r.Selection_Policy', 'r.Status', 'r.Failed_Step', 'r.Approved_At', 'r.Approved_At_Precision',
                        ...($table === 'TblSMSHistory' ? ['m.Export_Date AS History_Export_Date'] : [])]);
                if ($rows->count() > self::MAX_MEMBERSHIPS) return $this->hold('membership_limit_exceeded');
                foreach ($rows as $row) {
                    if ($row->Request_ID === null
                        || ! in_array($row->Selection_Policy, [SmsRecordPolicy::RECORDS, SmsRecordPolicy::LEGACY, SmsRecordPolicy::EXTERNAL], true)) {
                        return $this->hold('unproven_membership_metadata');
                    }
                    if ($row->Selection_Policy === SmsRecordPolicy::LEGACY) continue;
                    if ($row->Selection_Policy === SmsRecordPolicy::EXTERNAL) {
                        if ($table !== 'TblSMSHistory' || $row->Status !== 'external_sent'
                            || ! $db->getSchemaBuilder()->hasTable(SmsExternalEventService::MEMBERS)) return $this->hold('unproven_external_history');
                        $external=$db->table(SmsExternalEventService::EVENTS)->where('Request_ID',$row->Request_ID)->first();
                        $member=$db->table(SmsExternalEventService::MEMBERS)->where('Request_ID',$row->Request_ID)
                            ->where('Prospect_PK',$event->prospectPk)->where('Original_Phone',$event->phone)->first();
                        if (! $external || ! $member || $external->State!=='completed'
                            || $external->Policy_Version!==SmsRecordPolicy::EXTERNAL || (int)$external->Occurred_Precision!==0
                            || $row->Approved_At!==null || $member->Applied_Chunk===null
                            || substr((string)$row->History_Export_Date,0,19)!==substr((string)$external->Occurred_At_UTC,0,19)) {
                            return $this->hold('unproven_external_history');
                        }
                        // The ordering helper's internal clock field is shared; origin
                        // and explicit second precision retain historical provenance.
                        $approvedRows[]=['request_id'=>strtolower((string)$row->Request_ID),
                            'approved_at'=>(string)$external->Occurred_At_UTC,'precision'=>0,'origin'=>'external'];
                        continue;
                    }
                    if ($row->Status === 'discarded') continue;
                    $candidate = ['request_id' => strtolower((string) $row->Request_ID), 'approved_at' => $row->Approved_At,
                        'precision' => $row->Approved_At_Precision === null ? null : (int) $row->Approved_At_Precision];
                    if ($table === 'TblSMSHistory') {
                        if ($row->Status !== 'approved') return $this->hold('unproven_history_state');
                        $approvedRows[] = $candidate;
                    } elseif ($row->Status === 'approving' || ($row->Status === 'failed' && $row->Failed_Step === 'approve')) {
                        $pendingRows[] = $candidate;
                    } elseif ($row->Approved_At !== null
                        || ! in_array($row->Status, ['queued', 'selecting', 'review', 'building_file', 'file_ready', 'failed'], true)) {
                        return $this->hold('unproven_pending_state');
                    }
                }
            }
            $plan = SmsContactAttributionPlan::choose($event, $approvedRows, $pendingRows);
            if ($plan['state'] !== 'ready') return $this->hold($plan['reason']);
            // Insert nullable attribution even before every drop. Later campaigns
            // must not steal an already observed pre-campaign original contact.
            $db->table('TblSMSResponseAttribution')->insert([
                'Prospect_PK' => $event->prospectPk, 'Request_ID' => $plan['request_id'],
                'Contact_At_UTC' => $event->utcSql, 'Contact_At_UTC_Exact' => $event->utcExact,
                'Contacted_Date' => $event->californiaDate, 'Created_At' => $now->format('Y-m-d H:i:s.u'),
                'Source_System' => $event->sourceSystem, 'Source_Contact_ID' => $event->sourceContactId,
                'Source_Contract_SHA256' => $event->sourceContractSha256, 'Source_Candidate_Count' => $event->candidateCount,
            ]);
            $changed = $db->table('TblProspects')->where('PK', $event->prospectPk)->where('Phone', $event->phone)
                ->where('Contacted', 0)->whereNull('Contacted_Date')->update(['Contacted' => 1, 'Contacted_Date' => $event->californiaDate]);
            if ($changed !== 1) throw new RuntimeException('The original-contact target changed; attribution was rolled back.');
            // Response_Date, Last_SMS_Date, attempts, suppressions and history remain untouched.
            SmsAvailabilityRefresh::afterCommit($db);
            return ['outcome' => 'recorded', 'request_id' => $plan['request_id'], 'contacted_date' => $event->californiaDate];
        }));
    }

    private function hold(string $reason): array
    {
        return ['outcome' => 'held', 'reason' => $reason, 'request_id' => null];
    }
}
