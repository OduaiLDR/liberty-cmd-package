<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The current To and CC list for the draft email preview of an open batch (asked for by Monther, Nov 17, 2026).
 * Display only: the email itself still takes its list from TblReports at the moment it is prepared, and that
 * prepared list is the one that is sent. Read-only, kept for a few minutes so page refreshes do not each open a
 * connection, and any failure simply means "not known" (the page then says the list is loaded later).
 */
final class SmsRunEmailRecipientPreview
{
    private const KEEP_SECONDS = 300;
    private const FAILED_SECONDS = 60;

    /** @return array{to: list<string>, cc: list<string>, bcc_configured: bool}|null */
    public static function current(): ?array
    {
        $key = 'sms-email-recipient-preview:'.sha1(json_encode([
            config('sms-exports.email_report_name', 'SMS Export'), config('sms-exports.email_report_company'),
            config('sms-exports.email_report_source', 'plaw'),
        ]) ?: '');
        try {
            $saved = Cache::get($key);
            if ($saved === 'failed') return null;
            if (self::valid($saved)) return $saved;
        } catch (Throwable) {
            // No cache here: read the list directly.
        }
        try {
            $configuration = app(SmsRunEmailGateway::class)->configuration();
            SmsRunEmailMessage::assertConfiguration($configuration);
            $current = ['to' => array_values($configuration['to']), 'cc' => array_values($configuration['cc']),
                'bcc_configured' => $configuration['bcc'] !== []];
        } catch (Throwable) {
            try { Cache::put($key, 'failed', self::FAILED_SECONDS); } catch (Throwable) {}

            return null;
        }
        try { Cache::put($key, $current, self::KEEP_SECONDS); } catch (Throwable) {}

        return $current;
    }

    private static function valid(mixed $saved): bool
    {
        if (! is_array($saved) || array_keys($saved) !== ['to', 'cc', 'bcc_configured'] || ! is_bool($saved['bcc_configured'])) return false;
        foreach (['to', 'cc'] as $role) {
            if (! is_array($saved[$role]) || ! array_is_list($saved[$role])) return false;
            foreach ($saved[$role] as $email) if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        }

        return $saved['to'] !== [];
    }
}
