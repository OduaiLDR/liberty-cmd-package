<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Cmd\Reports\Jobs\CancelSmsSelectionJob;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Durable control on the queue database, outside the worker's SQL Server snapshot. */
class SmsSelectionCancellation
{
    public const TABLE = 'sms_selection_cancellations';
    public const CANCELLED_STEP = 'selection_cancelled';

    private bool $ready = false;

    public static function enabled(): bool
    {
        return config('sms-exports.selection_cancellation_enabled', false) === true;
    }

    private static function configured(): bool
    {
        return config('queue.default') === 'database'
            && config('queue.connections.database.driver') === 'database';
    }

    public static function storeConnection(): ConnectionInterface
    {
        if (! self::configured()) throw new HttpException(503, 'Selection cancellation needs the database queue.');
        $queueName = config('queue.connections.database.connection') ?: config('database.default');
        $name = config('sms-exports.selection_cancellation_connection') ?: $queueName;
        $db = DB::connection($name);
        $queue = DB::connection($queueName);
        if (! in_array($db->getDriverName(), ['mysql', 'sqlite'], true)
            || $db->getName() === 'sqlsrv' || $db->getPdo() !== $queue->getPdo()) {
            throw new HttpException(503, 'Cancellation receipts and jobs need the same independent queue database.');
        }
        if ($db->getDriverName() === 'sqlite' && ! app()->environment('testing')) {
            throw new HttpException(503, 'SQLite cancellation control is only an isolated test fixture.');
        }
        return $db;
    }

