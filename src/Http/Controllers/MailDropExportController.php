<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Http\Requests\MailDropExportRequest;
use Cmd\Reports\Repositories\MailDropExportRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MailDropExportController extends Controller
{
    public function __construct(protected MailDropExportRepository $repository)
    {
    }

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'target' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $target = (int) ($validated['target'] ?? 0);
        $drops = $target > 0
            ? $this->repository->selectDrops($target)
            : $this->repository->allDrops((int) ($validated['page'] ?? 1));

        return view('reports::reports.mail_drop_export', [
            'drops' => $drops, 'target' => $target, 'total' => (int) $drops->sum('Amount_Dropped'),
            'requestId' => (string) Str::uuid(), 'page' => (int) ($validated['page'] ?? 1),
        ]);
    }

    public function export(MailDropExportRequest $request): BinaryFileResponse
    {
        set_time_limit(0);
        $data = $request->validated();
        $export = $this->repository->prepareExport((int) $data['target'], $data['request_id']);

        clearstatcache(true, $export['path']);

        return response()->download($export['path'], 'sms_export_'.now()->format('Ymd_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-SMS-Count' => (string) $export['count'],
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }
}
