<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Cmd\\Reports\\Tests\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

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

if (!function_exists('storage_path')) {
    /** Report formatters save workbooks under storage_path('app/...'); point that at a temp dir. */
    function storage_path(string $path = ''): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liberty-cmd-package-tests';
        if (!is_dir($base . DIRECTORY_SEPARATOR . 'app')) {
            mkdir($base . DIRECTORY_SEPARATOR . 'app', 0777, true);
        }

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
