<?php

declare(strict_types=1);

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Repositories\ProspectListRepository;
use Cmd\Reports\Services\ProspectListCsvFiles;
use Cmd\Reports\Services\ProspectListFields;
use Cmd\Reports\Services\ProspectListRuns;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\TcpaLitigatorScrubber;
use Cmd\Reports\Services\TcpaScrubUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class BuildProspectList implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const COUNT_TIMEOUT_SECONDS = 330;
    public const EXPORT_TIMEOUT_SECONDS = SmsExportQueue::JOB_TIMEOUT;

    public int $tries = 1;
    public int $timeout;
    public bool $failOnTimeout = true;

    public function __construct(
        public string $key,
        public string $tenant,
        public string $actor,
        public string $id,
        public array $filters,
        public ?array $columns,
        public string $asOfDate,
        public ?int $rowLimit = null,
        public bool $requireTcpaScrub = false,
        public ?int $rowsPerFile = null,
    ) {
        $this->timeout = $columns === null ? self::COUNT_TIMEOUT_SECONDS : self::EXPORT_TIMEOUT_SECONDS;
        $this->onQueue(config('prospect-list-builder.queue', 'tu-export'));
    }

    public function handle(ProspectListRepository $prospects): void
    {
        $store = ProspectListRuns::store();
        $store->lock($this->key.':work', $this->timeout + 60)->get(function () use ($store, $prospects): void {
            $run = $store->get($this->key);
            if (! is_array($run) || $run['status'] !== 'pending') {
                return;
            }

            $asOf = Carbon::parse($this->asOfDate, 'America/Los_Angeles');
            if ($this->columns === null) {
                $store->put($this->key, [...$run, 'count_stage' => 'counting'], ProspectListRuns::RETENTION_SECONDS);
                $count = $prospects->count($this->filters, $asOf);
                $store->put($this->key, [...$run, 'count_stage' => 'summarizing', 'count' => $count], ProspectListRuns::RETENTION_SECONDS);
                $diagnostics = null;
                try {
                    $diagnostics = $prospects->diagnostics($this->filters, $asOf);
                } catch (QueryException $error) {
                    report($error);
                }
                $result = ['count' => $count, 'diagnostics' => $diagnostics, 'count_stage' => 'complete'];
            } elseif (config('prospect-list-builder.stamp_exports')) {
                $result = $store->lock('prospect-list:shared-export', $this->timeout + 60)
                    ->block(15, fn (): array => $this->export($prospects, $asOf, true));
            } else {
                $result = $this->export($prospects, $asOf, false);
            }

            $store->put($this->key, [...$run, ...$result, 'status' => 'completed'], ProspectListRuns::RETENTION_SECONDS);
        });
    }

    /** @return array{count: int, file_count: int, rows_per_file: int|null, export_summary: array{fields: list<array{id: string, label: string, present: int}>, states: array<string, int>, tcpa_scrub?: array{matched: int, invalid_phone: int}}} */
    private function export(ProspectListRepository $prospects, Carbon $asOf, bool $stamp): array
    {
        $columns = $this->columns ?? [];
        $scrubber = ($this->requireTcpaScrub ?? false) || config('prospect-list-builder.tcpa_scrub.enabled')
            ? app(TcpaLitigatorScrubber::class)
            : null;
        $queryColumns = $columns;
        if ($scrubber !== null && ! in_array('phone', $queryColumns, true)) {
            $queryColumns[] = 'phone';
        }
        $scrubSummary = ['matched' => 0, 'invalid_phone' => 0];
        $idStream = null;
        $count = 0;
        $definitions = ProspectListFields::columns();
        $headers = array_map(static fn (string $field): string => $definitions[$field][0], $columns);
        $rowsPerFile = $this->rowsPerFile ?? null;
        $files = new ProspectListCsvFiles(Storage::disk(config('prospect-list-builder.disk')), $this->key, $headers, $rowsPerFile);
        $present = array_fill_keys($columns, 0);
        $states = [];
        $stateIndex = array_search('state', $columns, true);
        try {
            if ($stamp && ($idStream = tmpfile()) === false) {
                throw new RuntimeException('Unable to track prospect list history.');
            }
            // Jobs already queued before this change have no requested size.
            $prospects->eachBatch($this->filters, $queryColumns, $asOf, $this->rowLimit ?? 10_000,
                function (Collection $rows) use ($columns, $definitions, $stateIndex, $files, $idStream, $scrubber, &$scrubSummary, &$count, &$present, &$states): void {
                    if ($scrubber !== null) {
                        $scrubbed = $scrubber->filter($rows);
                        $scrubSummary['matched'] += $scrubbed['matched'];
                        $scrubSummary['invalid_phone'] += $scrubbed['invalid_phone'];
                        $rows = $scrubbed['rows'];
                    }
                    foreach ($rows as $row) {
                        $cells = [];
                        foreach ($columns as $field) {
                            $value = ProspectListFields::csvCell($row, $field, $definitions[$field][3]);
                            $cells[] = $value;
                            if (trim((string) $value) !== '') {
                                $present[$field]++;
                            }
                        }
                        if ($stateIndex !== false) {
                            $state = strtoupper(trim((string) $cells[$stateIndex]));
                            if ($state === '') {
                                $state = 'Blank';
                            } elseif (preg_match('/\A[A-Z]{2}\z/', $state) !== 1) {
                                $state = 'Other';
                            }
                            $states[$state] = ($states[$state] ?? 0) + 1;
                        }
                        $files->write($cells);
                    }
                    if (is_resource($idStream) && $rows->isNotEmpty()) {
                        $this->writeRow($idStream, $rows->pluck('prospect_id')->all());
                    }
                    $count += $rows->count();
                });
            $fileCount = $files->finish(allowEmpty: $scrubber === null);

            if (is_resource($idStream) && $count > 0) {
                rewind($idStream);
                $prospects->stamp($this->idBatches($idStream), now('America/Los_Angeles'));
            }
        } catch (Throwable $error) {
            try {
                $files->discard();
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }
            throw $error;
        } finally {
            $files->close();
            if (is_resource($idStream)) {
                fclose($idStream);
            }
        }

        $summary = [
            'fields' => array_map(static fn (string $id, string $label): array => [
                'id' => $id, 'label' => $label, 'present' => $present[$id],
            ], $columns, $headers),
            'states' => $states,
        ];
        if ($scrubber !== null) {
            $summary['tcpa_scrub'] = $scrubSummary;
        }

        Log::info('Prospect list exported.', [
            'id' => $this->id, 'tenant' => $this->tenant, 'actor' => $this->actor,
            'filter_keys' => array_keys($this->filters), 'field_filters' => array_keys($this->filters['fields'] ?? []),
            'columns' => $columns, 'row_limit' => $this->rowLimit ?? null, 'count' => $count,
            'tcpa_scrub' => $summary['tcpa_scrub'] ?? null,
            'file_count' => $fileCount, 'rows_per_file' => $rowsPerFile,
        ]);

        arsort($summary['states']);

        return ['count' => $count, 'file_count' => $fileCount, 'rows_per_file' => $rowsPerFile, 'export_summary' => $summary];
    }

    /** @param resource $stream */
    private function idBatches($stream): iterable
    {
        while (($ids = fgetcsv($stream, escape: '')) !== false) {
            yield array_map(intval(...), $ids);
        }
    }

    /** @param resource $stream */
    private function writeRow($stream, array $row): void
    {
        if (fputcsv($stream, $row, escape: '') === false) {
            throw new RuntimeException('Unable to write the prospect list.');
        }
    }

    public function failed(?Throwable $error): void
    {
        $store = ProspectListRuns::store();
        $run = $store->get($this->key);
        if (is_array($run) && $run['status'] === 'pending') {
            $run['status'] = 'failed';
            if ($error instanceof TcpaScrubUnavailable) {
                $run['failure_reason'] = 'tcpa_scrub';
            }
            $store->put($this->key, $run, ProspectListRuns::RETENTION_SECONDS);
            if ($error instanceof TimeoutExceededException && ($this->rowsPerFile ?? null) !== null) {
                Storage::disk(config('prospect-list-builder.disk'))->deleteDirectory(ProspectListRuns::artifactDirectory($this->key));
            }
        }
    }
}
