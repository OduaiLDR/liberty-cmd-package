<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Services\SmsExportArtifacts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** An expiring signed capability is issued only through the actor-scoped status API. */
class SmsExportDownloadController
{
    public function __invoke(Request $request, SmsExportArtifacts $artifacts): BinaryFileResponse
    {
        $data = Validator::make($request->query(), ['request_id' => ['required', 'uuid']])->validate();
        $id = strtolower($data['request_id']);
        $row = DB::connection('sqlsrv')->table('TblSmsExportRequests')->where('Request_ID', $id)->first();
        if ($row === null || $row->Status !== 'ready' || ! str_starts_with((string) $row->Artifact_Key, 'local:'.$id.'.')) {
            throw new HttpException(404, 'A completed SMS export was not found.');
        }
        $tracking = DB::connection('sqlsrv')->table('TblSmsExports')->where('Request_ID', $id);
        if (! $tracking->exists() || (int) $tracking->sum('SMS_Count') !== (int) $row->SMS_Count) {
            throw new HttpException(409, 'SMS tracking needs reconciliation before downloading.');
        }
        try {
            $manifest = $artifacts->verifyLocal($row->Artifact_Key, (int) $row->Artifact_Bytes);
        } catch (\Throwable $error) {
            throw new HttpException(409, 'The recorded SMS file needs reconciliation. Do not regenerate this request.', $error);
        }
        if ($manifest['count'] !== (int) $row->SMS_Count || $manifest['part_count'] !== (int) $row->Part_Count
            || $manifest['format'] !== $row->Artifact_Format) {
            throw new HttpException(409, 'The recorded SMS file differs from its tracking.');
        }
        $response = new BinaryFileResponse($artifacts->localPath($row->Artifact_Key), 200,
            ['Content-Type' => $manifest['format'] === 'zip' ? 'application/zip' : 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'sms_export_'.$id.'.'.$manifest['format']);
        return $response;
    }
}
