<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\SettlementApprovalEmail;

use Cmd\Reports\SettlementApprovalEmail\ApprovalRunLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovalRunLogTest extends TestCase
{
    public function test_unknown_exception_and_personal_identifiers_are_not_copied_to_logs(): void
    {
        $records = [];
        $lines = [];
        $log = new ApprovalRunLog(false,
            function ($level, $event, $context) use (&$records) { $records[] = [$level, $event, $context]; },
            function ($line) use (&$lines) { $lines[] = $line; },
            true,
        );
        $log->message('private-graph-id', 'PLAW', '456');
        $log->event('offer_read_started');
        $diagnostic = $log->failure(new RuntimeException('SQL bindings: secret-api-key client@example.com private-body'), 'message_failed');

        self::assertStringContainsString('stage=offer_read_started', $diagnostic);
        self::assertStringContainsString('raw provider/database details omitted', $diagnostic);
        $all = $diagnostic . json_encode($records) . implode("\n", $lines);
        foreach (['secret-api-key', 'client@example.com', 'private-body', 'private-graph-id', 'SQL bindings'] as $private) {
            self::assertStringNotContainsString($private, $all);
        }
        self::assertSame('456', $records[1][2]['settlement_id']);
        self::assertSame(substr(hash('sha256', 'private-graph-id'), 0, 16), $records[1][2]['message_ref']);
        self::assertSame('error', $records[1][0]);
    }

    public function test_failed_log_and_console_sinks_never_escape_to_business_processing(): void
    {
        $log = new ApprovalRunLog(true,
            static fn () => throw new RuntimeException('sink unavailable'),
            static fn () => throw new RuntimeException('console unavailable'),
            true,
        );
        $log->event('note_create_started');
        $diagnostic = $log->failure(new RuntimeException('Offer status is missing or invalid.'), 'message_failed');
        self::assertStringContainsString('Offer status is missing or invalid.', $diagnostic);
        self::assertStringContainsString('stage=note_create_started', $diagnostic);
    }
}
