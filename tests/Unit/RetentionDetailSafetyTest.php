<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\RetentionCommissionReportBuilder;
use Cmd\Reports\Tests\TestCase;
use ReflectionMethod;
use RuntimeException;
use UnexpectedValueException;

class RetentionDetailSafetyTest extends TestCase
{
    public function test_failed_source_query_does_not_look_like_zero_commission(): void
    {
        $builder = new RetentionCommissionReportBuilder;
        $source = new RetentionDetailFakeConnector(false);
        $cases = [
            ['fetchBase', [RetentionCommissionReportBuilder::SOURCE_CONFIG['ldr']]],
            ['fetchReconsiderationDates', [377650, '101']],
            ['fetchRetainedDates', ['101']],
            ['fetchFirstClearedPerContact', ['101']],
        ];
        foreach ($cases as [$method, $args]) {
            try {
                (new ReflectionMethod($builder, $method))->invoke($builder, $source, ...$args);
                $this->fail($method . ' accepted a failed source query.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('query failed', $error->getMessage());
            }
        }
    }

    public function test_base_query_ignores_deleted_userfields(): void
    {
        $builder = new RetentionCommissionReportBuilder;
        $source = new RetentionDetailFakeConnector(true);
        (new ReflectionMethod($builder, 'fetchBase'))->invoke($builder, $source, RetentionCommissionReportBuilder::SOURCE_CONFIG['ldr']);
        $this->assertGreaterThanOrEqual(4, substr_count($source->sql, '_FIVETRAN_DELETED = FALSE'));
    }

    public function test_only_identical_join_copies_are_deduplicated(): void
    {
        $builder = new RetentionCommissionReportBuilder;
        $row = ['ID' => '101', 'RETENTION_AGENT' => 'Alice', 'CANCEL_REQUEST_DATE' => '2098-01-01'];
        $this->assertSame([$row], $builder->dedupeRetentionRowsByContactId([$row, $row]));
        $this->expectException(UnexpectedValueException::class);
        $builder->dedupeRetentionRowsByContactId([$row, [...$row, 'RETENTION_AGENT' => 'Bob']]);
    }
}

final class RetentionDetailFakeConnector extends DBConnector
{
    public string $sql = '';
    public function __construct(private bool $success) {}
    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        $this->sql = $sql;
        return ['success' => $this->success, 'data' => []];
    }
}
