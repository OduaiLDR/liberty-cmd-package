<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use InvalidArgumentException;

/** Pure, deterministic approved-file handoff rendering. Never reads configuration or creates links. */
final class SmsRunEmailMessage
{
    public static function build(array $configuration, array $manifest, array $options, int $selected, array $links, array $allowedHosts = []): array
    {
        $target = $options['target'] ?? null;
        if (! is_int($target) || SmsRunOptions::fromStored(json_encode($options, JSON_THROW_ON_ERROR), $target) !== $options
            || ($options['options_version'] ?? null) !== SmsRunOptions::PLANNED_DATE_VERSION
            || ($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION
            || ($options['csv_format'] ?? null) !== SmsRunOptions::CSV_FORMAT
            || $selected < 1 || $selected > $target) {
            throw new InvalidArgumentException('SMS email requires canonical approved four-column run options and a nonempty selection.');
        }
        $parts = $manifest['parts'] ?? null;
        $count = (int) ceil($selected / $options['rows_per_file']);
        if (($manifest['version'] ?? null) !== 1 || ($manifest['rows'] ?? null) !== $selected
            || ($manifest['rows_per_file'] ?? null) !== $options['rows_per_file']
            || ($manifest['csv_format'] ?? null) !== SmsRunOptions::CSV_FORMAT
            || ($manifest['options_sha256'] ?? null) !== hash('sha256', json_encode($options, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            || ! is_array($parts) || ! array_is_list($parts) || count($parts) !== $count
            || ! array_is_list($links) || count($links) !== $count || $count > SmsRunArtifactBundle::MAX_PARTS) {
            throw new InvalidArgumentException('SMS email file details differ from the frozen approved manifest.');
        }
        $files = self::files($links, $options['send_date'], $selected, $allowedHosts);
        foreach ($parts as $index => $part) {
            $file = $files[$index];
            if (! is_array($part) || ($part['part'] ?? null) !== $index + 1
                || ($part['name'] ?? null) !== $file['name'] || ($part['rows'] ?? null) !== $file['rows']
                || ($part['bytes'] ?? null) !== $file['bytes'] || ($part['sha256'] ?? null) !== $file['sha256']
                || $file['rows'] !== min($options['rows_per_file'], $selected - $index * $options['rows_per_file'])) {
                throw new InvalidArgumentException('SMS email links must match every approved CSV part in order.');
            }
        }
        $download = $manifest['download'] ?? null;
        $stem = substr($files[0]['name'], 0, -strlen(' - Part 1 of '.$count.'.csv'));
        $format = $count === 1 ? 'csv' : 'zip';
        if (! is_array($download) || ($download['format'] ?? null) !== $format
            || ($download['name'] ?? null) !== ($count === 1 ? $files[0]['name'] : $stem.'.zip')
            || ! self::positive($download['bytes'] ?? null) || ! self::sha($download['sha256'] ?? null)
            || ($count === 1 && ($download['bytes'] !== $files[0]['bytes'] || $download['sha256'] !== $files[0]['sha256']))) {
            throw new InvalidArgumentException('SMS email requires the approved final download receipt.');
        }
        return self::render($configuration, $options['send_date'], $selected, $files);
    }

    /** Validate the exact frozen content before a provider attempt, without consulting live recipients. */
    public static function validate(array $message, array $allowedHosts): void
    {
        $configuration = [];
        foreach (['from', 'to', 'cc', 'bcc', 'report_name', 'companies'] as $key) $configuration[$key] = $message[$key] ?? null;
        $date = $message['send_date'] ?? null;
        $total = $message['total'] ?? null;
        if (! is_string($date) || ! is_int($total) || ! is_array($message['files'] ?? null)) {
            throw new InvalidArgumentException('Stored SMS email content is incomplete.');
        }
        $files = self::files($message['files'], $date, $total, $allowedHosts);
        if ($message !== self::render($configuration, $date, $total, $files)) {
            throw new InvalidArgumentException('Stored SMS email content differs from its canonical frozen message.');
        }
    }

    public static function assertConfiguration(array $configuration): void
    {
        if (array_keys($configuration) !== ['from', 'to', 'cc', 'bcc', 'report_name', 'companies']
            || $configuration['from'] !== 'admin@lendingtower.com'
            || ! self::label($configuration['report_name'])
            || ! is_array($configuration['companies']) || ! array_is_list($configuration['companies']) || $configuration['companies'] === []) {
            throw new InvalidArgumentException('SMS email requires the confirmed sender and an explicit table report/company configuration.');
        }
        $companies = [];
        foreach ($configuration['companies'] as $company) {
            if (! self::label($company) || isset($companies[strtolower($company)])) throw new InvalidArgumentException('SMS email companies are invalid or duplicated.');
            $companies[strtolower($company)] = true;
        }
        $seen = [];
        foreach (['to', 'cc', 'bcc'] as $role) {
            $group = $configuration[$role];
            if (! is_array($group) || ! array_is_list($group) || ($role === 'to' && $group === [])) {
                throw new InvalidArgumentException('Configure valid SMS email To/CC/BCC groups in TblReports.');
            }
            foreach ($group as $email) {
                if (! is_string($email) || $email !== trim($email) || preg_match('/[\x00-\x20\x7f]/', $email)
                    || ! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[strtolower($email)])) {
                    throw new InvalidArgumentException('SMS email recipients are invalid or duplicated; correct TblReports before sending.');
                }
                $seen[strtolower($email)] = true;
            }
        }
    }

    private static function render(array $configuration, string $date, int $selected, array $files): array
    {
        self::assertConfiguration($configuration);
        $parts = count($files);
        $intro = 'Here are the SMS files to process starting on '.$date.'. There are '.$selected.' records in '.$parts.' file(s).';
        $lines = [$intro, '', 'Download Links'];
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<p>'.$e($intro).'</p><p>Download Links<br>';
        foreach ($files as $index => $file) {
            $label = 'File '.($index + 1).' - '.$file['name'];
            $lines[] = $label.': '.$file['url'];
            $html .= ($index > 0 ? '<br>' : '').'<a href="'.$e($file['url']).'">'.$e($label).'</a>';
        }
        $lines[] = ''; $lines[] = 'Let us know if there are any issues.';
        $html .= '</p><p>Let us know if there are any issues.</p>';
        return $configuration + ['subject' => 'SMS files to process starting on '.$date, 'text' => implode("\n", $lines),
            'html' => $html, 'send_date' => $date, 'total' => $selected, 'parts' => $parts, 'files' => $files];
    }

    private static function files(array $links, string $date, int $selected, array $allowedHosts): array
    {
        SmsDeliveryPolicy::plannedDate($date);
        if ($selected < 1 || $selected > SmsRunOptions::MAX_TARGET || ! array_is_list($links) || $links === []
            || count($links) > SmsRunArtifactBundle::MAX_PARTS || ! array_is_list($allowedHosts) || $allowedHosts === []) {
            throw new InvalidArgumentException('SMS email requires verified files and an explicit external link host allowlist.');
        }
        $hosts = [];
        foreach ($allowedHosts as $host) {
            if (! self::host($host)) throw new InvalidArgumentException('SMS email link hosts must be exact public DNS names.');
            $hosts[strtolower($host)] = true;
        }
        $files = []; $range = null; $rows = 0; $urls = [];
        foreach ($links as $index => $link) {
            if (! is_array($link) || count($link) !== 5 || array_diff(array_keys($link), ['name', 'url', 'sha256', 'bytes', 'rows'])
                || ! is_string($link['name'] ?? null) || ! self::sha($link['sha256'] ?? null)
                || ! self::positive($link['bytes'] ?? null) || ! self::positive($link['rows'] ?? null)) {
                throw new InvalidArgumentException('SMS email links require exact name/hash/byte/row receipts.');
            }
            $suffix = ' - Part '.($index + 1).' of '.count($links).'.csv';
            $stem = str_ends_with($link['name'], $suffix) ? substr($link['name'], 0, -strlen($suffix)) : '';
            $parsed = SmsRunArtifactFilename::parseStem($stem);
            if ($parsed === null || $parsed['send_date'] !== $date || ! SmsRunArtifactFilename::isDropRange($parsed['drop_name_range'])
                || ($range !== null && $parsed['drop_name_range'] !== $range)) {
                throw new InvalidArgumentException('SMS email filenames require the same approved full batch drop range on every part.');
            }
            $range = $parsed['drop_name_range'];
            self::url($link['url'] ?? null, $hosts);
            if (isset($urls[$link['url']])) throw new InvalidArgumentException('SMS email parts require distinct download links.');
            $urls[$link['url']] = true;
            $rows += $link['rows'];
            if ($rows > $selected) throw new InvalidArgumentException('SMS email row counts exceed the approved selection.');
            $files[] = ['name' => $link['name'], 'url' => $link['url'], 'sha256' => $link['sha256'], 'bytes' => $link['bytes'], 'rows' => $link['rows']];
        }
        if ($rows !== $selected) throw new InvalidArgumentException('SMS email row counts differ from the approved selection.');
        return $files;
    }

    private static function url(mixed $url, array $hosts): void
    {
        if (! is_string($url) || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url)) {
            throw new InvalidArgumentException('SMS email download URLs must be safe HTTPS links.');
        }
        $parsed = parse_url($url);
        if (! is_array($parsed) || ($parsed['scheme'] ?? null) !== 'https' || ! self::host($parsed['host'] ?? null)
            || ! isset($hosts[strtolower($parsed['host'])]) || isset($parsed['user']) || isset($parsed['pass'])
            || isset($parsed['fragment']) || (isset($parsed['port']) && $parsed['port'] !== 443)) {
            throw new InvalidArgumentException('SMS email download URLs must use approved HTTPS hosts without credentials or fragments.');
        }
    }

    private static function host(mixed $host): bool
    {
        return is_string($host) && strlen($host) <= 253 && ! filter_var($host, FILTER_VALIDATE_IP)
            && strtolower($host) !== 'localhost' && ! str_ends_with(strtolower($host), '.localhost')
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])$/iD', $host) === 1;
    }

    private static function label(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 128 && trim($value) === $value && ! preg_match('/[\x00-\x1f\x7f]/', $value);
    }

    private static function positive(mixed $value): bool { return is_int($value) && $value >= 1; }
    private static function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
}
