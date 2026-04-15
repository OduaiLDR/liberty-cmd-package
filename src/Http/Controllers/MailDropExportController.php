<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\MailDropExportRequest;
use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MailDropExportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected MailDropExportRepository $repository
    ) {
    }

    public function index(): View
    {
        $drops = $this->repository->allDrops();

        return view('reports::reports.mail_drop_export', [
            'drops' => $drops,
        ]);
    }

    public function export(MailDropExportRequest $request): StreamedResponse
    {
        // 1. Remove execution limits so it won't crash on huge data.
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        // 2. Remove memory limits. If the sqlsrv driver buffers too much locally,
        // a memory limit crash will corrupt the file.
        ini_set('memory_limit', '-1');

        $pks = array_values(array_map('intval', $request->input('pks', [])));

        return $this->exportCsv($pks);
    }

    protected function exportCsv(array $pks): StreamedResponse
    {
        $filename = 'mail_drop_export_' . Carbon::now()->format('Ymd_His') . '.csv';

        // Match the exact headers used by other working CSV reports.
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Pragma' => 'public',
            'X-Accel-Buffering' => 'no',
        ];

        return response()->streamDownload(function () use ($pks) {
            set_time_limit(0);
            $rowsWritten = 0;
            $out = null;

            try {
                $out = fopen('php://output', 'w');

                // UTF-8 BOM ensures Microsoft Excel opens the CSV as UTF-8.
                fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

                fputcsv($out, [
                    'Drop TID',
                    'Drop Name',
                    'Client',
                    'External ID',
                    'Phone',
                    'Email',
                    'Address',
                    'City',
                    'State',
                    'Zip',
                    'Send Date',
                ]);

                foreach ($this->repository->exportRows($pks) as $row) {
                    fputcsv($out, [
                        $row->Drop_PK     ?? '',
                        $row->Drop_Name   ?? '',
                        $row->Client      ?? '',
                        $row->External_ID ?? '',
                        $row->Phone       ?? '',
                        $row->Email       ?? '',
                        $row->Address     ?? '',
                        $row->City        ?? '',
                        $row->State       ?? '',
                        $row->Zip         ?? '',
                        $this->formatCsvDate($row->Send_Date),
                    ]);

                    $rowsWritten++;

                    if ($rowsWritten % 1000 === 0) {
                        fflush($out);
                    }
                }

                $this->repository->logExport($pks);
            } catch (Throwable $e) {
                Log::error('Mail drop CSV export failed.', [
                    'rows_written' => $rowsWritten,
                    'exception' => $e,
                ]);

                throw $e;
            } finally {
                if (is_resource($out)) {
                    fclose($out);
                }
            }
        }, $filename, $headers);
    }
}
