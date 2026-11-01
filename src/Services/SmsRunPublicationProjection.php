<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;
use Throwable;

/** Counts only. Never return provider identities, folder paths, raw links or receipts. */
final class SmsRunPublicationProjection
{
    public static function forRun(array $view, ?array $handoff): ?array
    {
        if ($handoff === null) return null;
        $handoff['file_publication'] = ['status' => 'not_configured', 'note' => 'External file publishing is disabled or not configured. Opening this view does not upload files or establish past provider activity.'];
        if (config('sms-exports.file_publication_enabled', false) !== true || ($view['status'] ?? null) !== 'approved'
            || ($view['options']['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) return $handoff;
        try {
            return self::apply($view, $handoff, app(SmsRunPublicationWorkflow::class)->status($view['request_id']));
        } catch (Throwable) {
            $handoff['file_publication'] = ['status' => 'unavailable', 'note' => 'The saved publication progress could not be verified. No upload or provider check was initiated by this read.'];
            return $handoff;
        }
    }

    public static function apply(array $view, array $handoff, ?array $state): array
    {
        if ($state === null) {
            $handoff['file_publication'] = ['status' => 'awaiting_publish', 'note' => 'This approved run has no tracked publication. Explicit publication requires the reviewed account, folder and sharing policy.'];
            return $handoff;
        }
        if (! in_array($state['status'] ?? null, ['ready', 'publishing', 'published', 'failed', 'uncertain'], true)) {
            throw new RuntimeException('Invalid SMS publication status.');
        }
        foreach (['files_done', 'files_total', 'rows_done', 'rows_total'] as $field) {
            if (! is_int($state[$field] ?? null) || $state[$field] < 0) throw new RuntimeException('Invalid SMS publication count.');
        }
        if ($state['files_total'] < 1 || $state['files_total'] > SmsRunArtifactBundle::MAX_PARTS
            || $state['files_done'] > $state['files_total'] || $state['rows_done'] > $state['rows_total']
            || $state['files_total'] !== ($view['file']['parts'] ?? null) || $state['rows_total'] !== ($view['selected'] ?? null)
            || ($state['status'] === 'published' && ($state['files_done'] !== $state['files_total'] || $state['rows_done'] !== $state['rows_total']))) {
            throw new RuntimeException('SMS publication counts differ from the approved export.');
        }
        $handoff['file_publication'] = array_intersect_key($state, array_flip(['status', 'note', 'files_done', 'files_total', 'rows_done', 'rows_total']));
        return $handoff;
    }
}
