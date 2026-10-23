<?php

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Cmd\Reports\Jobs\CountDurableSmsAvailabilityJob;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

/** Coordinates small durable records only. HTTP never runs the prospect aggregate. */
class SmsDurableAvailabilityService
{
    public function __construct(private SmsAvailabilityLedger $ledger) {}

    public function request(bool $force = false): array
    {
        $row = null;
        try {
            // Check before claiming: a missing worker/async queue must not leave an
            // apparently dispatched generation. The scheduler can retry after repair.
            app(SmsExportQueue::class)->assertReady();
            $claim = $this->ledger->claim($force);
            if ($claim !== null) {
                try {
                    app(Dispatcher::class)->dispatch(new CountDurableSmsAvailabilityJob($claim['scope'], $claim['generation']));
                } catch (Throwable $error) {
                    $this->ledger->fail($claim['scope'], $claim['generation']);
                    throw $error;
                }
            }
            $row = $this->ledger->read();
            return $this->response($row) + ($claim !== null ? ['queued_now' => true] : []);
        } catch (Throwable $error) {
            // A queue or cache outage must not hide the last successfully stored count.
            try { $row = $this->ledger->read(); } catch (Throwable) {}
            return $this->response($row, true);
        }
    }

    public function count(string $scope, string $generation, SmsProspectExportRepository $export): void
    {
        if ($this->ledger->start($scope, $generation) === null) return;
        try {
            // One consistent snapshot, including phone/history/suppression/reservation
            // inputs. Never label a mixed read of concurrent writes as a fresh count.
            $counts = $export->availabilitySnapshotWithTimeout();
            $this->ledger->complete($scope, $generation, $counts);
        } catch (Throwable $error) {
            $this->ledger->fail($scope, $generation);
            throw $error;
        }
        // A committed eligibility write during the query invalidates its publication.
        // The next generation coalesces here; the scheduler also recovers process loss.
        $this->request();
    }

    public function fail(string $scope, string $generation): void
    {
        $this->ledger->fail($scope, $generation);
    }

    private function response(?array $row, bool $unavailable = false): array
    {
        $status = $row['status'] ?? 'idle';
        $fresh = $row !== null && $status === 'ready'
            && (int) $row['revision'] === (int) $row['published_revision']
            && $row['counted_at'] !== null
            && Carbon::parse($row['counted_at'], 'UTC')->addSeconds(SmsAvailabilityLedger::FRESH_SECONDS)->isFuture();
        $state = $unavailable || $status === 'failed' ? 'failed' : ($fresh ? 'ready' : 'pending');
        $response = ['state' => $state, 'summary_storage' => 'database',
            'selection_policy' => SmsRecordPolicy::current(),
            // Kept unknown until a validated TU-completion contract supplies the date.
            'latest_data_date' => $row['latest_data_date'] ?? null];
        if ($state === 'failed') $response['message'] = 'Could not refresh available people. The background refresh will retry.';
        if ($row !== null && $row['requested_at'] !== null) $response['requested_at'] = $this->iso($row['requested_at']);
        if ($row !== null && $row['counted_at'] !== null) {
            foreach (['eligible', 'never_texted', 'texted_before'] as $key) $response[$key] = (int) $row[$key];
            $response['counted_at'] = $this->iso($row['counted_at']);
            if ($state !== 'ready') $response['stale'] = true;
        }
        return $response;
    }

    private function iso(string $date): string
    {
        return Carbon::parse($date, 'UTC')->toIso8601String();
    }
}
