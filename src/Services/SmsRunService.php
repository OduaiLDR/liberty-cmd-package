<?php

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Cmd\Reports\Jobs\SmsRunStepJob;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The Mail Drop Export page's SMS runs (TblSMSRuns). A run moves:
 *
 *   queued → selecting → review → building_file → file_ready → sending → sent
 *
 * and can be discarded until it is sent. A failed step keeps what finished and can be retried.
 * Long steps run on the tu-export queue; every status change is conditional on the status it
 * expects, so a double click or two people cannot run a step twice.
 */
class SmsRunService
{
    public const BUSY = ['queued', 'selecting', 'building_file', 'sending', 'reversing', 'approving', 'external_registering'];

    public const CLOSED = ['sent', 'discarded', 'review_only', 'not_sent', 'approved', 'external_sent'];

    public const MAX_TARGET = 10000000;

    /** A busy step with no progress for this long is shown as stuck and can be retried. */
    public const STALLED_AFTER_MINUTES = 120;

    public function __construct(protected SmsProspectExportRepository $export) {}

    public function start(string $requestId, int $target, string $actor, ?array $fileOptions = null): object
    {
        if (SmsRecordPolicy::enabled()) SmsRecordPolicy::assertReady();
        if ($target < 1 || $target > self::MAX_TARGET) {
            throw ValidationException::withMessages(['target' => 'Enter a number of records from 1 to 10,000,000.']);
        }
        $existing = $this->run($requestId);
        if ($existing !== null) SmsRecordPolicy::assertOrdinary($existing);
        $options = $this->normalizeFileOptions($target, $fileOptions, $existing);
        if ($existing) {
            $this->assertSameStart($existing, $target, $options);
            return $this->show($requestId);
        }
        SmsApprovalCreationPolicy::assertNewRun($options);
        if ($options !== null) $this->assertNewFileOptionsReady($options);
        if (isset($options['send_date'])) {
            try { SmsDeliveryPolicy::assertNotPast($options['send_date']); }
            catch (\InvalidArgumentException $error) { throw ValidationException::withMessages(['send_date' => $error->getMessage()]); }
        }
        $created = SmsWorkflowLock::run('new-run', fn () => DB::connection('sqlsrv')->transaction(function () use ($requestId, $target, $actor, $options): string {
            // Another identical POST may have completed while this caller waited for the lock.
            $existing = $this->runs()->where('Request_ID', $requestId)->lockForUpdate()->first();
            if ($existing) { SmsRecordPolicy::assertOrdinary($existing); $this->assertSameStart($existing, $target, $options); return 'existing'; }
            SmsApprovalCreationPolicy::assertNewRun($options);
            if (SmsExternalEventService::pending()) throw new HttpException(409, 'Finish the historical SMS registration before starting another campaign.');
            if (! SmsRecordPolicy::enabled() && SmsExternalEventService::exists()) throw new HttpException(409, 'Historical exact-record sends require the record workflow; legacy phone-based creation is unavailable.');
            $open = $this->runs()->whereNotIn('Status', self::CLOSED)->lockForUpdate()->first();
            if ($open) return 'busy';
            $now = $this->now();
            $values = ['Request_ID' => $requestId, 'Target' => $target, 'Status' => 'queued',
                'Created_By' => $actor, 'Created_At' => $now, 'Updated_At' => $now];
            if (SmsRecordPolicy::enabled()) $values['Selection_Policy'] = SmsRecordPolicy::RECORDS;
            if ($options !== null) $values['Run_Options_JSON'] = json_encode($options, JSON_THROW_ON_ERROR);
            $this->runs()->insert($values);
            return 'created';
        }));
        if ($created === 'busy') {
            throw new HttpException(409, 'Another SMS run is still open. Finish or discard it before starting a new one.');
        }
        if ($created === 'created') $this->dispatch($requestId, 'prepare');

        return $this->show($requestId);
    }

    /** Select, look up debts and create the SMS drops. Each part resumes where it stopped. */
    public function prepare(string $requestId): void
    {
        SmsWorkflowLock::run('run:'.$requestId, fn () => $this->prepareNow($requestId));
    }

    protected function prepareNow(string $requestId): void
    {
        $run = $this->mustRun($requestId);
        $this->assertPolicyReady($run);
        $options = $this->fileOptions($run);
        if ($run->Status !== 'queued') return;
        if (isset($options['send_date'])) SmsDeliveryPolicy::assertNotPast($options['send_date']);
        $cancellation = app(SmsSelectionCancellation::class);
        try {
            $cancellable = $cancellation->begin($run);
            $this->prepareQueuedSelection($requestId, $run, $options, $cancellation, $cancellable);
        } catch (SmsSelectionCancelledException) {
            // The repository's source snapshot has unwound before this terminal cleanup.
            $cancellation->complete($requestId);
        }
    }

