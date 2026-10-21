<?php

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Small idempotent chunks: the browser can resume a large provider CSV without a huge upload. */
class SmsFeedbackService
{
    public function import(string $requestId, int $chunk, array $records, string $actor): array
    {
        if (count($records) < 1 || count($records) > 500 || $chunk < 0) {
            throw ValidationException::withMessages(['records' => 'Import 1–500 feedback rows per chunk.']);
        }
        $rows = [];
        foreach ($records as $i => $record) {
            $phone = SmsProspectValues::phone($record['phone'] ?? '');
            $status = strtolower(trim((string) ($record['status'] ?? '')));
            if (! $phone || ! in_array($status, ['opt_out', 'invalid_phone'], true)) {
                throw ValidationException::withMessages(['records' => 'Row '.($i + 1).' must have a US phone and status opt_out or invalid_phone. No rows in this chunk were imported.']);
            }
            $rows[] = ['phone' => $phone, 'status' => $status];
        }
        $hash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        return SmsWorkflowLock::run('phone-suppression', fn () => DB::connection('sqlsrv')->transaction(function () use ($requestId, $chunk, $rows, $hash, $actor): array {
            $db = DB::connection('sqlsrv');
            $log = $db->table('TblSMSFeedbackImports')->where('Request_ID', $requestId)->where('Chunk', $chunk)->first();
            if ($log) {
                if (! hash_equals($log->Content_Hash, $hash)) throw new HttpException(409, 'This import chunk was already used for different feedback.');
                return ['records' => (int) $log->Records, 'matched' => (int) $log->Matched, 'updated' => (int) $log->Updated, 'replayed' => true];
            }
            $phones = array_values(array_unique(array_column($rows, 'phone')));
            $suppressed = $db->table('TblSMSSuppressions')->whereIn('Phone', $phones)->get()->keyBy('Phone');
            $flags = [];
            foreach ($rows as $row) {
                $phone = $row['phone'];
                $flags[$phone] ??= ['Phone' => $phone, 'Opt_Out' => (int) ($suppressed->get($phone)->Opt_Out ?? 0),
                    'Invalid_Phone' => (int) ($suppressed->get($phone)->Invalid_Phone ?? 0), 'Updated_At' => now()->toDateTimeString()];
                $flags[$phone][$row['status'] === 'opt_out' ? 'Opt_Out' : 'Invalid_Phone'] = 1;
            }
            // Keep suppressions even when no prospect exists yet. A later purchase/import of
            // the same number must not make an opted-out phone eligible again.
            $suppressionChanged = false;
            foreach ($flags as $phone => $flag) {
                $before = $suppressed->get($phone);
                if ($before === null || (int) $before->Opt_Out !== $flag['Opt_Out'] || (int) $before->Invalid_Phone !== $flag['Invalid_Phone']) {
                    $suppressionChanged = true;
                }
            }
            foreach (array_chunk(array_values($flags), 250) as $part) {
                $db->table('TblSMSSuppressions')->upsert($part, ['Phone'], ['Opt_Out', 'Invalid_Phone', 'Updated_At']);
            }
            $matched = $db->table('TblProspects')->whereIn('Phone', $phones)->distinct()->count('Phone');
            $updated = 0;
            foreach (['opt_out' => 'Opt_Out', 'invalid_phone' => 'Invalid_Phone'] as $status => $field) {
                $numbers = array_values(array_unique(array_column(array_filter($rows, fn ($row) => $row['status'] === $status), 'phone')));
                if ($numbers) $updated += $db->table('TblProspects')->whereIn('Phone', $numbers)->where($field, 0)->update([$field => 1]);
            }
            $db->table('TblSMSFeedbackImports')->insert(['Request_ID' => $requestId, 'Chunk' => $chunk, 'Content_Hash' => $hash,
                'Records' => count($rows), 'Matched' => $matched, 'Updated' => $updated, 'Created_By' => $actor, 'Created_At' => now()->toDateTimeString()]);
            if (! SmsAvailabilityRefresh::durableEnabled() || $updated > 0 || $suppressionChanged) SmsAvailabilityRefresh::afterCommit($db);
            return ['records' => count($rows), 'matched' => $matched, 'updated' => $updated, 'replayed' => false];
        }));
    }
}
