<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Throwable;

/** Bounded read-only projection. Never resolve recipients, create links or queue mail. */
final class SmsRunEmailProjection
{
    public static function forRun(array $view): ?array
    {
        $preview = SmsRunHandoffPreview::forRun($view);
        $preview = SmsRunPublicationProjection::forRun($view, $preview);
        if ($preview === null || config('sms-exports.email_handoff_enabled', false) !== true
            || ($view['status'] ?? null) !== 'approved'
            || ($view['options']['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) {
            return $preview;
        }
        try {
            $emails = app(SmsRunEmailWorkflow::class);
            $status = $emails->status($view['request_id']);
            return self::apply($preview, $status, $status === null ? null : $emails->preparedMessage($view['request_id']));
        } catch (Throwable) {
            $preview['email'] = ['status' => 'unavailable', 'note' => 'The saved email state could not be verified. No email was initiated by this read; check the private outbox before attempting delivery.'];
            $preview['email_preview']['available'] = false;
            $preview['email_preview']['body'] = null;
            return $preview;
        }
    }

    /** Inputs must come from the verified outbox reader, never a browser request. */
    public static function apply(array $preview, ?array $status, ?array $message): array
    {
        if ($status === null) {
            $preview['email'] = ['status' => 'awaiting_links', 'note' => 'No tracked email is prepared. Approved external file links and the scoped TblReports recipient row are required. Approval alone does not send an email.'];
            return $preview;
        }
        if (! in_array($status['status'] ?? null, ['ready', 'sending', 'accepted', 'uncertain'], true)
            || ! is_string($status['payload_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $status['payload_sha256'])
            || ! is_array($message)) {
            throw new \RuntimeException('Invalid saved SMS email projection.');
        }
        $preview['email'] = array_intersect_key($status, array_flip([
            'status', 'payload_sha256', 'note', 'created_at', 'attempted_at', 'accepted_at',
        ]));
        $replacements = [];
        $files = [];
        foreach ($message['files'] as $part) {
            $replacements[$part['url']] = '[Approved external link stored]';
            $files[] = ['name' => $part['name'], 'rows' => $part['rows'], 'link' => null];
        }
        $preview['email_preview'] = [
            'from' => $message['from'], 'to' => $message['to'], 'cc' => $message['cc'],
            'bcc' => [], 'bcc_configured' => $message['bcc'] !== [],
            'body' => strtr($message['text'], $replacements), 'files' => $files, 'available' => true, 'reason' => null,
        ];
        return $preview;
    }
}
