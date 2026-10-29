<?php

declare(strict_types=1);

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsAvailabilityRefresh;
use Cmd\Reports\Services\SmsDebtPolicy;
use Cmd\Reports\Services\SmsRecordPolicy;
use Cmd\Reports\Services\SmsDeliveryPolicy;
use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsRunArtifactFilename;
use Cmd\Reports\Services\SmsTierSelectionSql;
use Cmd\Reports\Services\SmsTierWindowSchema;
use Cmd\Reports\Services\SmsTierWindowSelectionSql;
use Cmd\Reports\Services\SmsLeanHistorySchema;
use Cmd\Reports\Services\SmsSelectionCancellation;
use Cmd\Reports\Services\SmsStatePolicy;
use Cmd\Reports\Services\SmsPhoneNumbersSql;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** PRD record membership. No phone deduplication, household expansion or inferred send date. */
class SmsRecordExportRepository extends SqlSrvRepository
{
    // Stay below SQL Server's 2,100 parameter limit and keep log-throttled
    // approval writes bounded without committing partial campaign history.
    public const APPROVAL_WRITE_PAGE = 1000;

    private function eligible(bool $seekContactPhones = false): Builder
    {
        $sqlServer = $this->connection()->getDriverName() === 'sqlsrv';
        $contactPhone = $sqlServer
            ? "CASE WHEN LEN(c.Phone) = 11 AND LEFT(c.Phone, 1) = '1' THEN SUBSTRING(c.Phone, 2, 10) ELSE c.Phone END"
            : "CASE WHEN length(c.Phone) = 11 AND substr(c.Phone, 1, 1) = '1' THEN substr(c.Phone, 2, 10) ELSE c.Phone END";
        $query = $this->table('TblProspects');
        $wideContacts = (bool) config('sms-exports.wide_phone_contacts_enabled', false);
        $contactRows = static fn (Builder $q): Builder => $wideContacts
            ? $q->fromRaw(SmsPhoneNumbersSql::from('c', '', true))
            : $q->from('TblPhoneNumbers as c');
        if ($seekContactPhones && $sqlServer) {
            // Fixed internal literals let cached plans prove the filtered-index
            // predicate; these flags are never supplied by the caller.
            $query->whereRaw('Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0');
        } else {
            $query->where('Contacted', 0)->where('Opt_Out', 0)->where('Invalid_Phone', 0);
        }
        $query->whereNotNull('Phone')
            // Legacy loaders may leave prospect flags clear despite existing phone exclusions.
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('TblSMSSuppressions as s')
                ->whereColumn('s.Phone', 'TblProspects.Phone')
                ->where(fn (Builder $flags) => $flags->where('s.Opt_Out', 1)->orWhere('s.Invalid_Phone', 1)));
        if ($seekContactPhones && $sqlServer) {
            // Eligible prospects contain exactly ten digits. The legacy CASE
            // therefore matches precisely the native ten-digit or 1+ten-digit
            // contact key (including SQL Server's existing trailing-space rule).
            $query->whereNotExists(fn (Builder $q) => $contactRows($q->selectRaw('1'))
                ->whereColumn('c.Phone', 'TblProspects.Phone'))
                ->whereNotExists(fn (Builder $q) => $contactRows($q->selectRaw('1'))
                    ->whereRaw("c.Phone = '1' + TblProspects.Phone"));
        } else {
            $query->whereNotExists(fn (Builder $q) => $contactRows($q->selectRaw('1'))
                ->whereNotNull('c.Phone')->whereRaw("{$contactPhone} = TblProspects.Phone"));
        }
        SmsStatePolicy::applyEligible($query, 'TblProspects.State');
        if ($sqlServer) {
            return $query->whereRaw("DATALENGTH(Phone)=10 AND Phone NOT LIKE '%[^0-9]%'");
        }
        return $query->whereRaw("length(Phone)=10 AND Phone NOT GLOB '*[^0-9]*'");
    }

    public function availability(): array
    {
        SmsRecordPolicy::assertReady();
        $query = $this->eligible()->selectRaw('COUNT(*) AS Eligible, COALESCE(SUM(CASE WHEN Last_SMS_Date IS NULL THEN 1 ELSE 0 END),0) AS Never_Texted');
        if ($this->connection()->getDriverName() === 'sqlsrv') {
            // The production record aggregate otherwise chose nested-loop anti joins
            // over millions of rows. Keep the same predicates/bindings and first-row
            // shape, without relying on a Query Store hint for one query identity.
            $query->limit(1);
            $row = $this->connection()->selectOne($query->toSql().' OPTION (HASH JOIN)', $query->getBindings());
        } else {
            $row = $query->first();
        }
        $all = (int) $row->Eligible;
        $never = (int) $row->Never_Texted;
        return ['eligible' => $all, 'never_texted' => $never, 'texted_before' => $all - $never];
    }

    public function select(string $requestId, int $target): array
    {
        SmsRecordPolicy::assertReady();
        if ($target < 1 || $target > 10000000) throw new HttpException(422, 'Enter a positive whole-number quantity up to 10,000,000.');
        $initial = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->useWritePdo()->first();
        $options = $initial ? $this->tierSelectionOptions($initial, $target) : null;
        $cancellation = app(SmsSelectionCancellation::class);
        $cancellable = $initial !== null && $cancellation->begin($initial);
        if ($options !== null) {
            if ($this->connection()->getDriverName() !== 'sqlsrv') throw new RuntimeException('Source-tier selection requires SQL Server SNAPSHOT; no alternate selector was substituted.');
            return $this->withQueryTimeout(SmsProspectExportRepository::STEP_QUERY_TIMEOUT,
                fn () => $this->withReadSnapshot(fn (): array => $this->selectTiersNow($requestId, $target, $options, $cancellable ? $cancellation : null)));
        }
        return $this->withQueryTimeout(SmsProspectExportRepository::STEP_QUERY_TIMEOUT, fn () => $this->connection()->transaction(function () use ($requestId, $target): array {
            $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->lockForUpdate()->first();
            if (! $run || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'selecting') throw new HttpException(409, 'This record batch is not selecting.');
            if ($this->tierSelectionOptions($run, $target) !== null) throw new HttpException(409, 'The frozen selector changed before selection; no membership was written.');
            if ($this->table('TblProspectsExport')->where('Request_ID', $requestId)->exists()
                || $this->table('TblSMSHistory')->where('Request_ID', $requestId)->exists()) throw new HttpException(409, 'This batch already has frozen membership.');
            $rows = $this->eligible()->selectRaw("? AS Request_ID, PK AS Prospect_PK, Phone, NULL AS Debt_Tier,
                Client,Address,City,State,Zip,Email,
                CASE WHEN Last_SMS_Date IS NULL THEN 0 ELSE 1 END AS Was_Previously_Texted,
                Last_SMS_Date AS Last_SMS_Date_At_Selection", [$requestId])
                ->orderByRaw('CASE WHEN Last_SMS_Date IS NULL THEN 0 ELSE 1 END ASC')
                ->orderByRaw('CASE WHEN Last_SMS_Date IS NULL THEN Creation_Date END DESC')
                ->orderBy('Last_SMS_Date')->orderBy('PK')->limit($target);
            $this->table('TblProspectsExport')->insertUsing(['Request_ID','Prospect_PK','Phone','Debt_Tier',
                'Client','Address','City','State','Zip','Email','Was_Previously_Texted','Last_SMS_Date_At_Selection'], $rows);
            $counts = $this->composition($requestId);
            $this->table('TblSMSRuns')->where('Request_ID', $requestId)->update(['Never_Texted' => $counts['never_texted'], 'Previously_Texted' => $counts['previously_texted']]);
            return ['selected' => $counts['selected'], 'target' => $target, 'shortfall' => max(0, $target - $counts['selected']), 'contacted_flagged' => 0];
        }));
    }

    /** NULL selector preserves every old optionless/four-key run, including all-tier runs. */
    private function tierSelectionOptions(object $run, int $target): ?array
    {
        if ((int) $run->Target !== $target) throw new HttpException(409, 'Selection quantity differs from its frozen run.');
        $options = SmsRunOptions::fromStored($run->Run_Options_JSON ?? null, $target);
        if ($options !== null && ! isset($options['tier_selection_policy']) && $options['tiers'] !== SmsRunOptions::TIERS) {
            throw new RuntimeException('Unversioned tier subsets cannot be selected.');
        }
        return isset($options['tier_selection_policy']) ? $options : null;
    }

    /** Versioned resolve/filter/priority and frozen insert in one consistent source snapshot. */
    private function selectTiersNow(string $requestId, int $target, array $options, ?SmsSelectionCancellation $cancellation = null): array
    {
        $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->lockForUpdate()->first();
        if (! $run || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'selecting'
            || $this->tierSelectionOptions($run, $target) !== $options) throw new HttpException(409, 'The frozen source-tier batch is not selecting.');
        if ($run->Selected !== null || $this->table('TblProspectsExport')->where('Request_ID', $requestId)->exists()
            || $this->table('TblSMSHistory')->where('Request_ID', $requestId)->exists()) throw new HttpException(409, 'This batch already has frozen membership.');
        $leanDebtSources = SmsRunOptions::usesLeanDebtSources($options['tier_selection_policy']);
        if ($leanDebtSources) SmsLeanHistorySchema::assertReady($this->connection());
        if (SmsRunOptions::isWindowedTierSelectionPolicy($options['tier_selection_policy'])) {
            SmsTierWindowSchema::assertReady($this->connection());
            $this->selectTierWindows($requestId, $target, $options, $cancellation);
        } else {
            $eligible = $this->eligible()->select(['PK','Phone','Client','Address','City','State','Zip','Email','Creation_Date','Last_SMS_Date']);
            $sql = SmsTierSelectionSql::select($eligible->toSql(), $options['tiers'], (string) config('sms-exports.mailer_recency_date', 'Send_Date'), $leanDebtSources);
            $this->connection()->affectingStatement($sql, [...$eligible->getBindings(), $target, $requestId, ...$options['tiers'], SmsDebtPolicy::minimum($options)]);
        }
        $this->assertTierPrices($requestId, $options);
        $cancellation?->checkpoint($requestId);
        $counts = $this->composition($requestId);
        if ($counts['selected'] > $target) throw new RuntimeException('Tier selection exceeded its frozen quantity.');
        $this->table('TblSMSRuns')->where('Request_ID', $requestId)->update([
            // NULL means not selected yet. Zero is a durable completed selection too.
            'Selected' => $counts['selected'], 'Never_Texted' => $counts['never_texted'], 'Previously_Texted' => $counts['previously_texted'],
        ]);
        return ['selected' => $counts['selected'], 'target' => $target, 'shortfall' => max(0, $target - $counts['selected']), 'contacted_flagged' => 0];
    }

    /** Walk the exact priority sequence; rejected/held rows advance the cursor too. */
    private function selectTierWindows(string $requestId, int $target, array $options, ?SmsSelectionCancellation $cancellation = null): void
    {
        $windowSize = (int) config('sms-exports.tier_selection_window_size', 5000);
        if ($windowSize < 1 || $windowSize > 100000) {
            throw new RuntimeException('SMS tier selection window size must be from 1 to 100,000.');
        }
        $db = $this->connection();
        $db->unprepared('DROP TABLE IF EXISTS #SmsTierWanted');
        $db->unprepared('CREATE TABLE #SmsTierWanted (
            Window_Position bigint NOT NULL, Export_PK int NOT NULL, Phone varchar(10) COLLATE database_default NULL,
            Client varchar(150) COLLATE database_default NULL, Address varchar(200) COLLATE database_default NULL, City varchar(100) COLLATE database_default NULL,
            State char(2) COLLATE database_default NULL, Snapshot_Zip varchar(10) COLLATE database_default NULL, Zip varchar(5) COLLATE database_default NULL,
            Email varchar(254) COLLATE database_default NULL, Creation_Date date NULL, Last_SMS_Date date NULL,
            PRIMARY KEY CLUSTERED (Window_Position), UNIQUE (Export_PK)
        )');

        $sql = SmsTierWindowSelectionSql::insert($options['tiers'], (string) config('sms-exports.mailer_recency_date', 'Send_Date'),
            SmsRunOptions::usesLeanDebtSources($options['tier_selection_policy']));
        $selected = 0;
        $noCount = (int) $db->selectOne('SELECT CASE WHEN (@@OPTIONS & 512)=512 THEN 1 ELSE 0 END AS enabled')->enabled;
        $db->unprepared('SET NOCOUNT ON');
        try {
            foreach ([0, 1, 2] as $phase) {
                $cursor = null;
                while ($selected < $target) {
                    $cancellation?->checkpoint($requestId);
                    $db->unprepared('TRUNCATE TABLE #SmsTierWanted');
                    $window = $this->tierWindow($phase, $cursor, $windowSize);
                    $db->table('#SmsTierWanted')->insertUsing([
                        'Window_Position','Export_PK','Phone','Client','Address','City','State',
                        'Snapshot_Zip','Zip','Email','Creation_Date','Last_SMS_Date',
                    ], $window);
                    $last = $db->table('#SmsTierWanted')->orderByDesc('Window_Position')->first([
                        'Export_PK','Creation_Date','Last_SMS_Date',
                    ]);
                    if ($last === null) break;

                    $remaining = $target - $selected;
                    // Read the statement's own count on the server: shared worker
                    // sessions/trigger NOCOUNT settings make PDO rowCount unreliable.
                    $receipt = $db->selectOne($sql.'; SELECT CONVERT(int,@@ROWCOUNT) AS inserted_rows', [$remaining, $requestId, ...$options['tiers'], SmsDebtPolicy::minimum($options)]);
                    $inserted = (int) $receipt->inserted_rows;
                    if ($inserted < 0 || $inserted > $remaining) throw new RuntimeException('Invalid window selection count.');
                    $selected += $inserted;
                    $cursor = $last;
                    $cancellation?->checkpoint($requestId);
                }
                if ($selected >= $target) break;
            }
            $db->unprepared('DROP TABLE #SmsTierWanted');
        } catch (\Throwable $error) {
            // A failed SNAPSHOT transaction rolls its temp DDL back. If it is
            // still committable, clear the connection-local table before reuse.
            try {
                $state = (int) ($db->selectOne('SELECT XACT_STATE() AS state')->state ?? -1);
                if ($state === 1) $db->unprepared('DROP TABLE IF EXISTS #SmsTierWanted');
            } catch (\Throwable) {
                // Preserve the selector exception; the next attempt drops stale temp state first.
            }
            throw $error;
        } finally {
            $db->unprepared('SET NOCOUNT '.($noCount === 1 ? 'ON' : 'OFF'));
        }
    }

    /** Exact priority page with disjoint seek ranges; never rescan a cursor prefix. */
    private function tierWindow(int $phase, ?object $cursor, int $windowSize): Builder
    {
        $query = $this->eligible(true);
        if ($phase < 2) {
            $query->whereNull('Last_SMS_Date');
            if ($phase === 0) $query->whereNotNull('Creation_Date');
            else $query->whereNull('Creation_Date');
            // Separate NULL phase retains SQL Server's original DESC NULL-last order.
            $order = 'Creation_Date DESC, PK ASC';
            $date = 'Creation_Date';
        } else {
            $query->whereNotNull('Last_SMS_Date');
            $order = 'Last_SMS_Date ASC, PK ASC';
            $date = 'Last_SMS_Date';
        }

        if ($cursor !== null && $phase === 1) {
            $query->where('PK', '>', (int) $cursor->Export_PK);
        } elseif ($cursor !== null) {
            // Each branch can seek its own simple (date, PK) range. TOP on
            // both branches bounds the union to 2*windowSize before final TOP.
            // An OR keyset predicate instead became a residual ordered scan
            // and reread millions of already-visited records on every page.
            $same = clone $query;
            $otherDates = clone $query;
            $columns = ['PK','Phone','Client','Address','City','State','Zip','Email','Creation_Date','Last_SMS_Date'];
            $same->where($date, $cursor->$date)->where('PK', '>', (int) $cursor->Export_PK)
                ->select($columns)->orderByRaw($order)->limit($windowSize);
            $otherDates->where($date, $phase === 0 ? '<' : '>', $cursor->$date)
                ->select($columns)->orderByRaw($order)->limit($windowSize);
            $query = $this->connection()->query()->fromSub($same->unionAll($otherDates), 'SmsTierPage');
        }

        return $query->selectRaw("ROW_NUMBER() OVER (ORDER BY {$order}) AS Window_Position,
            PK AS Export_PK,Phone,Client,Address,City,State,Zip AS Snapshot_Zip,LEFT(Zip,5) AS Zip,
            Email,Creation_Date,Last_SMS_Date")->orderByRaw($order)->limit($windowSize);
    }

    /** Validate immutable selected prices, never look up current sources again. */
    public function assertTierPrices(string $requestId, array $options): void
    {
        if (! SmsRunOptions::isTierSelectionPolicy($options['tier_selection_policy'] ?? null)) throw new RuntimeException('An explicit source-tier selector is required.');
        $leanDebtSources = SmsRunOptions::usesLeanDebtSources($options['tier_selection_policy']);
        if ($this->table('TblProspectsExport')->where('Request_ID', $requestId)->where(function (Builder $q) use ($options, $leanDebtSources): void {
            $q->whereNull('Debt_Tier')->orWhereNotIn('Debt_Tier', $options['tiers'])
                ->orWhereNull('Debt_Amount')->orWhere('Debt_Amount', '<', SmsDebtPolicy::minimum($options))
                ->orWhereRaw('Debt_Amount <> CAST(Debt_Amount / 1000 AS bigint) * 1000')
                ->orWhereNull('Debt_Source')->orWhereNotIn('Debt_Source', ['TblTUData','TblMailers','Tier']);
            $q->orWhere(function (Builder $source) use ($leanDebtSources): void {
                if ($leanDebtSources) {
                    $source->whereIn('Debt_Source', ['TblTUData','TblMailers'])
                        ->where(fn (Builder $pk) => $pk->whereNull('Debt_Source_PK')->orWhere('Debt_Source_PK', '<=', 0));
                } else {
                    $source->whereNull('Debt_Source_PK')->orWhere('Debt_Source_PK', '<=', 0);
                }
            });
            if ($leanDebtSources) $q->orWhere(fn (Builder $source) => $source->where('Debt_Source', 'Tier')->whereNotNull('Debt_Source_PK'));
        })->exists()) throw new RuntimeException('Frozen source-tier membership or price provenance does not reconcile.');
        if ($leanDebtSources && $this->connection()->getDriverName() === 'sqlsrv') {
            SmsLeanHistorySchema::assertReady($this->connection());
            foreach (['TblTUData','TblMailers'] as $source) {
                if ($this->table('TblProspectsExport', 'x')->leftJoin($source.' as s', 's.PK', '=', 'x.Debt_Source_PK')
                    ->where('x.Request_ID', $requestId)->where('x.Debt_Source', $source)->whereNull('s.PK')->exists()) {
                    throw new RuntimeException('Frozen '.$source.' provenance no longer resolves to its exact source PK.');
                }
            }
        }
    }

    public function composition(string $requestId): array
    {
        $row = $this->table('TblProspectsExport')->where('Request_ID', $requestId)->selectRaw('COUNT(*) AS Selected,
            COALESCE(SUM(CASE WHEN Was_Previously_Texted=0 THEN 1 ELSE 0 END),0) AS Never_Texted,
            COALESCE(SUM(CASE WHEN Was_Previously_Texted=1 THEN 1 ELSE 0 END),0) AS Previously_Texted')->first();
        return ['selected' => (int) $row->Selected, 'never_texted' => (int) $row->Never_Texted, 'previously_texted' => (int) $row->Previously_Texted];
    }

    /** Validate frozen content and current suppression without changing the saved selection. */
    public function assertFrozen(string $requestId, int $expected, bool $pendingIds = false): void
    {
        $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
        if ($run !== null) SmsRecordPolicy::assertOrdinary($run);
        if ($pendingIds) {
            $stored = $run ? SmsRunOptions::fromStored($run->Run_Options_JSON ?? null, (int) $run->Target) : null;
            if (($stored['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION
                || $this->table('TblSMSDrops')->where('Request_ID', $requestId)->exists()
                || $this->table('TblSMSHistory')->where('Request_ID', $requestId)->exists()) {
                throw new RuntimeException('Pending SMS IDs require the explicit approval workflow and no saved drops/history.');
            }
        }
        if ($run && ($options = $this->tierSelectionOptions($run, (int) $run->Target)) !== null) $this->assertTierPrices($requestId, $options);
        $rows = $this->table('TblProspectsExport')->where('Request_ID', $requestId);
        if ((clone $rows)->count() !== $expected || $expected < 1
            || (clone $rows)->where(function ($q) use ($pendingIds): void {
                if ($pendingIds) $q->whereNotNull('SMS_Drop_PK')->orWhereNotNull('Drop_Name');
                else $q->whereNull('SMS_Drop_PK');
                $q->orWhereNull('Debt_Tier')->orWhereNotIn('Debt_Tier', ['T1','T2','T3','T4','T5','T6','T7','T8','T9'])
                    ->orWhereRaw('Debt_Amount <> CAST(Debt_Amount / 1000 AS bigint) * 1000')
                    ->orWhereNull('Debt_Amount')->orWhere('Debt_Amount', '<=', 0)->orWhereNull('Was_Previously_Texted')->orWhereNotIn('Was_Previously_Texted', [0,1]);
            })->exists()) throw new RuntimeException('Frozen SMS membership, tier, debt or count does not reconcile.');
        $this->assertCurrentStates($requestId);
    }

    /** Compatibility entry point: State, phone identity and authoritative suppressions. */
    public function assertCurrentStates(string $requestId): void
    {
        \Cmd\Reports\Services\SmsFrozenEligibility::assertPending($this->connection(), $requestId);
    }

    /** Allocation is committed separately; every saved tier/member reference must reconcile on replay. */
    public function assertAllocatedDrops(string $requestId, int $expected, array $options): string
    {
        if (($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) throw new RuntimeException('Approval-time SMS allocation requires its explicit version.');
        $this->assertFrozen($requestId, $expected);
        if ($this->table('TblSMSHistory')->where('Request_ID', $requestId)->exists()) throw new RuntimeException('An uncommitted SMS approval cannot already contain history.');
        $bad = $this->table('TblProspectsExport', 'x')->leftJoin('TblSMSDrops as d', 'd.PK', '=', 'x.SMS_Drop_PK')
            ->where('x.Request_ID', $requestId)->where(function (Builder $q) use ($requestId, $options): void {
                $q->whereNull('x.Drop_Name')->orWhereNull('d.PK')->orWhereNull('d.Request_ID')->orWhere('d.Request_ID', '<>', $requestId)
                    ->orWhereNull('d.Debt_Tier')->orWhereColumn('d.Debt_Tier', '<>', 'x.Debt_Tier')
                    ->orWhereColumn('d.Drop_Name', '<>', 'x.Drop_Name')->orWhereNull('d.Drop_Date')->orWhere('d.Drop_Date', '<>', $options['send_date']);
            })->exists();
        if ($bad) throw new RuntimeException('Saved SMS drop allocation does not match its frozen members and planned date.');
        $tiers = $this->table('TblProspectsExport')->where('Request_ID', $requestId)->groupBy('Debt_Tier')->selectRaw('Debt_Tier,COUNT(*) AS Records')->get()->keyBy('Debt_Tier');
        $drops = $this->table('TblSMSDrops')->where('Request_ID', $requestId)->get(['PK','Drop_Name','Debt_Tier','Amount_Dropped']);
        if ($drops->count() !== $tiers->count()) throw new RuntimeException('Saved SMS drop count does not match the frozen tiers.');
        $seen = [];
        foreach ($drops as $drop) {
            if (isset($seen[$drop->Debt_Tier]) || ! isset($tiers[$drop->Debt_Tier]) || (int) $drop->Amount_Dropped !== (int) $tiers[$drop->Debt_Tier]->Records
                || $this->table('TblProspectsExport')->where('Request_ID', $requestId)->where('SMS_Drop_PK', $drop->PK)->count() !== (int) $drop->Amount_Dropped) {
                throw new RuntimeException('Saved SMS tier/drop composition does not reconcile.');
            }
            $seen[$drop->Debt_Tier] = true;
        }
        return SmsRunArtifactFilename::rangeFromDrops($drops->map(static fn ($drop): array => ['Drop_Name' => $drop->Drop_Name])->all());
    }

    /** Stream exact frozen values; no mutable prospect join or post-generation eligibility check. */
    public function writeCsv(string $requestId, string $path): int
    {
        SmsRecordPolicy::assertReady();
        $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
        if (! $run || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'building_file') throw new HttpException(409, 'This record batch is not building a file.');
        $this->assertFrozen($requestId, (int) $run->Selected);
        $file = fopen($path, 'wb');
        if ($file === false) throw new RuntimeException('Unable to open the SMS record export.');
        $count = 0;
        $singlePhone = (bool) config('sms-exports.single_phone_csv', false);
        try {
            if (fputcsv($file, SmsDeliveryPolicy::header($singlePhone), ',', '"', '', "\r\n") === false) throw new RuntimeException('Unable to write the SMS header.');
            $rows = $this->table('TblProspectsExport', 'x')->join('TblSMSDrops as d', 'd.PK', '=', 'x.SMS_Drop_PK')
                ->where('x.Request_ID', $requestId)->orderBy('d.Drop_Name')->orderBy('x.Prospect_PK')
                ->select(['x.Client','x.Address','x.Debt_Amount','x.Phone','d.Drop_Date'])->cursor();
            foreach ($rows as $row) {
                $cells = SmsDeliveryPolicy::row((string) $row->Client, (string) $row->Address,
                    $row->Debt_Amount, (string) $row->Phone, Carbon::parse($row->Drop_Date)->toDateString(), $singlePhone);
                $cells = array_map(fn (string $cell): string => preg_match('/^[=+@\-\t\r\n]/', $cell) ? "'".$cell : $cell, $cells);
                if (fputcsv($file, $cells, ',', '"', '', "\r\n") === false) throw new RuntimeException('Unable to write a frozen SMS record.');
                $count++;
            }
            if (! fflush($file)) throw new RuntimeException('Unable to finish the SMS record export.');
        } finally { fclose($file); }
        $this->assertCurrentStates($requestId);
        return $count;
    }

    /** Stream the immutable record snapshot into one atomic, versioned CSV/ZIP bundle. */
    public function writeVersionedBundle(string $requestId, string $runDirectory, string $stem, array $options): array
    {
        return $this->withQueryTimeout(SmsProspectExportRepository::STEP_QUERY_TIMEOUT, function () use ($requestId, $runDirectory, $stem, $options): array {
            SmsRecordPolicy::assertReady();
            $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
            if (! $run || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'building_file'
                || SmsRunOptions::fromStored($run->Run_Options_JSON ?? null, (int) $run->Target) !== $options) {
                throw new HttpException(409, 'This batch has no matching frozen file options.');
            }
            $pendingIds = ($options['filename_version'] ?? null) === SmsRunOptions::APPROVAL_FILENAME_VERSION;
            $this->assertFrozen($requestId, (int) $run->Selected, $pendingIds);
            $cells = (function () use ($requestId, $options): \Generator {
                $pendingIds = ($options['filename_version'] ?? null) === SmsRunOptions::APPROVAL_FILENAME_VERSION;
                $query = $this->table('TblProspectsExport', 'x')->where('x.Request_ID', $requestId);
                $rows = $pendingIds
                    ? $query->orderBy('x.Debt_Tier')->orderBy('x.Prospect_PK')->select(['x.Client', 'x.Address', 'x.Debt_Amount', 'x.Phone'])->cursor()
                    : $query->join('TblSMSDrops as d', 'd.PK', '=', 'x.SMS_Drop_PK')->orderBy('d.Drop_Name')->orderBy('x.Prospect_PK')
                        ->select(['x.Client', 'x.Address', 'x.Debt_Amount', 'x.Phone', 'd.Drop_Date'])->cursor();
                foreach ($rows as $row) {
                    yield SmsDeliveryPolicy::rowForFormat($options['csv_format'], (string) $row->Client, (string) $row->Address,
                        $row->Debt_Amount, (string) $row->Phone, $pendingIds ? $options['send_date'] : Carbon::parse($row->Drop_Date)->toDateString());
                }
            })();
            $bundle = (new SmsRunArtifactBundle)->build($runDirectory, $stem, $cells, (int) $run->Selected, $options);
            $this->assertCurrentStates($requestId);
            return $bundle;
        });
    }

    /** Called in the service's same approval transaction, with the run row already locked. */
    public function approve(string $requestId, Carbon $approvedAt, int $expected): int
    {
        SmsRecordPolicy::assertReady();
        $db = $this->connection();
        if ($db->transactionLevel() < 1) throw new RuntimeException('Record approval requires the batch transaction.');
        $this->assertFrozen($requestId, $expected);
        $counts = $this->composition($requestId);
        $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
        if (! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approving'
            || (int) $run->Never_Texted !== $counts['never_texted'] || (int) $run->Previously_Texted !== $counts['previously_texted']
            || $counts['never_texted'] + $counts['previously_texted'] !== $expected) throw new RuntimeException('The approved composition differs from the frozen batch.');
        if ($this->table('TblSMSHistory')->where('Request_ID', $requestId)->exists()) throw new RuntimeException('This pending batch already has history; reconcile before retrying.');
        $date = $approvedAt->copy()->setTimezone(SmsProspectExportRepository::TIMEZONE)->toDateString();
        $columns = ['Request_ID','SMS_Drop_PK','Drop_Name','Prospect_PK','Phone','Debt_Amount','Debt_Tier','Debt_Source','Debt_Source_PK',
            'Client','Address','City','State','Zip','Email','Was_Previously_Texted','Last_SMS_Date_At_Selection'];
        $updated = 0;
        $last = null;
        do {
            // Request+prospect is an existing unique key. Never use OFFSET or
            // phone matching, and never create another transaction per page.
            $page = $this->table('TblProspectsExport')->useWritePdo()->where('Request_ID', $requestId);
            if ($last !== null) $page->where('Prospect_PK', '>', $last);
            $ids = $page->orderBy('Prospect_PK')->limit(self::APPROVAL_WRITE_PAGE)->pluck('Prospect_PK')->all();
            if ($ids === []) break;
            $count = count($ids);
            $changed = $this->table('TblProspects')->whereIn('PK', $ids)->update([
                'Last_SMS_Date' => $date, 'Latest_Export_Date' => $date, 'SMS_Attempts' => $db->raw('SMS_Attempts + 1'),
            ]);
            if ($changed !== $count) throw new RuntimeException('Not all frozen prospect IDs could be updated; approval was rolled back.');
            $members = fn (): Builder => $this->table('TblProspectsExport')->where('Request_ID', $requestId)->whereIn('Prospect_PK', $ids);
            $inserted = $this->table('TblSMSHistory')->insertUsing([...$columns,'Export_Date'], $members()
                ->select($columns)->selectRaw('? AS Export_Date', [$approvedAt->copy()->utc()->format('Y-m-d H:i:s')]));
            if ($inserted !== $count || $members()->delete() !== $count) {
                throw new RuntimeException('Frozen SMS approval page counts do not reconcile; approval was rolled back.');
            }
            $updated += $changed;
            $last = $ids[array_key_last($ids)];
        } while ($count === self::APPROVAL_WRITE_PAGE);
        if ($updated !== $expected || $this->table('TblProspectsExport')->where('Request_ID', $requestId)->exists()) {
            throw new RuntimeException('Not all frozen prospect IDs could be moved; approval was rolled back.');
        }
        $tierCounts = $this->table('TblSMSHistory')->where('Request_ID', $requestId)->groupBy('Debt_Tier')->selectRaw('Debt_Tier,COUNT(*) AS Records')->get();
        foreach ($tierCounts as $tier) {
            $drop = $this->table('TblSMSDrops')->where('Request_ID', $requestId)->where('Debt_Tier', $tier->Debt_Tier);
            if ((clone $drop)->count() !== 1) throw new RuntimeException('The approved tier drop does not reconcile.');
            // Keep the planned CSV send date; approval time is stored separately in history.
            $drop->update(['Amount_Dropped' => (int) $tier->Records]);
        }
        if ($this->table('TblSMSHistory')->where('Request_ID', $requestId)->count() !== $expected) throw new RuntimeException('Approved history count does not reconcile.');
        SmsAvailabilityRefresh::afterCommit($db);
        return $updated;
    }

    /** Frozen denominators and live prospect flags. Response metrics await validated attribution. */
    public function details(string $requestId): array
    {
        $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
        if (! $run || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approved') throw new HttpException(409, 'Only approved record batches have this report.');
        $rows = $this->table('TblSMSHistory', 'h')->leftJoin('TblProspects as p', 'p.PK', '=', 'h.Prospect_PK')
            ->where('h.Request_ID', $requestId)->groupBy('h.Debt_Tier')->selectRaw('h.Debt_Tier,COUNT(*) AS Records,
                COALESCE(SUM(CASE WHEN p.Opt_Out=1 THEN 1 ELSE 0 END),0) AS Opt_Out,
                COALESCE(SUM(CASE WHEN p.Invalid_Phone=1 THEN 1 ELSE 0 END),0) AS Invalid,
                COALESCE(SUM(CASE WHEN p.Contacted=1 THEN 1 ELSE 0 END),0) AS Contacted')->get()->keyBy('Debt_Tier');
        $tiers = []; $total = ['records' => 0, 'opt_out' => 0, 'invalid' => 0, 'contacted' => 0];
        for ($i = 1; $i <= 9; $i++) {
            $row = $rows->get('T'.$i);
            $tier = ['tier' => 'T'.$i, 'records' => (int) ($row->Records ?? 0), 'opt_out' => (int) ($row->Opt_Out ?? 0),
                'invalid' => (int) ($row->Invalid ?? 0), 'contacted' => (int) ($row->Contacted ?? 0)];
            foreach (array_keys($total) as $key) $total[$key] += $tier[$key];
            $tier['response_rate'] = null;
            $tiers[] = $tier;
        }
        $total['response_rate'] = null;
        return ['request_id' => strtolower($requestId), 'reporting' => 'Live Status', 'tiers' => $tiers, 'total' => $total,
            'contacted_metrics_available' => true, 'contacted_metrics_basis' => 'current_prospect_flag',
            'response_metrics_available' => false,
            'response_metrics_reason' => 'Current Contacted flags are live prospect status; contact response attribution to this drop is unavailable.'];
    }
}
