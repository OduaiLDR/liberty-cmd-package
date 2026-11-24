<?php

declare(strict_types=1);

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Http\Requests\ProspectListRequest;
use Cmd\Reports\Jobs\BuildProspectList;
use Cmd\Reports\Repositories\ProspectListRepository;
use Cmd\Reports\Services\ProspectExportInspector;
use Cmd\Reports\Services\ProspectListFields;
use Cmd\Reports\Services\ProspectListRuns;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToReadFile;
use Throwable;

final class ProspectListController
{
    public function catalog(): JsonResponse
    {
        return response()->json(ProspectListFields::catalog());
    }

    public function count(ProspectListRequest $request): JsonResponse
    {
        return $this->submit($request, null, now('America/Los_Angeles')->toDateString());
    }

    public function export(ProspectListRequest $request): JsonResponse
    {
        $count = $this->find($request, $request->validated('count_id'));
        abort_unless($count['kind'] === 'count' && $count['status'] === 'completed'
            && $count['count'] > 0
            && $count['filter_hash'] === ProspectListRuns::fingerprint($request->validated('filters')),
            409, 'Count the current filters before creating a file.');

        $rowLimit = (int) $request->validated('row_limit');
        if ($rowLimit > $count['count']) {
            throw ValidationException::withMessages(['row_limit' => 'Enter a row count that does not exceed the number of matches.']);
        }

        $rowsPerFile = $request->validated('rows_per_file');

        return $this->submit($request, $request->validated('columns'), $count['as_of'], $rowLimit, $rowsPerFile === null ? null : (int) $rowsPerFile);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->publicState($this->find($request, $id)));
    }

    public function download(Request $request, string $id, ?string $part = null): JsonResponse
    {
        $run = $this->find($request, $id);
        abort_unless($run['kind'] === 'export' && $run['status'] === 'completed', 404);
        $fileCount = $run['file_count'] ?? (isset($run['export_summary']['tcpa_scrub']) && $run['count'] === 0 ? 0 : 1);
        $partNumber = $part === null ? 1 : filter_var($part, FILTER_VALIDATE_INT);
        abort_unless($partNumber !== false && $partNumber >= 1 && $partNumber <= $fileCount, 404);

        $split = isset($run['rows_per_file']);
        $path = ProspectListRuns::artifact($this->key($request, $id), $split ? $partNumber : null);
        $fileName = 'prospect-list-'.$run['id'].($split ? '-part-'.$partNumber : '').'.csv';
        $disk = Storage::disk(config('prospect-list-builder.disk'));
        abort_unless($disk->exists($path), 404);

        return response()->json([
            'url' => $disk->temporaryUrl($path, now()->addMinutes(5), [
                'ResponseContentDisposition' => 'attachment; filename="'.$fileName.'"',
            ]),
        ])->header('Cache-Control', 'no-store');
    }

    public function inspect(ProspectListRequest $request, string $id, ProspectExportInspector $inspector, ProspectListRepository $prospects): JsonResponse
    {
        $run = $this->find($request, $id);
        abort_unless($run['status'] === 'completed', 404);
        $countPreview = $run['kind'] === 'count';
        $columns = $countPreview ? $request->validated('columns') : $run['columns'];
        abort_unless(is_array($columns), 404);
        $field = $request->validated('field');
        $position = array_search($field, $columns, true);
        if ($position === false) {
            throw ValidationException::withMessages(['field' => 'Choose a column selected for this CSV.']);
        }

        $filter = $request->validated('filter');
        if ($countPreview) {
            $filters = $request->validated('filters');
            abort_unless($run['count'] > 0 && $run['filter_hash'] === ProspectListRuns::fingerprint($filters),
                409, 'Count the current filters before previewing your rows.');
            $plannedRows = min($run['count'], (int) $request->validated('row_limit', $run['count']));
            $previewRows = $prospects->preview($filters, $columns, Carbon::parse($run['as_of']), min(ProspectExportInspector::PREVIEW_ROW_LIMIT, $plannedRows));
            $inspection = $inspector->preview($previewRows, $columns, $position, $filter, $plannedRows);
        } else {
            $type = ProspectListFields::columns()[$field][3];
            $streams = $this->savedFiles($this->key($request, $id), $run);
            $inspection = $inspector->inspectFiles($streams, $columns, $position, $type, $filter, (int) $run['count']);
        }

        return response()->json($inspection, headers: ['Cache-Control' => 'private, no-store'], options: JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function savedFiles(string $key, array $run): Generator
    {
        $disk = Storage::disk(config('prospect-list-builder.disk'));
        $fileCount = $run['file_count'] ?? 1;
        abort_if($fileCount === 0, 404);
        for ($part = 1; $part <= $fileCount; $part++) {
            $path = ProspectListRuns::artifact($key, isset($run['rows_per_file']) ? $part : null);
            try {
                $stream = $disk->readStream($path);
            } catch (UnableToReadFile) {
                abort(404);
            }
            abort_unless(is_resource($stream), 404);
            try {
                yield $stream;
            } finally {
                fclose($stream);
            }
        }
    }

    private function submit(ProspectListRequest $request, ?array $columns, string $asOfDate, ?int $rowLimit = null, ?int $rowsPerFile = null): JsonResponse
    {
        $connection = config('queue.default');
        $timeout = $columns === null ? BuildProspectList::COUNT_TIMEOUT_SECONDS : BuildProspectList::EXPORT_TIMEOUT_SECONDS;
        abort_unless($connection === 'database'
            && (int) config('queue.connections.database.retry_after') > $timeout,
            503, 'Prospect list queue is unavailable.');

        $id = strtolower($request->validated('id'));
        $filters = $request->validated('filters');
        $key = $this->key($request, $id);
        $store = ProspectListRuns::store();
        $run = [
            'id' => $id,
            'kind' => $columns === null ? 'count' : 'export',
            'status' => 'pending',
            'count_stage' => $columns === null ? 'queued' : null,
            'filter_hash' => ProspectListRuns::fingerprint($filters),
            'columns' => $columns,
            'row_limit' => $rowLimit,
            'rows_per_file' => $rowsPerFile,
            'as_of' => $asOfDate,
        ];

        if ($store->add($key, $run, ProspectListRuns::RETENTION_SECONDS)) {
            try {
                BuildProspectList::dispatch(
                    $key,
                    $request->header('X-Prospect-Tenant'),
                    $request->header('X-Prospect-Actor'),
                    $id,
                    $filters,
                    $columns,
                    $asOfDate,
                    $rowLimit,
                    $columns !== null && (bool) config('prospect-list-builder.tcpa_scrub.enabled'),
                    $rowsPerFile,
                );
            } catch (Throwable $error) {
                $store->forget($key);
                throw $error;
            }
        } else {
            $existing = $this->find($request, $id);
            abort_unless($existing['filter_hash'] === $run['filter_hash']
                && $existing['columns'] === $columns && ($existing['row_limit'] ?? null) === $rowLimit
                && ($existing['rows_per_file'] ?? null) === $rowsPerFile
                && $existing['kind'] === $run['kind'], 409);
            $run = $existing;
        }

        return response()->json($this->publicState($run), 202);
    }

    private function key(Request $request, string $id): string
    {
        return ProspectListRuns::key($request->header('X-Prospect-Tenant'), $request->header('X-Prospect-Actor'), $id);
    }

    private function find(Request $request, string $id): array
    {
        $run = ProspectListRuns::store()->get($this->key($request, $id));
        abort_unless(is_array($run), 404);

        return $run;
    }

    private function publicState(array $run): array
    {
        return [
            'id' => $run['id'],
            'kind' => $run['kind'],
            'status' => $run['status'],
            'count' => $run['count'] ?? null,
            'count_stage' => $run['count_stage'] ?? null,
            'diagnostics' => $run['diagnostics'] ?? null,
            'export_summary' => $run['export_summary'] ?? null,
            'failure_reason' => $run['failure_reason'] ?? null,
            'file_count' => $run['file_count'] ?? null,
            'rows_per_file' => $run['rows_per_file'] ?? null,
        ];
    }
}
