<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Check explicit publication wiring without booting the host, routes or commands. */
final class SmsMigrationPublicationTest extends TestCase
{
    public function test_every_shipped_sms_migration_is_explicitly_publishable_without_renaming(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/src/ReportsServiceProvider.php');
        preg_match_all("~__DIR__ \\. '/\.\./database/sms-migrations/([^']+)'\\s*=>\\s*database_path\\('migrations/([^']+)'\\)~", $source, $matches);
        self::assertNotEmpty($matches[1]);
        self::assertSame($matches[1], $matches[2], 'Published migration names must match package evidence.');
        $shipped = array_map('basename', glob($root.'/database/sms-migrations/*.php'));
        $published = $matches[1]; sort($shipped); sort($published);
        self::assertSame($shipped, $published, 'A shipped SMS schema prerequisite must not be absent from the opt-in publish map.');
        self::assertStringContainsString("], 'reports-sms-migrations');", $source);
        self::assertDoesNotMatchRegularExpression('~loadMigrationsFrom\\([^;]*sms-migrations~s', $source, 'Publication must not silently apply SMS schema.');
    }
}
