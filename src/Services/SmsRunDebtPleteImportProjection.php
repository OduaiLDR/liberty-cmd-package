<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;
use Throwable;

/** Only saved progress is projected. Opening or refreshing a page never starts an import. */
final class SmsRunDebtPleteImportProjection
{
    public static function forRun(array $view, ?array $preview): ?array
    {
        if ($preview === null || config('sms-exports.debtplete_import_enabled', false) !== true
            || ($view['status'] ?? null) !== 'approved'
            || ($view['options']['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) return $preview;
        try {
            return SmsDebtPleteImportPrivacy::run(function () use ($view, $preview): array {
                $status = app(SmsRunDebtPleteImportWorkflow::class)->status($view['request_id']);
                if ($status === null) {
                    $preview['debtplete_import'] = ['status' => 'not_requested', 'processed' => null,
                        'total' => $view['selected'], 'note' => 'No saved automatic-import receipt is available for this run. Past manual imports are not inferred.'];
                    return $preview;
                }
                return self::apply($view, $preview, $status);
            });
        } catch (Throwable) {
            $preview['debtplete_import'] = ['status' => 'unavailable', 'processed' => null, 'total' => $view['selected'] ?? null,
                'note' => 'Saved import progress could not be confirmed. No upload was started by opening this view.'];
            return $preview;
        }
    }

    /** Inputs are from the verified outbox reader, never a caller-supplied remote response. */
    public static function apply(array $view, array $preview, array $status): array
    {
        $selected = $view['selected'] ?? null; $parts = $view['file']['parts'] ?? null;
        $processed = $status['processed'] ?? null; $accepted = $status['files_accepted'] ?? null;
        if (($status['request_id'] ?? null) !== ($view['request_id'] ?? null) || !is_int($selected) || $selected < 1 || $selected > SmsRunOptions::MAX_TARGET
            || ($status['total'] ?? null) !== $selected || !is_int($parts) || $parts < 1 || $parts > 1000
            || ($status['files_total'] ?? null) !== $parts
            || !in_array($status['status'] ?? null, ['queued', 'working', 'pending', 'completed', 'held'], true)
            || !array_key_exists('processed', $status) || !array_key_exists('files_accepted', $status)
            || ($processed !== null && (!is_int($processed) || $processed < 0 || $processed > $selected))
            || ($accepted !== null && (!is_int($accepted) || $accepted < 0 || $accepted > $parts))
            || !in_array($status['remote_status'] ?? null, [null, 'queued', 'importing', 'completed', 'failed'], true)
            || !is_bool($status['retry_pending'] ?? null)
            || (($processed === null) !== ($accepted === null))
            || (($status['remote_status'] ?? null) === null && ($processed !== null || $accepted !== null))
            || (($status['remote_status'] ?? null) !== null && ($processed === null || $accepted === null))
            || (in_array($status['status'], ['completed', 'held'], true) && $status['retry_pending'] !== false)
            || ($status['status'] === 'completed' && ($processed !== $selected || $accepted !== $parts || $status['remote_status'] !== 'completed'))) {
            throw new RuntimeException('The saved SMS import projection could not be confirmed.');
        }
        $label = $status['status'] === 'completed' ? 'completed' : ($status['status'] === 'held' ? 'failed'
            : (($status['remote_status'] ?? null) === 'importing' ? 'importing' : 'queued'));
        $note = $label === 'completed'
            ? 'The receiver confirmed all approved records were imported. This does not confirm SMS delivery.'
            : ($label === 'failed' ? 'Import is paused for review. Its original files and last confirmed progress are retained.'
                : 'Last confirmed receiver progress. Background work updates this saved status; opening the page starts no upload.');
        $preview['debtplete_import'] = ['status' => $label, 'processed' => $processed, 'total' => $selected,
            'files_accepted' => $accepted, 'files_total' => $parts, 'note' => $note];
        return $preview;
    }
}