    private function prepareQueuedSelection(string $requestId, object $run, ?array $options,
        SmsSelectionCancellation $cancellation, bool $cancellable): void
    {
        $checkpoint = static function () use ($cancellation, $cancellable, $requestId): void {
            if ($cancellable) $cancellation->checkpoint($requestId);
        };
        $sourceFirst = SmsRunOptions::isTierSelectionPolicy($options['tier_selection_policy'] ?? null);
        if (! $this->move($requestId, ['queued'], 'selecting', ['Progress_Step' => 'contacts_and_selection'])) return;
        $checkpoint();
        $hasRows = DB::connection('sqlsrv')->table('TblProspectsExport')->where('Request_ID', $requestId)->exists();
        $selectionCommitted = $sourceFirst && $this->mustRun($requestId)->Selected !== null;
        if ($sourceFirst && $hasRows && ! $selectionCommitted) throw new RuntimeException('Source-tier membership has no atomic completion count; reconcile before retrying.');
        if (! $hasRows && ! $selectionCommitted) {
            $this->export->select($requestId, (int) $this->run($requestId)->Target);
        }
        $checkpoint();
        $selected = DB::connection('sqlsrv')->table('TblProspectsExport')->where('Request_ID', $requestId)->count();
        if ($sourceFirst) {
            $completed = $this->mustRun($requestId)->Selected;
            if ($completed === null || (int) $completed !== $selected) throw new RuntimeException('Source-tier membership differs from its committed selection count.');
        }
        $this->move($requestId, ['selecting'], 'selecting', ['Selected' => $selected, 'Progress_Step' => 'tu_debts']);
        $counts = ['Selected' => $selected, 'Debt_TU' => 0, 'Debt_Mailers' => 0, 'Debt_Tier_Default' => 0, 'Debt_Missing' => 0];
        if ($selected > 0) {
            if ($sourceFirst) {
                // Prices/provenance were frozen in the same source-filtered selection.
                // Do not reread mutable TU/mailer sources after membership was committed.
                app(SmsRecordExportRepository::class)->assertTierPrices($requestId, $options);
            } else {
                $this->export->lookupDebts($requestId, [], function (string $step) use ($requestId): void {
                    $this->move($requestId, ['selecting'], 'selecting', ['Progress_Step' => $step.'_debts']);
                });
            }
            $checkpoint();
            if (! $this->approvalFilenames($options)) {
                $this->move($requestId, ['selecting'], 'selecting', ['Progress_Step' => 'numbering_drops']);
                $this->export->assignDrops($requestId, isset($options['send_date'])
                    ? SmsDeliveryPolicy::assertNotPast($options['send_date']) : SmsDeliveryPolicy::followingMonday());
            }
            $sources = DB::connection('sqlsrv')->table('TblProspectsExport')->where('Request_ID', $requestId)
                ->selectRaw("SUM(CASE WHEN Debt_Source = 'TblTUData' THEN 1 ELSE 0 END) AS TU,
                    SUM(CASE WHEN Debt_Source = 'TblMailers' THEN 1 ELSE 0 END) AS Mailers,
                    SUM(CASE WHEN Debt_Source = 'Tier' THEN 1 ELSE 0 END) AS TierDefault,
                    SUM(CASE WHEN Debt_Amount IS NULL THEN 1 ELSE 0 END) AS Missing")->first();
            $counts = ['Selected' => $selected, 'Debt_TU' => (int) $sources->TU, 'Debt_Mailers' => (int) $sources->Mailers,
                'Debt_Tier_Default' => (int) $sources->TierDefault, 'Debt_Missing' => (int) $sources->Missing];
        }
        $checkpoint();
        if ($cancellable) {
            $cancellation->finishSelection($run,
                fn () => $this->publishSelectionReview($requestId, $counts));
        } else {
            $this->move($requestId, ['selecting'], 'review', $counts + ['Progress_Step' => 'review']);
        }
    }

    /** The recovery command shares the same per-run lock as the selection worker. */
    public function completeSelectionCancellation(string $requestId): void
    {
        SmsWorkflowLock::run('run:'.$requestId,
            fn () => app(SmsSelectionCancellation::class)->complete($requestId));
    }

    /** A short conditional update; never wait on a SQL row while holding the MySQL finish fence. */
    private function publishSelectionReview(string $requestId, array $counts): void
    {
        $db = DB::connection('sqlsrv');
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('Complete the source snapshot before publishing review.');
        }
        $native = $db->getDriverName() === 'sqlsrv';
        $pdo = $db->getPdo();
        $timeout = $native ? $pdo->getAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT) : null;
        $lockTimeout = $native ? (int) $db->selectOne('SELECT @@LOCK_TIMEOUT AS value')->value : null;
        if ($native) {
            $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 5);
            $db->unprepared('SET LOCK_TIMEOUT 0');
        }
        try {
            $db->transaction(function () use ($requestId, $counts, $db, $pdo): void {
                if ($db->getPdo() !== $pdo || ! $this->move($requestId, ['selecting'], 'review',
                    $counts + ['Progress_Step' => 'review'])) {
                    throw new RuntimeException('The selecting run changed before review publication.');
                }
            }, 1);
        } finally {
            if ($native) {
                $db->unprepared('SET LOCK_TIMEOUT '.$lockTimeout);
                $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $timeout);
            }
        }
    }

    public function requestFile(string $requestId): object
    {
        $run = $this->mustRun($requestId);
        $this->assertPolicyReady($run);
        if ((int) $run->Selected < 1) throw new HttpException(409, 'This run has no records, so there is no file to make.');
        $options = $this->fileOptions($run);
        if (isset($options['send_date'])) {
            try { SmsDeliveryPolicy::assertNotPast($options['send_date']); }
            catch (\InvalidArgumentException $error) { throw new HttpException(409, $error->getMessage(), $error); }
        }
        if ((int) $run->Debt_Missing > 0) throw new HttpException(409, 'Some records have no positive debt amount. Resolve their tiers/debt before making a file.');
        if (! $this->move($requestId, ['review'], 'building_file')) {
            throw new HttpException(409, 'The file can be made once the run is ready for review.');
        }
        $this->dispatch($requestId, 'file');

        return $this->show($requestId);
    }

    public function buildFile(string $requestId): void
    {
        SmsWorkflowLock::run('run:'.$requestId, fn () => $this->buildFileNow($requestId));
    }

    protected function buildFileNow(string $requestId): void
    {
        $run = $this->mustRun($requestId);
        $this->assertPolicyReady($run);
        if ($run->Status !== 'building_file') return;
        $options = $this->fileOptions($run);
        $dir = $this->runDirectory($requestId);
        // A publication may already exist after a worker died before the SQL receipt.
        // Adopt its original bytes/date, including a later-week recovery; never regenerate it.
        if ($options !== null && is_dir($dir.'/v1/published')) {
            $this->buildVersionedFile($run, $dir, $options);
            return;
        }
        // Do not silently move reviewed drops when generation/retry slips into another week.
        // Ready/downloaded historical files never enter this path.
        $monday = isset($options['send_date'])
            ? SmsDeliveryPolicy::assertNotPast($options['send_date'])->toDateString()
            : SmsDeliveryPolicy::followingMonday()->toDateString();
        if (DB::connection('sqlsrv')->table('TblSMSDrops')->where('Request_ID', $requestId)
            ->where(fn ($q) => $q->whereNull('Drop_Date')->orWhere('Drop_Date', '<>', $monday))->exists()) {
            throw new RuntimeException(isset($options['send_date'])
                ? 'This reviewed batch no longer matches its frozen SMS send date. Existing dates were not changed.'
                : 'This reviewed batch no longer targets the following Monday. Start a fresh batch; existing dates were not changed.');
        }
        if ($options !== null) {
            $this->buildVersionedFile($run, $dir, $options);
            return;
        }
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) throw new RuntimeException('Unable to create the SMS file folder.');
        $final = $dir.'/sms-'.Carbon::today(SmsProspectExportRepository::TIMEZONE)->format('Y-m-d').'.csv';
        $partial = $final.'.partial';
        $count = $this->export->writeCsv($requestId, $partial);
        if ($count !== (int) $this->mustRun($requestId)->Selected) throw new RuntimeException('The file row count does not match the reviewed run. No file was published.');
        if (! rename($partial, $final)) throw new RuntimeException('Unable to save the SMS file.');
        $this->move($requestId, ['building_file'], 'file_ready', [
            'File_Path' => $final, 'File_Bytes' => filesize($final), 'File_Sha256' => hash_file('sha256', $final),
            'File_At' => $this->now(), 'Selected' => $count,
        ]);
    }

    private function buildVersionedFile(object $run, string $directory, array $options): void
    {
        if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasColumns('TblSMSRuns', ['Run_Options_JSON', 'Artifact_Manifest_JSON'])) {
            throw new HttpException(503, 'The frozen SMS file-options schema is incomplete.');
        }
        $date = Carbon::parse($run->Created_At, 'UTC')->setTimezone(SmsProspectExportRepository::TIMEZONE)->format('Y-m-d');
        $stem = isset($options['send_date']) ? 'SMS Export - '.$options['send_date'] : 'sms-'.$date;
        if ($this->approvalFilenames($options)) {
            $stem .= ' - ID Pending';
        } elseif (isset($options['filename_version'])) {
            $bundles = new SmsRunArtifactBundle;
            // Publication is the frozen filename receipt. Recovery does not
            // recalculate a range from mutable sources or a global next counter.
            $stem = is_dir($directory.'/v1/published')
                ? $bundles->publishedStem($directory, $options, (int) $run->Selected)
                : $stem.' - '.SmsRunArtifactFilename::rangeFromDrops($this->export->drops((string) $run->Request_ID));
        }
        $bundle = app(SmsRecordExportRepository::class)->writeVersionedBundle((string) $run->Request_ID, $directory, $stem, $options);
        if (! $this->move((string) $run->Request_ID, ['building_file'], 'file_ready', [
            'File_Path' => $bundle['path'], 'File_Bytes' => $bundle['bytes'], 'File_Sha256' => $bundle['sha256'],
            'Artifact_Manifest_JSON' => json_encode($bundle['manifest'], JSON_THROW_ON_ERROR), 'File_At' => $this->now(),
        ])) {
            // Keep the private publication for reconciliation/retry; the database outcome
            // must never cause a second file with different reviewed content to replace it.
            throw new RuntimeException('The SMS file was published but its run changed before the receipt was saved.');
        }
    }

    public function requestSent(string $requestId, string $actor): object
    {
        $this->assertLegacy($this->mustRun($requestId));
        $this->export->assertCurrentStates($requestId);
        if (! $this->move($requestId, ['file_ready'], 'sending', ['Sent_By' => $actor])) {
            throw new HttpException(409, 'A run can be marked as sent once its file is ready.');
        }
        $this->dispatch($requestId, 'send');

        return $this->show($requestId);
    }

    public function markSent(string $requestId): void
    {
        SmsWorkflowLock::run('run:'.$requestId, function () use ($requestId): void {
            $this->assertLegacy($this->mustRun($requestId));
            if ($this->run($requestId)?->Status !== 'sending') return;
            DB::connection('sqlsrv')->transaction(function () use ($requestId): void {
                $this->export->assertCurrentStates($requestId);
                $sentAt = Carbon::now();
                $this->export->markSent($requestId, $sentAt);
                $this->move($requestId, ['sending'], 'sent', ['Sent_At' => $sentAt->toDateTimeString()]);
            });
        });
    }

    /** Approval time is accepted once, in UTC, before the asynchronous atomic commit. */
    public function requestApprove(string $requestId, string $actor): object
    {
        $preflight = $this->mustRun($requestId);
        SmsRecordPolicy::assertOrdinary($preflight);
        if (SmsExternalEventService::pending()) throw new HttpException(409, 'Historical SMS registration must finish before another approval.');
        $newFiles = $this->approvalFilenames($this->fileOptions($preflight));
        // Slow hashing stays outside SQL/contact coordination. The locked
        // transaction below compares the same immutable publication receipt.
        if ($newFiles && $preflight->Status === 'file_ready') {
            try { SmsDeliveryPolicy::assertNotPast($this->fileOptions($preflight)['send_date']); }
            catch (\InvalidArgumentException $error) { throw new HttpException(409, $error->getMessage(), $error); }
            $this->filePath($requestId);
        }
        $queued = SmsContactAttribution::coordinate(fn (?\PDO $session) => SmsWorkflowLock::run('run:'.$requestId, fn () => DB::connection('sqlsrv')->transaction(function () use ($requestId, $actor, $session, $preflight, $newFiles): bool {
            SmsContactAttribution::assertPinnedSession($session);
            $run = $this->runs()->where('Request_ID', $requestId)->lockForUpdate()->first();
            if (! $run) throw new HttpException(404, 'SMS run not found.');
            $this->assertPolicyReady($run);
            if (! SmsRecordPolicy::isRecord($run)) throw new HttpException(409, 'Legacy runs retain their original send-confirmation workflow.');
            if (in_array($run->Status, ['approving','approved'], true)) return false;
            if ($run->Status !== 'file_ready' || (int) $run->Selected < 1) throw new HttpException(409, 'Approve only a complete, ready record batch.');
            if ($newFiles) $this->assertApprovalReceiptUnchanged($run, $preflight);
            else $this->filePath($requestId);
            app(SmsRecordExportRepository::class)->assertFrozen($requestId, (int) $run->Selected, $newFiles);
            $precise = SmsContactAttribution::enabled();
            if ($precise) SmsContactAttribution::assertReady();
            $at = Carbon::now('UTC')->format($precise ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s');
            if ($newFiles && $this->fileOptions($run)['send_date'] < Carbon::parse($at, 'UTC')->setTimezone(SmsDeliveryPolicy::TIMEZONE)->toDateString()) {
                throw new HttpException(409, 'SMS send date must be today or a future date in California when approval is accepted.');
            }
            $values = ['Approved_By' => $actor, 'Approved_At' => $at, 'Progress_Step' => $newFiles ? 'assigning_ids' : 'approval']
                + ($precise ? ['Approved_At_Precision' => 6] : []);
            if (! $this->move($requestId, ['file_ready'], 'approving', $values)) {
                throw new HttpException(409, 'This batch changed before approval. Refresh it.');
            }
            return true;
        })));
        if ($queued) $this->dispatch($requestId, 'approve');
        return $this->show($requestId);
    }

    public function markApproved(string $requestId): void
    {
        SmsRecordPolicy::assertOrdinary($this->mustRun($requestId));
        if (SmsExternalEventService::pending()) throw new HttpException(409, 'Historical SMS registration must finish before another approval.');
        if ($this->approvalFilenames($this->fileOptions($this->mustRun($requestId)))) {
            $this->markApprovedFiles($requestId);
            return;
        }
        SmsContactAttribution::coordinate(fn (?\PDO $session) => SmsWorkflowLock::run('run:'.$requestId, fn () => DB::connection('sqlsrv')->transaction(function () use ($requestId, $session): void {
            SmsContactAttribution::assertPinnedSession($session);
            $run = $this->runs()->where('Request_ID', $requestId)->lockForUpdate()->first();
            if (! $run || $run->Status === 'approved') return;
            $this->assertPolicyReady($run);
            if (! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approving' || ! $run->Approved_At || ! $run->Approved_By) {
                throw new HttpException(409, 'No accepted record approval is waiting for this batch.');
            }
            // Membership/prices remain frozen; current suppressions can hold the approval.
            $at = Carbon::parse($run->Approved_At, 'UTC');
            app(SmsRecordExportRepository::class)->approve($requestId, $at, (int) $run->Selected);
            if (! $this->move($requestId, ['approving'], 'approved', ['Sent_By' => $run->Approved_By,
                'Sent_At' => $at->format('Y-m-d H:i:s'), 'Progress_Step' => 'approved'])) throw new RuntimeException('Approval status changed; all business changes were rolled back.');
        })));
    }

    /** IDs first, immutable file publication second, business commit last. */
    private function markApprovedFiles(string $requestId): void
    {
        $runSession = DB::connection('sqlsrv')->getPdo();
        $context = SmsWorkflowLock::run('run:'.$requestId, function () use ($requestId, $runSession): ?array {
            SmsContactAttribution::assertPinnedSession($runSession);
            $pending = $this->mustRun($requestId);
            if ($pending->Status === 'approved') return null;
            $this->assertAcceptedFileApproval($pending);
            $options = $this->fileOptions($pending);
            $manifest = $this->artifactManifest($pending);
            $this->verifiedRunArtifact($pending, 'v1');
            // Hold the global allocator lock until this OUTER transaction
            // commits, including when assignDrops starts a nested transaction.
            $range = $this->allocateApprovalDrops($requestId, $pending, $options, $runSession);
            SmsContactAttribution::assertPinnedSession($runSession);
            $bundle = $this->publishApprovedFiles($requestId, $manifest, $options, (int) $pending->Selected, $range);
            return compact('pending', 'options', 'manifest', 'range', 'bundle');
        });
        if ($context === null) return;
        $this->commitApprovedFiles($requestId, $context);
    }

    /** Caller holds the run lock on this exact PDO; allocator lock covers commit. */
    protected function allocateApprovalDrops(string $requestId, object $pending, array $options, \PDO $runSession): string
    {
        SmsContactAttribution::assertPinnedSession($runSession);
        return SmsWorkflowLock::run('drop-numbering', function () use ($requestId, $pending, $options, $runSession): string {
            SmsContactAttribution::assertPinnedSession($runSession);
            return DB::connection('sqlsrv')->transaction(function () use ($requestId, $pending, $options, $runSession): string {
                SmsContactAttribution::assertPinnedSession($runSession);
                $run = $this->runs()->where('Request_ID', $requestId)->lockForUpdate()->first();
                $this->assertAcceptedFileApproval($run);
                $this->assertApprovalReceiptUnchanged($run, $pending);
                $repository = app(SmsRecordExportRepository::class);
                $hasIds = DB::connection('sqlsrv')->table('TblSMSDrops')->where('Request_ID', $requestId)->exists()
                    || DB::connection('sqlsrv')->table('TblProspectsExport')->where('Request_ID', $requestId)
                        ->where(fn ($q) => $q->whereNotNull('SMS_Drop_PK')->orWhereNotNull('Drop_Name'))->exists();
                if (! $hasIds) {
                    $repository->assertFrozen($requestId, (int) $run->Selected, true);
                    // An accepted approval may execute after its planned date;
                    // never move that date or re-accept approval on a retry.
                    $this->export->assignDrops($requestId, SmsDeliveryPolicy::plannedDate($options['send_date']));
                }
                SmsContactAttribution::assertPinnedSession($runSession);
                $range = $repository->assertAllocatedDrops($requestId, (int) $run->Selected, $options);
                if (! $this->move($requestId, ['approving'], 'approving', ['Progress_Step' => 'finalizing_files'])) throw new RuntimeException('SMS allocation changed before its receipt committed.');
                return $range;
            });
        });
    }

    protected function commitApprovedFiles(string $requestId, array $context): void
    {
        // Restore the existing contact->run lock order for the final short SQL
        // commit. A competing retry can only adopt identical immutable files.
        SmsContactAttribution::coordinate(function (?\PDO $session) use ($requestId, $context): void {
            $runSession = DB::connection('sqlsrv')->getPdo();
            SmsWorkflowLock::run('run:'.$requestId, function () use ($requestId, $context, $session, $runSession): void {
                SmsContactAttribution::assertPinnedSession($runSession);
                DB::connection('sqlsrv')->transaction(function () use ($requestId, $context, $session, $runSession): void {
                    SmsContactAttribution::assertPinnedSession($session);
                    SmsContactAttribution::assertPinnedSession($runSession);
                    $run = $this->runs()->where('Request_ID', $requestId)->lockForUpdate()->first();
                    if ($run && $run->Status === 'approved') return;
                    $this->assertAcceptedFileApproval($run);
                    $this->assertApprovalReceiptUnchanged($run, $context['pending']);
                    $repository = app(SmsRecordExportRepository::class);
                    if ($repository->assertAllocatedDrops($requestId, (int) $run->Selected, $context['options']) !== $context['range']) {
                        throw new RuntimeException('The saved SMS drop range changed before the final approval commit.');
                    }
                    $bundle = $context['bundle'];
                    (new SmsRunArtifactBundle('v2'))->validateManifest($bundle['manifest'], strtolower($requestId), $context['options'], (int) $run->Selected);
                    $at = Carbon::parse($run->Approved_At, 'UTC');
                    $repository->approve($requestId, $at, (int) $run->Selected);
                    if (! $this->move($requestId, ['approving'], 'approved', [
                        'File_Path' => $bundle['path'], 'File_Bytes' => $bundle['bytes'], 'File_Sha256' => $bundle['sha256'],
                        'Artifact_Manifest_JSON' => json_encode($bundle['manifest'], JSON_THROW_ON_ERROR), 'File_At' => $this->now(),
                        'Sent_By' => $run->Approved_By, 'Sent_At' => $at->format('Y-m-d H:i:s'), 'Progress_Step' => 'approved',
                    ])) throw new RuntimeException('Approval status changed; all business changes were rolled back.');
                    $this->captureDebtPleteImport($this->mustRun($requestId));
                    $this->captureAutomaticHandoff($this->mustRun($requestId));
                });
            });
        });
        $this->requestDebtPleteImport($requestId);
        $this->requestAutomaticHandoff($requestId);
    }

    protected function captureAutomaticHandoff(object $run): void
    {
        if (! $this->automaticHandoffEnabled()) return;
        SmsDebtPleteImportPrivacy::run(fn () => app(SmsRunAutomaticHandoffWorkflow::class)->captureApproved($run));
    }

    /** Its dispatch acknowledgement cannot undo approval or suppress the independent import. */
    protected function requestAutomaticHandoff(string $requestId): void
    {
        if (! $this->automaticHandoffEnabled()) return;
        SmsDebtPleteImportPrivacy::run(function () use ($requestId): void {
            try {
                $status = app(SmsRunAutomaticHandoffWorkflow::class)->status($requestId);
                if ($status === null || in_array($status['status'], ['completed', 'held'], true)) return;
                \Cmd\Reports\Jobs\SmsRunAutomaticHandoffJob::dispatch($requestId)->afterCommit();
            } catch (Throwable) {
                try { \Illuminate\Support\Facades\Log::warning('SMS approval committed; automatic handoff queue acknowledgement is pending.', ['request_id' => $requestId]); }
                catch (Throwable) {}
            }
        });
    }

    private function automaticHandoffEnabled(): bool
    {
        return config('sms-exports.handoff_automatic', false) === true
            && config('sms-exports.file_publication_enabled', false) === true
            && config('sms-exports.email_handoff_enabled', false) === true;
    }

    /** Import evidence commits with the first approval; no upload runs in this transaction. */
    protected function captureDebtPleteImport(object $run): void
    {
        if (config('sms-exports.debtplete_import_enabled', false) !== true
            || config('sms-exports.debtplete_import_automatic', false) !== true) return;
        SmsDebtPleteImportPrivacy::run(fn () => app(SmsRunDebtPleteImportWorkflow::class)->captureApproved($run));
    }

    /** Queue acknowledgement is separate from the committed approval and durable outbox. */
    protected function requestDebtPleteImport(string $requestId): void
    {
        if (config('sms-exports.debtplete_import_enabled', false) !== true
            || config('sms-exports.debtplete_import_automatic', false) !== true) return;
        SmsDebtPleteImportPrivacy::run(function () use ($requestId): void {
            try {
                $status = app(SmsRunDebtPleteImportWorkflow::class)->status($requestId);
                if ($status === null || in_array($status['status'], ['completed', 'held'], true)) return;
                \Cmd\Reports\Jobs\SmsRunDebtPleteImportJob::dispatch($requestId)->afterCommit();
            } catch (Throwable) {
                try {
                    \Illuminate\Support\Facades\Log::warning('SMS approval committed; import queue acknowledgement is pending.', ['request_id' => $requestId]);
                } catch (Throwable) {}
            }
        });
    }

    /** Deliberately overridable to exercise crash/retry publication boundaries. */
    protected function publishApprovedFiles(string $requestId, array $manifest, array $options, int $expected, string $range): array
    {
        $db = DB::connection('sqlsrv');
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) throw new RuntimeException('SMS approval file publication must occur outside SQL transactions.');
        return (new SmsRunArtifactBundle('v2'))->publishApproved($this->runDirectory($requestId), $manifest, $options, $expected, $range);
    }

    private function assertAcceptedFileApproval(?object $run): void
    {
        if ($run === null || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approving' || ! $run->Approved_At || ! $run->Approved_By) {
            throw new HttpException(409, 'No accepted approval-time SMS batch is waiting for finalization.');
        }
        $this->assertPolicyReady($run);
        if (! $this->approvalFilenames($this->fileOptions($run))) throw new RuntimeException('The SMS approval filename version changed.');
    }

    /** Pure receipt comparison; no artifact IO under the database row lock. */
    private function assertApprovalReceiptUnchanged(object $run, object $original): void
    {
        foreach (['Request_ID','Target','Selected','Run_Options_JSON','File_Path','File_Bytes','File_Sha256','Artifact_Manifest_JSON','Approved_At','Approved_By'] as $key) {
            if (($run->$key ?? null) !== ($original->$key ?? null)) throw new HttpException(409, 'The SMS approval or frozen publication receipt changed. Refresh before continuing.');
        }
    }

    public function details(string $requestId): array
    {
        $run = $this->mustRun($requestId);
        if (SmsRecordPolicy::isExternal($run)) return SmsExternalEventProjection::details($run);
        $this->assertPolicyReady($run);
        return app(SmsRecordExportRepository::class)->details($requestId);
    }

    /** A review copy is never a send and must not reserve people or accrue invoice costs. */
    public function reviewOnly(string $requestId, string $actor): object
    {
        $this->assertLegacy($this->mustRun($requestId));
        return SmsWorkflowLock::run('run:'.$requestId, fn () => DB::connection('sqlsrv')->transaction(function () use ($requestId, $actor): object {
            if (! $this->move($requestId, ['file_ready'], 'review_only', ['Closed_By' => $actor, 'Closed_At' => $this->now()])) {
                throw new HttpException(409, 'Only a ready, unsent file can be saved for review.');
            }
            $this->export->releaseReview($requestId);
            return $this->show($requestId);
        }));
    }

    public function requestNotSent(string $requestId, string $actor, string $reason): object
    {
        $this->assertLegacy($this->mustRun($requestId));
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) throw ValidationException::withMessages(['reason' => 'Explain why this was not sent (10–500 characters).']);
        SmsWorkflowLock::run('run:'.$requestId, function () use ($requestId, $actor, $reason): void {
            $db = DB::connection('sqlsrv');
            $db->transaction(function () use ($db, $requestId, $actor, $reason): void {
                // Invoice allocation locks this same run row before inspecting its drops.
                // The application lock alone would not serialize those two workflows.
                $db->table('TblSMSRuns')->where('Request_ID', $requestId)->lockForUpdate()->first();
                if ($db->table('TblSMSDrops')->where('Request_ID', $requestId)->whereNotNull('Invoice_Number')->where('Invoice_Number', '<>', '')->exists()) {
                    throw new HttpException(409, 'This run has an invoice. Reconcile that invoice before correcting its send.');
                }
                if (! $db->table('TblSMSAttemptAudit')->where('Request_ID', $requestId)->exists()) {
                    throw new HttpException(409, 'This older run has no saved before-values. An administrator must reconcile it against a pre-send backup; no dates or counts were changed.');
                }
                if (! $this->move($requestId, ['sent'], 'reversing', ['Closed_By' => $actor, 'Close_Reason' => $reason])) {
                    throw new HttpException(409, 'Only a recorded send can be corrected.');
                }
            });
        });
        $this->dispatch($requestId, 'reverse');
        return $this->show($requestId);
    }

    public function reverseSent(string $requestId): void
    {
        SmsWorkflowLock::run('run:'.$requestId, function () use ($requestId): void {
            $this->assertLegacy($this->mustRun($requestId));
            if ($this->run($requestId)?->Status !== 'reversing') return;
            DB::connection('sqlsrv')->transaction(function () use ($requestId): void {
                $this->export->reverseSent($requestId);
                $this->move($requestId, ['reversing'], 'not_sent', ['Closed_At' => $this->now()]);
            });
        });
    }

    public function discard(string $requestId): object
    {
        SmsRecordPolicy::assertOrdinary($this->mustRun($requestId));
        return SmsWorkflowLock::run('run:'.$requestId, function () use ($requestId): object {
            $run = $this->mustRun($requestId);
            if (SmsRecordPolicy::isRecord($run)) {
                app(SmsSelectionCancellation::class)->assertDiscardAllowed($run);
                return $this->discardRecord($requestId);
            }
            return DB::connection('sqlsrv')->transaction(fn () => $this->discardNow($requestId));
        });
    }

    protected function discardNow(string $requestId): object
    {
        $run = $this->mustRun($requestId);
        SmsRecordPolicy::assertOrdinary($run);
        if (! in_array($run->Status, ['review', 'file_ready', 'failed'], true)) {
            throw new HttpException(409, $run->Status === 'sent'
                ? 'This run was sent and cannot be discarded.'
                : 'Wait for the current step to finish before discarding.');
        }
        // Someone said the texts went out; dropping the run now would let these people be texted again.
        if ($run->Sent_By) {
            throw new HttpException(409, 'This run was marked as sent. Retry to finish recording the send.');
        }
        $this->export->discard($requestId);
        if ($run->File_Path && is_file($run->File_Path)) @unlink($run->File_Path);
        $this->move($requestId, ['review', 'file_ready', 'failed'], 'discarded', ['File_Path' => null]);

        return $this->show($requestId);
    }

    /** Quarantine first, then commit membership deletion. Physical cleanup is retryable. */
    private function discardRecord(string $requestId): object
    {
        $quarantine = null; $original = null; $previousFilePath = null; $versioned = false;
        try {
            $db = DB::connection('sqlsrv');
            $db->transaction(function () use ($db, $requestId, &$quarantine, &$original, &$previousFilePath, &$versioned): void {
                $run = $this->runs()->where('Request_ID', $requestId)->lockForUpdate()->first();
                $this->assertPolicyReady($run);
                if ($run->Status === 'discarded') return;
                if (! in_array($run->Status, ['review','file_ready','failed'], true) || $run->Approved_By || $run->Approved_At) {
                    throw new HttpException(409, 'An approved or approval-in-progress record batch cannot be deleted.');
                }
                if ($db->table('TblSMSHistory')->where('Request_ID', $requestId)->exists()) throw new HttpException(409, 'History exists for this batch; deletion was refused.');
                $previousFilePath = $run->File_Path;
                $versioned = $this->fileOptions($run) !== null;
                if ($versioned) {
                    $original = $this->runDirectory($requestId).'/v1';
                    $quarantine = (new SmsRunArtifactBundle)->quarantine($this->runDirectory($requestId));
                } elseif ($run->File_Path) {
                    $original = $run->File_Path; $quarantine = $original.'.delete-pending';
                    // A process can die after rename but before SQL commits. The run lock
                    // makes its deterministic quarantine safe to adopt on an explicit retry.
                    if (file_exists($quarantine)) {
                        if (! is_file($quarantine) || file_exists($original)) throw new RuntimeException('The pending file has conflicting quarantine evidence; nothing was deleted.');
                    } elseif (is_file($original) && ! rename($original, $quarantine)) {
                        throw new RuntimeException('The pending file could not be quarantined; nothing was deleted.');
                    }
                }
                $db->table('TblProspectsExport')->where('Request_ID', $requestId)->delete();
                $db->table('TblSMSDrops')->where('Request_ID', $requestId)->delete();
                if (! $this->move($requestId, ['review','file_ready','failed'], 'discarded', ['File_Path' => null,
                    'File_Cleanup_Path' => $quarantine, 'Closed_At' => $this->now()])) throw new RuntimeException('The batch changed before deletion.');
            });
        } catch (Throwable $error) {
            // Laravel commit listeners may throw after PDO committed. Only restore
            // after a fresh writer read proves the pending state survived rollback.
            // An uncertain outcome keeps the file quarantined for explicit retry.
            if ($quarantine && $original && (is_file($quarantine) || is_dir($quarantine)) && ! file_exists($original)) {
                try {
                    $pending = $this->runs()->useWritePdo()->where('Request_ID', $requestId)->first();
                    if ($pending && in_array($pending->Status, ['review','file_ready','failed'], true)
                        && $pending->File_Path === $previousFilePath && ! ($pending->File_Cleanup_Path ?? null)) {
                        if ($versioned) (new SmsRunArtifactBundle)->restore($this->runDirectory($requestId));
                        else @rename($quarantine, $original);
                    }
                } catch (Throwable) { /* Keep private quarantine when commit outcome is unknown. */ }
            }
            throw $error;
        }
        try { $this->cleanupDeletedFile($requestId); } catch (Throwable) { /* Persisted cleanup receipt remains retryable. */ }
        return $this->show($requestId);
    }

    public function cleanupDeletedFile(string $requestId): void
    {
        $run = $this->mustRun($requestId);
        SmsRecordPolicy::assertOrdinary($run);
        if (! SmsRecordPolicy::isRecord($run) || $run->Status !== 'discarded' || ! ($run->File_Cleanup_Path ?? null)) return;
        $path = (string) $run->File_Cleanup_Path;
        if ($this->fileOptions($run) !== null) {
            if ($path !== $this->runDirectory($requestId).'/v1.delete-pending') {
                throw new RuntimeException('The SMS cleanup receipt is outside this versioned run.');
            }
            if (! (new SmsRunArtifactBundle)->cleanup($this->runDirectory($requestId))) return;
            $this->runs()->where('Request_ID', $requestId)->where('Status', 'discarded')->where('File_Cleanup_Path', $path)
                ->update(['File_Cleanup_Path' => null]);
            return;
        }
        // A failed unlink retains a private receipt, never a downloadable export.
        if (is_file($path) && ! @unlink($path)) return;
        $this->runs()->where('Request_ID', $requestId)->where('Status', 'discarded')->where('File_Cleanup_Path', $path)->update(['File_Cleanup_Path' => null]);
    }

    /** Runs the failed step again, or restarts a step that stalled. */
    public function retry(string $requestId): object
    {
        $job = SmsWorkflowLock::run('run:'.$requestId, fn () => $this->retryNow($requestId));
        $this->dispatch($requestId, $job);
        return $this->show($requestId);
    }

    protected function retryNow(string $requestId): string
    {
        $run = $this->mustRun($requestId);
        $stalled = $this->stalled($run);
        if ($run->Status !== 'failed' && ! $stalled) throw new HttpException(409, 'Only a failed or stuck step can be retried.');
        $this->assertPolicyReady($run);
        $step = $stalled ? ['selecting' => 'select', 'queued' => 'select', 'building_file' => 'file', 'sending' => 'send', 'reversing' => 'reverse', 'approving' => 'approve'][$run->Status] : $run->Failed_Step;
        [$status, $job] = match ($step) {
            'file' => ['building_file', 'file'],
            'send' => ['sending', 'send'],
            'reverse' => ['reversing', 'reverse'],
            'approve' => ['approving', 'approve'],
            default => ['queued', 'prepare'],
        };
        if (! $this->move($requestId, [$run->Status], $status, ['Failed_Step' => null, 'Error' => null])) {
            throw new HttpException(409, 'The run changed. Refresh the page.');
        }
        return $job;
    }

    public function fail(string $requestId, string $job, string $message): void
    {
        $run = $this->run($requestId);
        if ($run !== null) SmsRecordPolicy::assertOrdinary($run);
        $step = ['prepare' => 'select', 'file' => 'file', 'send' => 'send', 'reverse' => 'reverse', 'approve' => 'approve'][$job] ?? $job;
        $from = ['prepare' => ['queued', 'selecting'], 'file' => ['building_file'], 'send' => ['sending'], 'reverse' => ['reversing'], 'approve' => ['approving']][$job] ?? [];
        $this->move($requestId, $from, 'failed', ['Failed_Step' => $step, 'Error' => mb_substr($message, 0, 500)]);
    }

    public function show(string $requestId): object
    {
        $run = $this->mustRun($requestId);
        if (SmsRecordPolicy::isExternal($run)) return (object) SmsExternalEventProjection::forRun($run);
        $view = [
            'request_id' => strtolower((string) $run->Request_ID), 'status' => $run->Status, 'target' => (int) $run->Target,
            'options' => $this->fileOptions($run),
            'selected' => $run->Selected === null ? null : (int) $run->Selected,
            'debt' => $run->Selected === null ? null : ['tu' => (int) $run->Debt_TU, 'mailers' => (int) $run->Debt_Mailers,
                'tier_default' => (int) $run->Debt_Tier_Default, 'missing' => (int) $run->Debt_Missing],
            'failed_step' => $run->Failed_Step, 'error' => $run->Error, 'busy' => in_array($run->Status, self::BUSY, true),
            'stalled' => $this->stalled($run),
            'created_by' => $run->Created_By, 'created_at' => $this->iso($run->Created_At), 'updated_at' => $this->iso($run->Updated_At),
            'sent_by' => $run->Sent_By, 'sent_at' => $this->iso($run->Sent_At),
            'selection_policy' => $run->Selection_Policy ?? SmsRecordPolicy::LEGACY,
            'never_texted' => isset($run->Never_Texted) ? (int) $run->Never_Texted : null,
            'previously_texted' => isset($run->Previously_Texted) ? (int) $run->Previously_Texted : null,
            'shortfall' => $run->Selected === null ? null : max(0, (int) $run->Target - (int) $run->Selected),
            'approved_at' => $this->utcIso($run->Approved_At ?? null), 'approved_by' => $run->Approved_By ?? null,
            'file_cleanup_pending' => ! empty($run->File_Cleanup_Path),
            'progress_step' => $run->Progress_Step ?? null,
            'closed_by' => $run->Closed_By ?? null, 'close_reason' => $run->Close_Reason ?? null,
            'can_correct_send' => $run->Status === 'sent' && DB::connection('sqlsrv')->table('TblSMSAttemptAudit')->where('Request_ID', $requestId)->exists(),
            'file' => $this->fileView($run),
            'drops' => in_array($run->Status, ['queued', 'selecting', 'discarded'], true) ? [] : $this->export->drops($requestId),
        ];

        $view += $this->approvalFileView($run, $view['options']);
        if (($run->Progress_Step ?? null) === SmsSelectionCancellation::CANCELLED_STEP) $view['selection_cancelled'] = true;
        $cancellation = app(SmsSelectionCancellation::class)->projection($run);
        if ($cancellation !== null) $view['selection_cancellation'] = $cancellation;
        $view['handoff'] = SmsRunDebtPleteImportProjection::forRun($view, SmsRunEmailProjection::forRun($view));
        return (object) $view;
    }

    /** Latest page only; older closed runs use the same keyset history contract. */
    public function overview(): array
    {
        $open = $this->runs()->whereNotIn('Status', self::CLOSED)->orderByDesc('PK')->first();
        $page = $this->history();
        return ['open' => $open ? $this->show((string) $open->Request_ID) : null, 'recent' => $page['items'],
            'history_next_cursor' => $page['next_cursor'],
            'selection_policy' => SmsRecordPolicy::current(), 'capabilities' => $this->fileCapabilities()];
    }

    /** Bounded read only; no COUNT/OFFSET, file hashing, regeneration or tracking writes. */
    public function history(?int $beforeId = null, int $limit = 10): array
    {
        if ($beforeId !== null && ($beforeId < 1 || $beforeId > 2147483647)) {
            throw ValidationException::withMessages(['before_id' => 'Use a valid history cursor.']);
        }
        if ($limit < 1 || $limit > 50) throw ValidationException::withMessages(['limit' => 'History page size must be from 1 to 50.']);
        $query = $this->runs()->whereIn('Status', self::CLOSED)->orderByDesc('PK');
        if ($beforeId !== null) $query->where('PK', '<', $beforeId);
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        return ['items' => $page->map(fn ($run) => $this->historyView($run))->values()->all(),
            'next_cursor' => $hasMore ? (int) $page->last()->PK : null, 'limit' => $limit];
    }

    private function historyView(object $run): object
    {
        if (SmsRecordPolicy::isExternal($run)) return (object) SmsExternalEventProjection::forRun($run);
        $view = [
            'request_id' => strtolower((string) $run->Request_ID), 'status' => $run->Status,
            'target' => (int) $run->Target, 'selected' => $run->Selected === null ? null : (int) $run->Selected,
            'options' => $this->fileOptions($run), 'file' => $this->fileView($run),
            'created_by' => $run->Created_By, 'created_at' => $this->iso($run->Created_At),
            'sent_by' => $run->Sent_By, 'sent_at' => $this->iso($run->Sent_At),
            'selection_policy' => $run->Selection_Policy ?? SmsRecordPolicy::LEGACY,
            'never_texted' => isset($run->Never_Texted) ? (int) $run->Never_Texted : null,
            'previously_texted' => isset($run->Previously_Texted) ? (int) $run->Previously_Texted : null,
            'shortfall' => $run->Selected === null ? null : max(0, (int) $run->Target - (int) $run->Selected),
            'approved_at' => $this->utcIso($run->Approved_At ?? null), 'approved_by' => $run->Approved_By ?? null,
            'file_cleanup_pending' => ! empty($run->File_Cleanup_Path),
            'can_correct_send' => $run->Status === 'sent' && DB::connection('sqlsrv')->table('TblSMSAttemptAudit')->where('Request_ID', $run->Request_ID)->exists(),
            'drops' => $run->Status !== 'discarded' ? $this->export->drops((string) $run->Request_ID) : [],
        ];
        $view += $this->approvalFileView($run, $view['options']);
        if (($run->Progress_Step ?? null) === SmsSelectionCancellation::CANCELLED_STEP) $view['selection_cancelled'] = true;
        $cancellation = app(SmsSelectionCancellation::class)->projection($run);
        if ($cancellation !== null) $view['selection_cancellation'] = $cancellation;
        $view['handoff'] = SmsRunDebtPleteImportProjection::forRun($view, SmsRunEmailProjection::forRun($view));
        return (object) $view;
    }

    /** A short-lived signed link straight to CMD runner, so the file never passes through the API proxy. */
    public function downloadUrl(string $requestId): string
    {
        $run = $this->mustRun($requestId);
        if (SmsRecordPolicy::isExternal($run)) SmsExternalEventProjection::assertComplete($run);
        if ($this->approvalFilenames($this->fileOptions($run)) && $run->Status === 'approving') throw new HttpException(409, 'SMS files are being finalized after approval.');
        if (! in_array($run->Status, ['file_ready', 'sending', 'sent', 'review_only', 'not_sent', 'approving', 'approved', 'external_sent'], true) || ! $run->File_Path || ! is_file($run->File_Path)) {
            throw new HttpException(409, 'The file is not ready, or it is no longer on the server.');
        }
        $origin = rtrim((string) config('app.url'), '/');
        if (! app()->environment('local') && ! str_starts_with($origin, 'https://')) {
            throw new RuntimeException('CMD runner APP_URL must be its HTTPS origin for SMS file downloads.');
        }

        return $origin.URL::temporarySignedRoute('cmd.sms_run.download', now()->addMinutes(15), ['requestId' => strtolower($requestId)], false);
    }

    public function filePath(string $requestId): string
    {
        $run = $this->mustRun($requestId);
        if (SmsRecordPolicy::isExternal($run)) {
            $header = SmsExternalEventProjection::assertComplete($run);
            try { return SmsExternalArtifact::verify($run, $header); }
            catch (\Throwable $error) { throw new HttpException(409, 'Original external files are unavailable or do not match their receipt.', $error); }
        }
        if (SmsRecordPolicy::isRecord($run) && ! in_array($run->Status, ['file_ready','approving','approved'], true)) throw new HttpException(404, 'This batch has no downloadable file.');
        if (SmsRecordPolicy::isRecord($run) && in_array($run->Status, ['file_ready','approving'], true)) {
            app(SmsRecordExportRepository::class)->assertCurrentStates($requestId);
        }
        if (! SmsRecordPolicy::isRecord($run) && in_array($run->Status, ['file_ready','sending'], true)) {
            $this->export->assertCurrentStates($requestId);
        }
        if ($this->approvalFilenames($this->fileOptions($run)) && $run->Status === 'approving') throw new HttpException(409, 'SMS files are being finalized after approval.');
        $path = $this->verifiedRunArtifact($run, $this->artifactGeneration($run));
        // Hashing can take time for a large bundle. Check again immediately before
        // releasing a pending download, using the same frozen membership.
        if (SmsRecordPolicy::isRecord($run) && in_array($run->Status, ['file_ready','approving'], true)) {
            app(SmsRecordExportRepository::class)->assertCurrentStates($requestId);
        }
        if (! SmsRecordPolicy::isRecord($run) && in_array($run->Status, ['file_ready','sending'], true)) {
            $this->export->assertCurrentStates($requestId);
        }
        return $path;
    }

    private function verifiedRunArtifact(object $run, string $generation): string
    {
        $requestId = strtolower((string) $run->Request_ID);
        if (! $run->File_Path || ! is_file($run->File_Path)) throw new HttpException(404, 'The SMS file is no longer on the server.');
        $options = $this->fileOptions($run);
        if ($options !== null) {
            try {
                // Publication/recovery workers already verified every CSV row/ZIP entry.
                // HTTP pins those exact bytes instead of recounting millions of rows.
                $manifest = $this->artifactManifest($run);
                $path = (new SmsRunArtifactBundle($generation))->verify($this->runDirectory($requestId), $manifest, $options, (int) $run->Selected, false);
                if ($path !== $run->File_Path || (int) $run->File_Bytes !== $manifest['download']['bytes']
                    || ! is_string($run->File_Sha256) || ! hash_equals($manifest['download']['sha256'], $run->File_Sha256)) {
                    throw new RuntimeException('The SMS primary artifact metadata changed.');
                }
                return $path;
            } catch (RuntimeException|\InvalidArgumentException|\JsonException $error) {
                throw new HttpException(409, 'The SMS bundle does not match its frozen publication receipt.', $error);
            }
        }
        if (filesize($run->File_Path) !== (int) $run->File_Bytes || ! hash_equals((string) $run->File_Sha256, hash_file('sha256', $run->File_Path))) throw new HttpException(409, 'The SMS file on the server does not match what was recorded.');

        return $run->File_Path;
    }

    protected function dispatch(string $requestId, string $job): void
    {
        try {
            app(SmsExportQueue::class)->assertReady();
            SmsRunStepJob::dispatch($requestId, $job);
        } catch (Throwable $error) {
            $this->fail($requestId, $job, 'The step could not be queued: '.$error->getMessage());
            throw $error;
        }
    }

    /** Moves the run only if it is in one of $from; returns whether it moved. */
    protected function move(string $requestId, array $from, string $to, array $values = []): bool
    {
        return $this->runs()->where('Request_ID', $requestId)->whereIn('Status', $from)
            ->update(['Status' => $to, 'Updated_At' => $this->now()] + $values) === 1;
    }

    protected function stalled(object $run): bool
    {
        return in_array($run->Status, self::BUSY, true)
            && Carbon::parse($run->Updated_At)->lt(Carbon::now()->subMinutes(self::STALLED_AFTER_MINUTES));
    }

    protected function runDirectory(string $requestId): string
    {
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', strtolower($requestId))) throw new RuntimeException('Invalid run ID.');
        $root = str_replace('\\', '/', rtrim((string) (config('sms-exports.local_root') ?: storage_path('app/private/sms-exports')), '/\\'));

        return $root.'/runs/'.strtolower($requestId);
    }

    protected function runs()
    {
        return DB::connection('sqlsrv')->table('TblSMSRuns');
    }

    protected function run(string $requestId): ?object
    {
        return $this->runs()->where('Request_ID', $requestId)->first();
    }

    protected function mustRun(string $requestId): object
    {
        return $this->run($requestId) ?? throw new HttpException(404, 'SMS run not found.');
    }

    protected function now(): string
    {
        return Carbon::now()->toDateTimeString();
    }

    protected function iso(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function utcIso(mixed $value): ?string
    {
        return $value ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
    }

    private function assertPolicyReady(object $run): void
    {
        SmsRecordPolicy::assertOrdinary($run);
        $this->fileOptions($run);
        if (SmsRecordPolicy::isRecord($run)) SmsRecordPolicy::assertReady();
    }

    private function assertLegacy(object $run): void
    {
        SmsRecordPolicy::assertOrdinary($run);
        $this->fileOptions($run);
        if (SmsRecordPolicy::isRecord($run)) throw new HttpException(409, 'Record-based batches use immutable Approve/Delete actions, not legacy send correction or review-only.');
    }

    private function normalizeFileOptions(int $target, ?array $input, ?object $existing = null): ?array
    {
        if ($input === null) return null;
        try {
            if (($input['csv_format'] ?? null) !== SmsRunOptions::CSV_FORMAT
                || array_diff(array_keys($input), ['target', 'csv_format', 'rows_per_file', 'tiers', 'tier_selection_policy', 'options_version', 'send_date', 'filename_version']) !== []
                || (isset($input['target']) && $input['target'] !== $target)
                || (isset($input['rows_per_file']) && ! is_int($input['rows_per_file']))
                || (isset($input['tiers']) && ! is_array($input['tiers']))
                || (array_key_exists('tier_selection_policy', $input) && ! SmsRunOptions::isTierSelectionPolicy($input['tier_selection_policy']))
                || (array_key_exists('options_version', $input) && $input['options_version'] !== SmsRunOptions::PLANNED_DATE_VERSION)
                || (array_key_exists('filename_version', $input) && ! in_array($input['filename_version'], [SmsRunOptions::DROP_RANGE_FILENAME_VERSION, SmsRunOptions::APPROVAL_FILENAME_VERSION], true))
                || (array_key_exists('send_date', $input) && ! is_string($input['send_date']))) {
                throw new \InvalidArgumentException('Use the explicit supported SMS format and valid file options.');
            }
            $planned = isset($input['options_version']) || array_key_exists('send_date', $input);
            // Default retries reuse the accepted date, never a newly calculated Monday.
            $stored = $planned && $existing !== null ? $this->fileOptions($existing) : null;
            $date = $planned ? ($input['send_date'] ?? $stored['send_date'] ?? SmsDeliveryPolicy::followingMonday()->toDateString()) : null;
            return SmsRunOptions::normalize($target, $input['rows_per_file'] ?? null, $input['tiers'] ?? null, $input['tier_selection_policy'] ?? null,
                $planned ? SmsRunOptions::PLANNED_DATE_VERSION : null, $date, $input['filename_version'] ?? null);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['file_options' => $error->getMessage()]);
        }
    }

    private function fileOptions(object $run): ?array
    {
        try {
            $options = SmsRunOptions::fromStored($run->Run_Options_JSON ?? null, (int) $run->Target);
            if ($options !== null && (! SmsRecordPolicy::isRecord($run)
                || (! isset($options['tier_selection_policy']) && $options['tiers'] !== SmsRunOptions::TIERS))) {
                throw new \InvalidArgumentException('This SMS options/selection contract is not supported.');
            }
            return $options;
        } catch (\InvalidArgumentException|\TypeError $error) {
            throw new HttpException(409, 'Stored SMS file options require reconciliation; the run was not reinterpreted.', $error);
        }
    }

    private function assertSameStart(object $run, int $target, ?array $options): void
    {
        if ((int) $run->Target !== $target || $this->fileOptions($run) !== $options) {
            throw new HttpException(409, 'This run ID belongs to different quantity, tier or file options. Retry the original request unchanged.');
        }
    }

    private function fileCapabilities(): ?array
    {
        if (! config('sms-exports.run_file_options_enabled', false) || ! SmsRecordPolicy::enabled()) return null;
        try { SmsRecordPolicy::assertReady(); } catch (HttpException) { return null; }
        if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasColumns('TblSMSRuns', ['Run_Options_JSON', 'Artifact_Manifest_JSON'])
            || ! class_exists(\ZipArchive::class)) return null;
        $tierSelection = (bool) config('sms-exports.tier_selection_enabled', false);
        $windowed = (bool) config('sms-exports.tier_selection_windowed_enabled', false);
        $leanHistory = $windowed && (bool) config('sms-exports.lean_history_enabled', false);
        $windowSchemaReady = ! $tierSelection || ! $windowed || (SmsTierWindowSchema::ready(DB::connection('sqlsrv'))
            && (! $leanHistory || SmsLeanHistorySchema::ready(DB::connection('sqlsrv'))));
        if ($tierSelection && ! $windowSchemaReady) $tierSelection = false;
        $capabilities = ['csv_format' => SmsRunOptions::CSV_FORMAT, 'rows_per_file' => true,
            'tier_subset' => $tierSelection, 'max_parts' => SmsRunArtifactBundle::MAX_PARTS];
        if ($capabilities['tier_subset']) {
            $capabilities['tier_selection_policy'] = $windowed
                ? ($leanHistory ? SmsRunOptions::LEAN_WINDOWED_TIER_SELECTION_POLICY : SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY)
                : SmsRunOptions::TIER_SELECTION_POLICY;
        }
        $capabilities += ['options_version' => SmsRunOptions::PLANNED_DATE_VERSION, 'send_date' => true,
            'default_send_date' => SmsDeliveryPolicy::followingMonday()->toDateString(),
            'min_send_date' => Carbon::now(SmsDeliveryPolicy::TIMEZONE)->toDateString(), 'send_date_timezone' => SmsDeliveryPolicy::TIMEZONE,
            'filename_version' => SmsRunOptions::APPROVAL_FILENAME_VERSION];
        if (SmsRunOptions::isWindowedTierSelectionPolicy($capabilities['tier_selection_policy'] ?? null)
            && app(SmsSelectionCancellation::class)->availableForNewRequests()) {
            $capabilities['selection_cancellation'] = true;
        }
        return $capabilities;
    }

    private function assertNewFileOptionsReady(array $options): void
    {
        if (! isset($options['tier_selection_policy']) && $options['tiers'] !== SmsRunOptions::TIERS) {
            throw ValidationException::withMessages(['tiers' => 'Tier subsets require the explicit current-source-tier selection policy.']);
        }
        if (isset($options['tier_selection_policy']) && ! config('sms-exports.tier_selection_enabled', false)) {
            throw new HttpException(503, 'Source-tier selection requires its coordinated creation rollout.');
        }
        if (SmsRunOptions::isWindowedTierSelectionPolicy($options['tier_selection_policy'] ?? null)) {
            try { SmsTierWindowSchema::assertReady(DB::connection('sqlsrv')); }
            catch (Throwable $error) { throw new HttpException(503, 'Windowed source-tier selection requires its reviewed seek schema.', $error); }
        }
        if (SmsRunOptions::usesLeanDebtSources($options['tier_selection_policy'] ?? null)) {
            try { SmsLeanHistorySchema::assertReady(DB::connection('sqlsrv')); }
            catch (Throwable $error) { throw new HttpException(503, 'Lean SMS history requires unique TU and mailer source PK lookup identities.', $error); }
        }
        $capabilities = $this->fileCapabilities();
        if (isset($options['tier_selection_policy'])
            && ($capabilities['tier_selection_policy'] ?? null) !== $options['tier_selection_policy']) {
            throw new HttpException(503, 'This source-tier selector version is not enabled for new runs.');
        }
        if (intdiv($options['target'] + $options['rows_per_file'] - 1, $options['rows_per_file']) > SmsRunArtifactBundle::MAX_PARTS) {
            throw ValidationException::withMessages(['rows_per_file' => 'Choose rows per file that produce at most '.SmsRunArtifactBundle::MAX_PARTS.' files.']);
        }
        if ($capabilities === null) {
            throw new HttpException(503, 'New SMS file options require the coordinated record workflow, file-options migration, ZIP support and creation flag.');
        }
    }

    private function fileView(object $run): ?array
    {
        if (! $run->File_Path) return null;
        $view = ['bytes' => (int) $run->File_Bytes, 'name' => basename((string) $run->File_Path),
            'created_at' => $this->iso($run->File_At), 'format' => str_ends_with(strtolower($run->File_Path), '.zip') ? 'zip' : 'csv'];
        if (($options = $this->fileOptions($run)) !== null) {
            $manifest = $this->artifactManifest($run);
            try {
                (new SmsRunArtifactBundle($this->artifactGeneration($run)))->validateManifest($manifest, strtolower((string) $run->Request_ID), $options, (int) $run->Selected);
                if ($view['name'] !== $manifest['download']['name'] || $view['bytes'] !== $manifest['download']['bytes']
                    || $run->File_Sha256 !== $manifest['download']['sha256']) {
                    throw new RuntimeException('The SMS file metadata differs from its manifest.');
                }
            } catch (RuntimeException|\InvalidArgumentException $error) {
                throw new HttpException(409, 'The SMS file metadata does not match its frozen publication receipt.', $error);
            }
            $view['parts'] = count($manifest['parts']);
            $view['files'] = array_map(static fn (array $part): array => ['name' => $part['name'], 'rows' => $part['rows']], $manifest['parts']);
            if (isset($options['filename_version'])) {
                $view['drop_name_range'] = $this->approvalFilenames($options) && $run->Status !== 'approved' ? null
                    : SmsRunArtifactFilename::parseStem(SmsRunArtifactFilename::stemFromDownload($manifest['download']['name'], $manifest['download']['format']))['drop_name_range'];
            }
        }
        return $view;
    }

    private function approvalFilenames(?array $options): bool
    {
        return ($options['filename_version'] ?? null) === SmsRunOptions::APPROVAL_FILENAME_VERSION;
    }

    private function artifactGeneration(object $run): string
    {
        return $run->Status === 'approved' && $this->approvalFilenames($this->fileOptions($run)) ? 'v2' : 'v1';
    }

    private function approvalFileView(object $run, ?array $options): array
    {
        if (! $this->approvalFilenames($options)) return [];
        $phase = $run->Status === 'approved' ? 'complete'
            : ($run->Approved_At && $run->Approved_By ? ($run->Progress_Step === 'finalizing_files' ? 'finalizing_files' : 'assigning_ids') : 'awaiting_approval');
        return ['id_status' => in_array($phase, ['finalizing_files','complete'], true) ? 'assigned' : 'pending', 'approval_phase' => $phase];
    }

    private function artifactManifest(object $run): array
    {
        try { $manifest = json_decode((string) ($run->Artifact_Manifest_JSON ?? ''), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new HttpException(409, 'The SMS artifact manifest is missing or invalid.', $error); }
        if (! is_array($manifest) || ! is_array($manifest['parts'] ?? null)) throw new HttpException(409, 'The SMS artifact manifest is incomplete.');
        return $manifest;
    }
}
