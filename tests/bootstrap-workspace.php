<?php

declare(strict_types=1);

/*
 * Runs this package's tests WITHOUT a package-level vendor/, borrowing the sibling cmd-runner
 * checkout's dependencies (the workspace layout: GitHub/liberty-cmd-package next to GitHub/cmd-runner).
 *
 *   cd cmd-runner
 *   php vendor/bin/phpunit --bootstrap ../liberty-cmd-package/tests/bootstrap-workspace.php \
 *       --no-configuration ../liberty-cmd-package/tests/Unit
 *
 * cmd-runner's autoloader maps Cmd\Reports\ to the VENDORED (possibly stale) copy of this package,
 * and Composer consults its classmap before PSR-4, so the working tree is prepended with a
 * dedicated autoloader rather than addPsr4().
 */

$package = dirname(__DIR__);
$cmdRunnerAutoload = dirname($package) . '/cmd-runner/vendor/autoload.php';

if (!is_file($cmdRunnerAutoload)) {
    fwrite(STDERR, "bootstrap-workspace.php: {$cmdRunnerAutoload} not found; run `composer install` in cmd-runner first.\n");
    exit(1);
}

require $cmdRunnerAutoload;

spl_autoload_register(static function (string $class) use ($package): void {
    foreach (['Cmd\\Reports\\Tests\\' => '/tests/', 'Cmd\\Reports\\' => '/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $package . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
}, true, true);

date_default_timezone_set('UTC');

if (!function_exists('tenant')) {
    /**
     * @return array<string, mixed>|mixed|null
     */
    function tenant(?string $key = null): mixed
    {
        $context = $GLOBALS['__cmd_reports_test_tenant'] ?? [];

        return $key === null ? $context : ($context[$key] ?? null);
    }
}

if (!function_exists('now')) {
    function now(DateTimeZone|string|int|null $tz = null): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now($tz);
    }
}

// Laravel's own storage_path() is loaded from cmd-runner's vendor and asks the container for
// storagePath(); tests that build workbooks bind a container providing it (see
// EnrollmentSummaryPeelOffsSheetTest::setUp()).
