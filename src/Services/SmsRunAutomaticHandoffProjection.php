<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Throwable;

/**
 * Tells the page that publishing and the email run by themselves after approval, so it can say so and keep
 * reading the saved progress instead of showing the snapshot from the moment of approval (Monther, Nov 17, 2026:
 * "i clicked approve and the page show like this", with "Awaiting publication" on a handoff that had finished).
 * Read-only. It starts nothing, and any failure leaves the view exactly as it was.
 */
final class SmsRunAutomaticHandoffProjection
{
    public static function forRun(array $view, ?array $handoff): ?array
    {
        if ($handoff === null || ($view['status'] ?? null) !== 'approved'
            || ($view['options']['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION
            || config('sms-exports.handoff_automatic', false) !== true
            || config('sms-exports.file_publication_enabled', false) !== true
            || config('sms-exports.email_handoff_enabled', false) !== true
            || ! is_string($view['request_id'] ?? null)) return $handoff;
        // Finished already: nothing to add, and no extra read.
        if (($handoff['file_publication']['status'] ?? null) === 'published' && ($handoff['email']['status'] ?? null) === 'accepted') {
            $handoff['automatic'] = ['status' => 'completed'];

            return $handoff;
        }
        try {
            $state = app(SmsRunAutomaticHandoffWorkflow::class)->status(strtolower($view['request_id']));
        } catch (Throwable) {
            return $handoff;
        }
        $status = $state['status'] ?? null;
        // No saved automatic handoff for this batch (for example it was approved before the switch was on).
        if (! in_array($status, ['pending', 'working', 'completed', 'held'], true)) return $handoff;
        if ($status === 'completed') {
            $handoff['automatic'] = ['status' => 'completed'];

            return $handoff;
        }
        if ($status === 'held') {
            $handoff['automatic'] = ['status' => 'held', 'note' => 'The automatic handoff stopped and needs to be checked. Nothing is retried by this page.'];

            return $handoff;
        }
        $handoff['automatic'] = ['status' => 'in_progress', 'note' => 'Publishing and the email run by themselves after approval. This is in progress.'];
        if (($handoff['file_publication']['status'] ?? null) === 'awaiting_publish') {
            $handoff['file_publication']['note'] = 'The approved files are being published automatically. Nothing needs to be done here.';
        }
        if (($handoff['email']['status'] ?? null) === 'awaiting_links') {
            $handoff['email']['note'] = 'The email is prepared and sent automatically once the files are published.';
        }

        return $handoff;
    }
}
