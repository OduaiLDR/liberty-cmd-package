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
            ['fetchRetainedDates', ['101', '2098-01-31']],
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

    public function test_retained_status_lookup_excludes_dates_after_report_cutoff(): void
    {
        $builder = new RetentionCommissionReportBuilder;
        $source = new RetentionDetailFakeConnector(true, [
            ['CONTACT_ID' => '101', 'RETAINED_DATE' => '2026-09-30'],
            ['CONTACT_ID' => '101', 'RETAINED_DATE' => '2026-10-05'],
        ]);
        $retained = (new ReflectionMethod($builder, 'fetchRetainedDates'))->invoke(
            $builder, $source, '101', '2026-09-30'
        );

        $this->assertSame(['2026-09-30'], $retained['101']);
        $this->assertStringContainsString("LEFT(cs.STAMP,10) <= '2026-09-30'", $source->sql);
    }
}

final class RetentionDetailFakeConnector extends DBConnector
{
    public string $sql = '';
    public function __construct(private bool $success, private array $data = []) {}
    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        $this->sql = $sql;
        return ['success' => $this->success, 'data' => $this->data];
    }
}
