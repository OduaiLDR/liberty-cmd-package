<?php

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Cmd\Reports\Jobs\SmsRunStepJob;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
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
    public const BUSY = ['queued', 'selecting', 'building_file', 'sending', 'reversing'];

    public const CLOSED = ['sent', 'discarded', 'review_only', 'not_sent'];

    public const MAX_TARGET = 10000000;

    /** A busy step with no progress for this long is shown as stuck and can be retried. */
    public const STALLED_AFTER_MINUTES = 120;

    public function __construct(protected SmsProspectExportRepository $export) {}

    public function start(string $requestId, int $target, string $actor): object
    {
        if ($target < 1 || $target > self::MAX_TARGET) {
            throw ValidationException::withMessages(['target' => 'Enter a number of records from 1 to 10,000,000.']);
        }
        $existing = $this->run($requestId);
        if ($existing) {
            if ((int) $existing->Target !== $target) throw new HttpException(409, 'This run ID belongs to a different request. Refresh the page.');
            return $this->show($requestId);
        }
        $created = SmsWorkflowLock::run('new-run', fn () => DB::connection('sqlsrv')->transaction(function () use ($requestId, $target, $actor): bool {
            $open = $this->runs()->whereNotIn('Status', self::CLOSED)->lockForUpdate()->first();
            if ($open) return false;
            $now = $this->now();
            $this->runs()->insert(['Request_ID' => $requestId, 'Target' => $target, 'Status' => 'queued',
                'Created_By' => $actor, 'Created_At' => $now, 'Updated_At' => $now]);
            return true;
        }));
        if (! $created) {
            throw new HttpException(409, 'Another SMS run is still open. Finish or discard it before starting a new one.');
        }
        $this->dispatch($requestId, 'prepare');

        return $this->show($requestId);
    }

    /** Select, look up debts and create the SMS drops. Each part resumes where it stopped. */
    public function prepare(string $requestId): void
    {
        SmsWorkflowLock::run('run:'.$requestId, fn () => $this->prepareNow($requestId));
    }

    protected function prepareNow(string $requestId): void
    {
        if (! $this->move($requestId, ['queued'], 'selecting', ['Progress_Step' => 'contacts_and_selection'])) return;
        $hasRows = DB::connection('sqlsrv')->table('TblProspectsExport')->where('Request_ID', $requestId)->exists();
        if (! $hasRows) {
            $this->export->select($requestId, (int) $this->run($requestId)->Target);
        }
        $selected = DB::connection('sqlsrv')->table('TblProspectsExport')->where('Request_ID', $requestId)->count();
        $this->move($requestId, ['selecting'], 'selecting', ['Selected' => $selected, 'Progress_Step' => 'tu_debts']);
        $counts = ['Selected' => $selected, 'Debt_TU' => 0, 'Debt_Mailers' => 0, 'Debt_Tier_Default' => 0, 'Debt_Missing' => 0];
        if ($selected > 0) {
            $this->export->lookupDebts($requestId, (array) config('sms-exports.tier_debts', []), function (string $step) use ($requestId): void {
                $this->move($requestId, ['selecting'], 'selecting', ['Progress_Step' => $step.'_debts']);
            });
            $this->move($requestId, ['selecting'], 'selecting', ['Progress_Step' => 'numbering_drops']);
            $this->export->assignDrops($requestId, Carbon::today(SmsProspectExportRepository::TIMEZONE));
            $sources = DB::connection('sqlsrv')->table('TblProspectsExport')->where('Request_ID', $requestId)
                ->selectRaw("SUM(CASE WHEN Debt_Source = 'TblTUData' THEN 1 ELSE 0 END) AS TU,
                    SUM(CASE WHEN Debt_Source = 'TblMailers' THEN 1 ELSE 0 END) AS Mailers,
                    SUM(CASE WHEN Debt_Source = 'Tier' THEN 1 ELSE 0 END) AS TierDefault,
                    SUM(CASE WHEN Debt_Amount IS NULL THEN 1 ELSE 0 END) AS Missing")->first();
            $counts = ['Selected' => $selected, 'Debt_TU' => (int) $sources->TU, 'Debt_Mailers' => (int) $sources->Mailers,
                'Debt_Tier_Default' => (int) $sources->TierDefault, 'Debt_Missing' => (int) $sources->Missing];
        }
        $this->move($requestId, ['selecting'], 'review', $counts + ['Progress_Step' => 'review']);
    }

    public function requestFile(string $requestId): object
    {
        $run = $this->mustRun($requestId);
        if ((int) $run->Selected < 1) throw new HttpException(409, 'This run has no records, so there is no file to make.');
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
        if ($this->run($requestId)?->Status !== 'building_file') return;
        $dir = $this->runDirectory($requestId);
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

    public function requestSent(string $requestId, string $actor): object
    {
        if (! $this->move($requestId, ['file_ready'], 'sending', ['Sent_By' => $actor])) {
            throw new HttpException(409, 'A run can be marked as sent once its file is ready.');
        }
        $this->dispatch($requestId, 'send');

        return $this->show($requestId);
    }

    public function markSent(string $requestId): void
    {
        SmsWorkflowLock::run('run:'.$requestId, function () use ($requestId): void {
            if ($this->run($requestId)?->Status !== 'sending') return;
            DB::connection('sqlsrv')->transaction(function () use ($requestId): void {
                $sentAt = Carbon::now();
                $this->export->markSent($requestId, $sentAt);
                $this->move($requestId, ['sending'], 'sent', ['Sent_At' => $sentAt->toDateTimeString()]);
            });
        });
    }

    /** A review copy is never a send and must not reserve people or accrue invoice costs. */
    public function reviewOnly(string $requestId, string $actor): object
    {
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
            if ($this->run($requestId)?->Status !== 'reversing') return;
            DB::connection('sqlsrv')->transaction(function () use ($requestId): void {
                $this->export->reverseSent($requestId);
                $this->move($requestId, ['reversing'], 'not_sent', ['Closed_At' => $this->now()]);
            });
        });
    }

    public function discard(string $requestId): object
    {
        return SmsWorkflowLock::run('run:'.$requestId, fn () => DB::connection('sqlsrv')->transaction(fn () => $this->discardNow($requestId)));
    }

    protected function discardNow(string $requestId): object
    {
        $run = $this->mustRun($requestId);
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
        $step = $stalled ? ['selecting' => 'select', 'queued' => 'select', 'building_file' => 'file', 'sending' => 'send', 'reversing' => 'reverse'][$run->Status] : $run->Failed_Step;
        [$status, $job] = match ($step) {
            'file' => ['building_file', 'file'],
            'send' => ['sending', 'send'],
            'reverse' => ['reversing', 'reverse'],
            default => ['queued', 'prepare'],
        };
        if (! $this->move($requestId, [$run->Status], $status, ['Failed_Step' => null, 'Error' => null])) {
            throw new HttpException(409, 'The run changed. Refresh the page.');
        }
        return $job;
    }

    public function fail(string $requestId, string $job, string $message): void
    {
        $step = ['prepare' => 'select', 'file' => 'file', 'send' => 'send', 'reverse' => 'reverse'][$job] ?? $job;
        $from = ['prepare' => ['queued', 'selecting'], 'file' => ['building_file'], 'send' => ['sending'], 'reverse' => ['reversing']][$job] ?? [];
        $this->move($requestId, $from, 'failed', ['Failed_Step' => $step, 'Error' => mb_substr($message, 0, 500)]);
    }

    public function show(string $requestId): object
    {
        $run = $this->mustRun($requestId);
        $view = [
            'request_id' => strtolower((string) $run->Request_ID), 'status' => $run->Status, 'target' => (int) $run->Target,
            'selected' => $run->Selected === null ? null : (int) $run->Selected,
            'debt' => $run->Selected === null ? null : ['tu' => (int) $run->Debt_TU, 'mailers' => (int) $run->Debt_Mailers,
                'tier_default' => (int) $run->Debt_Tier_Default, 'missing' => (int) $run->Debt_Missing],
            'failed_step' => $run->Failed_Step, 'error' => $run->Error, 'busy' => in_array($run->Status, self::BUSY, true),
            'stalled' => $this->stalled($run),
            'created_by' => $run->Created_By, 'created_at' => $this->iso($run->Created_At), 'updated_at' => $this->iso($run->Updated_At),
            'sent_by' => $run->Sent_By, 'sent_at' => $this->iso($run->Sent_At),
            'progress_step' => $run->Progress_Step ?? null,
            'closed_by' => $run->Closed_By ?? null, 'close_reason' => $run->Close_Reason ?? null,
            'can_correct_send' => $run->Status === 'sent' && DB::connection('sqlsrv')->table('TblSMSAttemptAudit')->where('Request_ID', $requestId)->exists(),
            'file' => $run->File_Path ? ['bytes' => (int) $run->File_Bytes, 'name' => basename((string) $run->File_Path), 'created_at' => $this->iso($run->File_At)] : null,
            'drops' => in_array($run->Status, ['queued', 'selecting', 'discarded'], true) ? [] : $this->export->drops($requestId),
        ];

        return (object) $view;
    }

    /** @return array{open: ?object, recent: array<int, object>} */
    public function overview(): array
    {
        $open = $this->runs()->whereNotIn('Status', self::CLOSED)->orderByDesc('PK')->first();
        $recent = $this->runs()->whereIn('Status', self::CLOSED)->orderByDesc('PK')->limit(10)->get()
            ->map(fn ($run) => (object) [
                'request_id' => strtolower((string) $run->Request_ID), 'status' => $run->Status,
                'target' => (int) $run->Target, 'selected' => $run->Selected === null ? null : (int) $run->Selected,
                'created_by' => $run->Created_By, 'created_at' => $this->iso($run->Created_At),
                'sent_by' => $run->Sent_By, 'sent_at' => $this->iso($run->Sent_At),
                'can_correct_send' => $run->Status === 'sent' && DB::connection('sqlsrv')->table('TblSMSAttemptAudit')->where('Request_ID', $run->Request_ID)->exists(),
                'drops' => $run->Status !== 'discarded' ? $this->export->drops((string) $run->Request_ID) : [],
            ])->all();

        return ['open' => $open ? $this->show((string) $open->Request_ID) : null, 'recent' => $recent];
    }

    /** A short-lived signed link straight to CMD runner, so the file never passes through the API proxy. */
    public function downloadUrl(string $requestId): string
    {
        $run = $this->mustRun($requestId);
        if (! in_array($run->Status, ['file_ready', 'sending', 'sent', 'review_only', 'not_sent'], true) || ! $run->File_Path || ! is_file($run->File_Path)) {
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
        if (! $run->File_Path || ! is_file($run->File_Path)) throw new HttpException(404, 'The SMS file is no longer on the server.');
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
        $root = rtrim((string) (config('sms-exports.local_root') ?: storage_path('app/private/sms-exports')), '/\\');

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
}