    public function assertReady(): void
    {
        if ($this->ready) return;
        app(SmsExportQueue::class)->assertReady();
        $db = self::storeConnection();
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable(self::TABLE) || ! $schema->hasColumns(self::TABLE, [
            'request_id','created_by','options_sha256','state','requested_by','requested_at',
            'job_enqueued_at','finished_at','created_at','updated_at',
        ])) throw new HttpException(503, 'Install the explicit new selection-cancellation migration first.');
        $primary = array_filter($schema->getIndexes(self::TABLE),
            static fn (array $index): bool => ($index['primary'] ?? false) && ($index['columns'] ?? []) === ['request_id']);
        if (count($primary) !== 1
            || ! $schema->hasTable((string) config('queue.connections.database.table', 'jobs'))) {
            throw new HttpException(503, 'Cancellation needs its unique receipt and durable queue table.');
        }
        $this->ready = true;
    }

    public function availableForNewRequests(): bool
    {
        if (! self::enabled()) return false;
        try { $this->assertReady(); return true; }
        catch (Throwable) { return false; }
    }

    /** Never reinterpret a legacy, historical, approved or old filename workflow. */
    public static function supports(object $run): bool
    {
        if (($run->Selection_Policy ?? null) !== SmsRecordPolicy::RECORDS) return false;
        try {
            $options = SmsRunOptions::fromStored($run->Run_Options_JSON ?? null, (int) $run->Target);
            return ($options['csv_format'] ?? null) === SmsRunOptions::CSV_FORMAT
                && ($options['filename_version'] ?? null) === SmsRunOptions::APPROVAL_FILENAME_VERSION
                && SmsRunOptions::isWindowedTierSelectionPolicy($options['tier_selection_policy'] ?? null);
        } catch (Throwable) { return false; }
    }

    /** The API creation gate never disables worker cooperation once the store is installed. */
    private function recoveryStoreExists(): bool
    {
        if (! self::configured()) return false;
        $name = config('queue.connections.database.connection') ?: config('database.default');
        if ($name === 'sqlsrv') return false;
        return self::storeConnection()->getSchemaBuilder()->hasTable(self::TABLE);
    }

    public function begin(object $run): bool
    {
        if (! self::supports($run)) return false;
        if (! self::enabled() && ! $this->recoveryStoreExists()) return false;
        $this->assertReady();
        if (! in_array($run->Status, ['queued','selecting'], true)) return false;
        $this->fence($run, static fn (object $control): null => null);
        $this->checkpoint((string) $run->Request_ID);
        return true;
    }

    public function checkpoint(string $id): void
    {
        $this->assertReady();
        $row = $this->control($id);
        if ($row === null) throw new RuntimeException('The selection cancellation receipt disappeared.');
        if (in_array($row->state, ['requested','cancelled'], true)) throw new SmsSelectionCancelledException;
        if ($row->state !== 'active') throw new RuntimeException('Selection cancellation control is not active.');
    }

    /** Caller holds the run SQL applock; release this short fence before bulk/file deletion. */
    public function assertDiscardAllowed(object $run): void
    {
        if (! self::supports($run) || $run->Status !== 'failed' || ($run->Failed_Step ?? null) !== 'select') return;
        if (! self::enabled() && ! $this->recoveryStoreExists()) return;
        $source = DB::connection('sqlsrv');
        if ($source->transactionLevel() !== 0 || $source->getPdo()->inTransaction()) {
            throw new RuntimeException('Check cancellation before starting discard cleanup.');
        }
        $this->fence($run, function (object $control) use ($run): void {
            $fresh = $this->readRunSnapshot((string) $run->Request_ID);
            $this->assertOwnerScope($fresh, (string) $run->Created_By);
            $this->assertReceipt($control, $fresh);
            if ($fresh->Status !== 'failed' || ($fresh->Failed_Step ?? null) !== 'select') {
                throw new HttpException(409, 'The failed selection changed. Refresh before deleting.');
            }
            if (in_array($control->state, ['requested','cancelled'], true)) {
                throw new HttpException(409, 'Cancellation was accepted. Let its cleanup finish instead of deleting this run.');
            }
            if (! in_array($control->state, ['active','finished'], true)) throw new RuntimeException('Selection cancellation control changed.');
            // No tombstone: failed deletion must leave ordinary retry available.
        });
    }

    public function requestCancel(string $id, string $actor): object
    {
        try { return $this->requestCancelNow($id, $actor); }
        catch (HttpException $error) { throw $error; }
        catch (Throwable $error) {
            throw new HttpException(503, 'Could not confirm cancellation. Refresh or retry the same request; do not start another run.', $error);
        }
    }

    private function requestCancelNow(string $id, string $actor): object
    {
        $id = strtolower($id);
        $initial = $this->readRunSnapshot($id);
        $this->assertOwnerScope($initial, $actor);
        $this->assertReady();
        if (! self::enabled() && ! in_array($this->control($id)?->state, ['requested','cancelled'], true)) {
            throw new HttpException(503, 'New selection cancellation requests are disabled.');
        }
        return $this->fence($initial, function (object $control) use ($id, $actor): object {
            // A NEW snapshot under the fence sees a committed review even if its
            // MySQL finished receipt was lost after the SQL commit.
            $run = $this->readRunSnapshot($id);
            $this->assertOwnerScope($run, $actor);
            $this->assertReceipt($control, $run);
            if ($run->Status === 'discarded' && ($run->Progress_Step ?? null) === self::CANCELLED_STEP) {
                return $this->response($id, 'cancelled', $control->requested_at);
            }
            if (! in_array($run->Status, ['queued','selecting'], true)) {
                throw new HttpException(409, 'Selection has already finished. Review or delete the pending run instead.');
            }
            if ($control->state === 'requested') return $this->response($id, 'requested', $control->requested_at);
            if ($control->state !== 'active') throw new HttpException(409, 'Selection is no longer cancellable.');
            $now = $this->utcNow();
            self::storeConnection()->table(self::TABLE)->where('request_id', $id)->update([
                'state' => 'requested', 'requested_by' => $actor, 'requested_at' => $now,
                'job_enqueued_at' => $now, 'updated_at' => $now,
            ]);
            $this->enqueue($id);
            return $this->response($id, 'requested', $now);
        });
    }

    protected function enqueue(string $id): void
    {
        $store = self::storeConnection();
        $queue = Queue::connection('database');
        if (! $queue instanceof DatabaseQueue || $queue->getDatabase()->getPdo() !== $store->getPdo()
            || $store->transactionLevel() !== 1) {
            throw new RuntimeException('Cancellation and recovery job must be one database commit.');
        }
        $queue->push(new CancelSmsSelectionJob($id), '', (string) config('sms-exports.queue', 'tu-export'));
    }

    /** The final SQL review commit and cancellation acceptance share this short fence. */
    public function finishSelection(object $run, callable $publishReview): void
    {
        $this->fence($run, function (object $control) use ($run, $publishReview): void {
            if ($control->state === 'requested') throw new SmsSelectionCancelledException;
            if ($control->state !== 'active') throw new RuntimeException('Selection finish control changed.');
            $publishReview();
            self::storeConnection()->table(self::TABLE)->where('request_id', strtolower($run->Request_ID))->update([
                'state' => 'finished', 'finished_at' => $this->utcNow(), 'updated_at' => $this->utcNow(),
            ]);
        });
    }

    /** Caller holds the existing per-run SQL application lock. No MySQL fence spans bulk cleanup. */
    public function complete(string $id): void
    {
        $id = strtolower($id);
        $this->assertReady();
        $control = $this->control($id);
        if ($control === null) throw new RuntimeException('No accepted cancellation receipt exists.');
        if ($control->state === 'cancelled') return;
        if ($control->state !== 'requested') throw new RuntimeException('This cancellation was not accepted.');
        $db = DB::connection('sqlsrv');
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('Rollback selection before completing cancellation.');
        }
        $db->transaction(function () use ($db, $id, $control): void {
            $run = $db->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $id)->lockForUpdate()->first();
            if ($run === null) throw new RuntimeException('The cancelled selection run is missing.');
            $this->assertOwnerScope($run, (string) $control->requested_by);
            $this->assertReceipt($control, $run);
            if ($run->Status === 'discarded' && ($run->Progress_Step ?? null) === self::CANCELLED_STEP) return;
            $failedSelection = $run->Status === 'failed' && ($run->Failed_Step ?? null) === 'select';
            if (! in_array($run->Status, ['queued','selecting'], true) && ! $failedSelection) {
                throw new HttpException(409, 'A completed or approved workflow cannot be cancelled as a selection.');
            }
            if ($db->table('TblSMSHistory')->where('Request_ID', $id)->exists()
                || $db->table('TblSMSDrops')->where('Request_ID', $id)->exists()
                || $db->table('TblProspectsExport')->where('Request_ID', $id)
                    ->where(fn ($q) => $q->whereNotNull('SMS_Drop_PK')->orWhereNotNull('Drop_Name'))->exists()) {
                throw new RuntimeException('Numbered drops or history prevent selection cancellation.');
            }
            $db->table('TblProspectsExport')->where('Request_ID', $id)->delete();
            $db->table('TblSMSRuns')->where('Request_ID', $id)->update([
                'Status' => 'discarded', 'Selected' => 0, 'Never_Texted' => 0, 'Previously_Texted' => 0,
                'Debt_TU' => 0, 'Debt_Mailers' => 0, 'Debt_Tier_Default' => 0, 'Debt_Missing' => 0,
                'Progress_Step' => self::CANCELLED_STEP, 'Closed_By' => $control->requested_by,
                'Closed_At' => Carbon::now()->toDateTimeString(), 'Close_Reason' => 'Selection cancelled by its creator before review.',
                'Failed_Step' => null, 'Error' => null, 'Updated_At' => Carbon::now()->toDateTimeString(),
            ]);
        });
        // If this fails after the SQL commit, the queued recovery command sees
        // the SQL terminal receipt and safely acknowledges it on its next attempt.
        $this->acknowledge($id);
    }

    protected function acknowledge(string $id): void
    {
        $this->storeTransaction(function () use ($id): void {
            $row = self::storeConnection()->table(self::TABLE)->where('request_id', $id)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->state, ['requested','cancelled'], true)) {
                throw new RuntimeException('The accepted cancellation control changed.');
            }
            self::storeConnection()->table(self::TABLE)->where('request_id', $id)->update([
                'state' => 'cancelled', 'finished_at' => $this->utcNow(), 'updated_at' => $this->utcNow(),
            ]);
        });
    }

    public function projection(object $run): ?array
    {
        if (! self::supports($run)) return null;
        if (! in_array($run->Status, ['queued','selecting'], true)
            && ! ($run->Status === 'failed' && ($run->Failed_Step ?? null) === 'select')) return null;
        if (! self::enabled() && ! $this->recoveryStoreExists()) return null;
        $this->assertReady();
        $row = $this->control((string) $run->Request_ID);
        return [
            'supported' => self::enabled(),
            'requested' => $row?->state === 'requested',
            'state' => $row?->state ?? 'active',
            'requested_at' => $this->iso($row?->requested_at),
        ];
    }

    private function control(string $id): ?object
    {
        return self::storeConnection()->table(self::TABLE)->where('request_id', strtolower($id))->first();
    }

    private function fence(object $run, callable $work): mixed
    {
        $this->assertReady();
        $store = self::storeConnection();
        if ($store->transactionLevel() !== 0 || $store->getPdo()->inTransaction()) {
            throw new RuntimeException('Cancellation fence requires its own queue-database transaction.');
        }
        return $this->storeTransaction(function () use ($store, $run, $work): mixed {
            $now = $this->utcNow();
            $store->table(self::TABLE)->insertOrIgnore([
                'request_id' => strtolower($run->Request_ID), 'created_by' => $run->Created_By,
                'options_sha256' => hash('sha256', (string) $run->Run_Options_JSON), 'state' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $control = $store->table(self::TABLE)->where('request_id', strtolower($run->Request_ID))->lockForUpdate()->first();
            if ($control === null) throw new RuntimeException('Cancellation control cannot be locked.');
            $this->assertReceipt($control, $run);
            return $work($control);
        });
    }

    /** Do not leave an HTTP request waiting on a crashed finish fence for MySQL's default 50s. */
    private function storeTransaction(callable $work): mixed
    {
        $db = self::storeConnection();
        $native = $db->getDriverName() === 'mysql';
        $pdo = $db->getPdo();
        $previous = $native ? (int) $db->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value : null;
        if ($native) $db->statement('SET SESSION innodb_lock_wait_timeout='.max(1, min(2, $previous)));
        try {
            return $db->transaction(function () use ($db, $pdo, $work): mixed {
                if ($db->getPdo() !== $pdo) throw new RuntimeException('The cancellation queue connection changed.');
                return $work();
            }, 1);
        } finally {
            if ($native) $db->statement('SET SESSION innodb_lock_wait_timeout='.$previous);
        }
    }

    private function assertReceipt(object $control, object $run): void
    {
        if (strcasecmp((string) $control->created_by, (string) $run->Created_By) !== 0
            || ! hash_equals($control->options_sha256, hash('sha256', (string) $run->Run_Options_JSON))) {
            throw new RuntimeException('Cancellation control no longer matches its frozen run.');
        }
    }

    private function assertOwnerScope(object $run, string $actor): void
    {
        SmsRecordPolicy::assertOrdinary($run);
        if (! self::supports($run)) throw new HttpException(409, 'Only current windowed record selections support cancellation.');
        if (trim($actor) === '' || strcasecmp(trim($actor), trim((string) $run->Created_By)) !== 0) {
            throw new HttpException(403, 'Only the creator can cancel this selection.');
        }
        if (! empty($run->Approved_At) || ! empty($run->Approved_By) || ! empty($run->Sent_At)
            || ! empty($run->Sent_By) || ! empty($run->File_Path) || ! empty($run->File_Cleanup_Path)
            || ! empty($run->_cancellation_history) || ! empty($run->_cancellation_drops)) {
            throw new HttpException(409, 'Approved, numbered, file-ready or historical workflows cannot be cancelled as a selection.');
        }
    }

    /** A dedicated fresh MVCC read cannot wait behind the worker's UPDLOCK row. */
    protected function readRunSnapshot(string $id): object
    {
        if (! preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', strtolower($id))) {
            throw new HttpException(422, 'Use a valid selection request ID.');
        }
        $source = DB::connection('sqlsrv');
        $name = 'sqlsrv_selection_cancellation_read';
        config(['database.connections.'.$name => $source->getConfig()]);
        $read = DB::connection($name);
        $pdo = $read->getPdo();
        if ($pdo === $source->getPdo() || $read->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new RuntimeException('Cancellation needs a separate fresh source snapshot.');
        }
        $native = $read->getDriverName() === 'sqlsrv';
        $timeout = $native ? $pdo->getAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT) : null;
        $lockTimeout = $native ? (int) $read->selectOne('SELECT @@LOCK_TIMEOUT AS value')->value : null;
        $levels = [1 => 'READ UNCOMMITTED', 2 => 'READ COMMITTED', 3 => 'REPEATABLE READ', 4 => 'SERIALIZABLE', 5 => 'SNAPSHOT'];
        $previous = $native ? ($levels[(int) $read->selectOne('SELECT transaction_isolation_level AS value FROM sys.dm_exec_sessions WHERE session_id=@@SPID')->value] ?? null) : null;
        if ($native && $previous === null) throw new RuntimeException('Cannot determine cancellation read isolation.');
        if ($native) {
            $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 5);
            $read->unprepared('SET LOCK_TIMEOUT 0;SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
        }
        try {
            return $read->transaction(function () use ($read, $pdo, $id): object {
                if ($read->getPdo() !== $pdo) throw new RuntimeException('Cancellation snapshot connection changed.');
                $run = $read->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $id)->first();
                if ($run === null) throw new HttpException(404, 'SMS run not found.');
                $run->_cancellation_history = $read->table('TblSMSHistory')->useWritePdo()->where('Request_ID', $id)->exists();
                $run->_cancellation_drops = $read->table('TblSMSDrops')->useWritePdo()->where('Request_ID', $id)->exists();
                return $run;
            }, 1);
        } finally {
            if ($native) {
                $read->unprepared('SET TRANSACTION ISOLATION LEVEL '.$previous.';SET LOCK_TIMEOUT '.$lockTimeout);
                $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $timeout);
            }
        }
    }

    private function response(string $id, string $state, ?string $requestedAt): object
    {
        return (object) ['request_id' => strtolower($id), 'cancellation' => [
            'state' => $state, 'requested' => $state === 'requested', 'requested_at' => $this->iso($requestedAt),
        ]];
    }

    private function iso(?string $date): ?string
    {
        return $date === null ? null : Carbon::parse($date, 'UTC')->toIso8601String();
    }

    private function utcNow(): string
    {
        return Carbon::now('UTC')->format('Y-m-d H:i:s.u');
    }
}
