<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

if (!function_exists('tenant')) {
    /**
     * @return array<string, mixed>|mixed|null
     */
    function tenant(?string $key = null): mixed
    {
        $context = $GLOBALS['__cmd_reports_test_tenant'] ?? [];

        if ($key === null) {
            return $context;
        }

        return $context[$key] ?? null;
    }
}

if (!function_exists('now')) {
    function now(DateTimeZone|string|int|null $tz = null): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now($tz);
    }
}
