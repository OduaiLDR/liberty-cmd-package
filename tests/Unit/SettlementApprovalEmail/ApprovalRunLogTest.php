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

    public function test_wrapped_timeout_reports_safe_recovery_without_exposing_provider_data(): void
    {
        $records = [];
        $log = new ApprovalRunLog(true,
            function ($level, $event, $context) use (&$records) { $records[] = $context; },
            static function ($line) {},
        );
        $cause = new \GuzzleHttp\Exception\ConnectException('private response and token',
            new \GuzzleHttp\Psr7\Request('POST', 'https://provider.invalid/private-id'), null, ['errno' => 28]);
        $log->event('note_create_started');
        $diagnostic = $log->failure(new RuntimeException('Forth request failed; mutation outcomes require reconciliation before retry.', 0, $cause), 'message_failed');
        self::assertSame('timeout', $records[1]['error_code']);
        self::assertSame('manual_review_before_retry', $records[1]['recovery']);
        self::assertStringContainsString('error_code=timeout', $diagnostic);
        self::assertStringNotContainsString('private', json_encode($records));
        self::assertStringNotContainsString('provider.invalid', json_encode($records));
    }

    public function test_http_diagnostics_include_bounded_retry_after_and_preserve_the_operation_stage(): void
    {
        $records = [];
        $log = new ApprovalRunLog(false,
            function ($level, $event, $context) use (&$records) { $records[] = $context; },
            static function ($line) {},
        );
        $error = new \GuzzleHttp\Exception\RequestException('secret provider text',
            new \GuzzleHttp\Psr7\Request('GET', 'https://provider.invalid'),
            new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => '120'], 'private body'));
        $log->event('offer_read_started');
        $log->failure($error, 'message_failed');
        $log->failure(new RuntimeException('cleanup failed'), 'ledger_release_failed', 'ledger_release_started');
        $log->failure($error, 'message_failed');

        self::assertSame('rate_limited', $records[1]['error_code']);
        self::assertSame(429, $records[1]['http_status']);
        self::assertSame(120, $records[1]['retry_after_seconds']);
        self::assertSame('retry_next_run', $records[1]['recovery']);
        self::assertSame('ledger_release_started', $records[2]['stage']);
        self::assertSame('offer_read_started', $records[3]['stage']);
        self::assertStringNotContainsString('secret', json_encode($records));
    }

    public function test_database_programming_and_forth_http_errors_have_distinct_diagnostics(): void
    {
        foreach ([
            [new \PDOException('private SQL bindings'), 'database_error', 'retry_next_run'],
            [new \TypeError('private input'), 'programming_error', 'investigate'],
            [new RuntimeException('Forth request rejected (HTTP 403).'), 'access_denied', 'check_configuration'],
            [new \JsonException('private JSON'), 'invalid_json', 'investigate'],
        ] as [$error, $code, $recovery]) {
            $records = [];
            $log = new ApprovalRunLog(false,
                function ($level, $event, $context) use (&$records) { $records[] = $context; },
                static function ($line) {},
            );
            $log->event('offer_read_started');
            $log->failure($error, 'message_failed');
            self::assertSame($code, $records[1]['error_code']);
            self::assertSame($recovery, $records[1]['recovery']);
            self::assertStringNotContainsString('private', json_encode($records));
        }
    }
}
