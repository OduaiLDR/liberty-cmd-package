<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Pure preparation only: no delivery, upload, link creation, or configuration reads. */
final class SmsRunHandoffPreview
{
    private const FROM = 'admin@lendingtower.com';

    /**
     * Accepts the public run view after its artifact manifest was validated.
     * Old runs deliberately receive no inferred handoff or delivery state.
     */
    public static function forRun(array $view): ?array
    {
        $options = $view['options'] ?? null;
        if (! is_array($options) || ($options['options_version'] ?? null) !== SmsRunOptions::PLANNED_DATE_VERSION) {
            return null;
        }

        $selected = self::count($view['selected'] ?? null) ? $view['selected'] : null;
        $preview = [
            'email_preview' => [
                'from' => self::FROM, 'to' => [], 'body' => null, 'files' => [],
                'available' => false, 'reason' => 'A valid planned date and complete verified SMS file details are required for this preview.',
            ],
            'email' => [
                'status' => 'not_configured',
                'note' => 'Preview only; opening this view does not send email. Recipients come from TblReports when an approved handoff is prepared; external download links and delivery setup are required. This preview alone does not establish past email activity.',
            ],
            'debtplete_import' => [
                'status' => 'not_configured', 'processed' => null, 'total' => $selected,
                'note' => 'No records have been uploaded. The authenticated DebtPlete import and its progress tracking are not connected.',
            ],
        ];

        $date = $options['send_date'] ?? null;
        $file = $view['file'] ?? null;
        if ($selected === null || ! self::date($date)
            || ! in_array($view['status'] ?? null, ['file_ready', 'approving', 'approved'], true)
            || ($options['csv_format'] ?? null) !== SmsRunOptions::CSV_FORMAT
            || ! is_array($file) || ! is_array($file['files'] ?? null) || ! array_is_list($file['files'])) {
            return $preview;
        }

        $parts = count($file['files']);
        if ($parts < 1 || $parts > SmsRunArtifactBundle::MAX_PARTS
            || ($file['parts'] ?? null) !== $parts
            || ($file['format'] ?? null) !== ($parts === 1 ? 'csv' : 'zip')) {
            return $preview;
        }

        $stem = 'SMS Export - '.$date;
        if (array_key_exists('filename_version', $options)) {
            $approvalNames = $options['filename_version'] === SmsRunOptions::APPROVAL_FILENAME_VERSION;
            if ($approvalNames && $view['status'] !== 'approved') {
                if (($file['drop_name_range'] ?? null) !== null) return $preview;
                $stem .= ' - ID Pending';
                $preview['email']['note'] .= ' SMS drop IDs are assigned only on approval; these are pending filenames.';
            } elseif ((! $approvalNames && $options['filename_version'] !== SmsRunOptions::DROP_RANGE_FILENAME_VERSION)
                || ! is_string($file['drop_name_range'] ?? null) || ! SmsRunArtifactFilename::isDropRange($file['drop_name_range'])) {
                return $preview;
            } else {
                $stem .= ' - '.$file['drop_name_range'];
            }
        }

        $files = [];
        $rows = 0;
        foreach ($file['files'] as $index => $part) {
            $name = $stem.' - Part '.($index + 1).' of '.$parts.'.csv';
            if (! is_array($part) || ($part['name'] ?? null) !== $name || ! self::count($part['rows'] ?? null)) {
                return $preview;
            }
            $rows += $part['rows'];
            if ($rows > $selected) {
                return $preview;
            }
            $files[] = ['name' => $name, 'rows' => $part['rows'], 'link' => null];
        }
        if ($rows !== $selected) {
            return $preview;
        }

        $lines = [
            'Here are the SMS files to process starting on '.$date.'. There are '.$selected.' records in '.$parts.' file(s).',
            '', 'Download Links',
        ];
        foreach ($files as $index => $part) {
            $lines[] = 'File '.($index + 1).' - '.$part['name'].': [Download link pending]';
        }
        $lines[] = '';
        $lines[] = 'Let us know if there are any issues.';
        $preview['email_preview'] = [
            'from' => self::FROM, 'to' => [], 'body' => implode("\n", $lines),
            'files' => $files, 'available' => true, 'reason' => null,
        ];

        return $preview;
    }

    private static function count(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= SmsRunOptions::MAX_TARGET;
    }

    private static function date(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $match) === 1
            && checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }
}
