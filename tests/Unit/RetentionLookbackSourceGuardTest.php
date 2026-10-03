<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission\GenerateRetentionBonusCommission;
use Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission\BonusFormatter;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Tests\TestCase;
use ReflectionMethod;
use UnexpectedValueException;

class RetentionLookbackSourceGuardTest extends TestCase
{
    public function test_unique_contacts_leave_source_boundary_unchanged(): void
    {
        $rows = [
            ['ID' => '101', 'RETENTION_AGENT' => 'Alice Smith'],
            ['ID' => '102', 'RETENTION_AGENT' => 'Alice Smith'],
        ];
        $source = new LookbackGuardConnector($rows);
        $this->assertSame($rows, $this->fetch($source));
        $this->assertSame(1, $source->reads);
        $this->assertSame(0, $source->writes);
    }

    public function test_no_azure_write_mode_skips_the_commission_writer_entirely(): void
    {
        $command = new GenerateRetentionBonusCommission;
        $source = new LookbackGuardConnector([]);
        $persist = new ReflectionMethod($command, 'persistBonusResults');

        $this->assertNull($persist->invoke($command, $source, 'ldr', '2098-01-01', [
            ['agent' => 'Alice Smith', 'amount' => 50.0],
        ], true));
        $this->assertSame(0, $source->reads);
        $this->assertSame(0, $source->writes);
    }

    public function test_no_azure_write_email_requires_a_redirected_recipient(): void
    {
        $command = new GenerateRetentionBonusCommission;
        $guard = new ReflectionMethod($command, 'requiresTestRecipientForNoAzureWrite');

        $this->assertTrue($guard->invoke($command, true, false, ''));
        $this->assertTrue($guard->invoke($command, true, false, 'someone@example.com'));
        $this->assertTrue($guard->invoke($command, true, false, 'oduai@libertydebtrelief.com, someone@example.com'));
        $this->assertFalse($guard->invoke($command, true, false, 'oduai@libertydebtrelief.com'));
        $this->assertFalse($guard->invoke($command, true, false, ' ODUAI@LIBERTYDEBTRELIEF.COM '));
        $this->assertFalse($guard->invoke($command, true, true, ''));
        $this->assertFalse($guard->invoke($command, false, false, ''));
    }

    public function test_no_write_test_path_uses_only_sql_reads_and_direct_graph_email(): void
    {
        $commandSource = file_get_contents(__DIR__ . '/../../src/Console/Commands/GenerateRetentionBonusCommission/GenerateRetentionBonusCommission.php');
        $rosterSource = file_get_contents(__DIR__ . '/../../src/Services/CommissionRosterProvider.php');
        $emailSource = file_get_contents(__DIR__ . '/../../src/Services/EmailSenderService.php');

        $this->assertSame(3, substr_count($commandSource, '$sql->querySqlServer('));
        $this->assertSame(3, preg_match_all('/\$sql->querySqlServer\(\s*"SELECT\b/u', $commandSource));
        $this->assertStringContainsString("'SELECT Agent FROM dbo.'", $rosterSource);

        $sendHtmlStart = strpos($emailSource, 'public function sendMailHtml(');
        $sendHtmlEnd = strpos($emailSource, 'public function sendMailUsingTblReports(', $sendHtmlStart);
        $sendHtmlSource = substr($emailSource, $sendHtmlStart, $sendHtmlEnd - $sendHtmlStart);
        $this->assertStringNotContainsString('querySqlServer(', $sendHtmlSource);
        $this->assertStringNotContainsString('logToTblLog(', $sendHtmlSource);
        $this->assertStringContainsString('$email->sendMailHtml($baseSubject, $baseBody, [$testTo]', $commandSource);
        $this->assertStringContainsString('$email->sendMailUsingTblReportsHtml(', $commandSource);
    }

    public function test_differing_duplicates_cannot_leave_source_boundary(): void
    {
        foreach (['Bob Jones', 'ALICE SMITH'] as $secondAgent) {
            $source = new LookbackGuardConnector([
                ['ID' => '101', 'RETENTION_AGENT' => 'Alice Smith'],
                ['id' => '101', 'RETENTION_AGENT' => $secondAgent],
            ]);
            $continued = false;
            try {
                $this->fetch($source);
                $continued = true;
                $this->fail('Duplicate contact rows must block calculation/persistence.');
            } catch (UnexpectedValueException $error) {
                $this->assertStringContainsString('duplicate CONTACT ID rows (101)', $error->getMessage());
                $this->assertStringContainsString('Resolve ambiguous CONTACTS_USERFIELDS', $error->getMessage());
            }
            $this->assertFalse($continued);
            $this->assertSame(1, $source->reads);
            $this->assertSame(0, $source->writes);
        }
    }

    public function test_identical_contact_copies_produce_one_award(): void
    {
        $row = ['ID' => '101', 'RETENTION_AGENT' => 'Alice Smith', 'RETENTION_COMMISSION' => 50];
        $source = new LookbackGuardConnector([$row, $row, $row]);
        $unique = $this->fetch($source);
        $this->assertSame([$row], $unique);
        $this->assertSame(50.0, BonusFormatter::commissionTotals($unique)['alice smith']['commission']);
        $this->assertStringContainsString('EXISTS (', $source->sql);
        $this->assertStringContainsString('cs.STATUS_ID = 377650', $source->sql);
        $this->assertStringContainsString("AS DATE) >= '2098-01-01'", $source->sql);
        $this->assertStringContainsString("AS DATE) < '2098-02-01'", $source->sql);
        $this->assertStringNotContainsString('AND cu2.F_DATE', $source->sql);
        $this->assertSame(3, substr_count($source->sql, '_FIVETRAN_DELETED = FALSE'),
            'Only current agent, retention-date, and result custom-field rows may join a contact.');
        $this->assertStringContainsString('cu1._FIVETRAN_DELETED = FALSE', $source->sql);
        $this->assertStringContainsString('cu3._FIVETRAN_DELETED = FALSE', $source->sql);
        $this->assertMatchesRegularExpression(
            '/SELECT CONTACT_ID, F_DATE\s+FROM CONTACTS_USERFIELDS\s+WHERE CUSTOM_ID = 742101\s+AND _FIVETRAN_DELETED = FALSE/s',
            $source->sql
        );
    }

    public function test_four_month_window_and_confirmed_eligibility_boundaries(): void
    {
        $command = new GenerateRetentionBonusCommission;
        $window = (new ReflectionMethod($command, 'lookbackWindow'))->invoke($command, '2098-09-01');
        $this->assertSame(['2098-06-01', '2098-09-30'], $window);
        $eligible = new ReflectionMethod($command, 'isEligibleLookbackRow');
        $base = ['RECONSIDERATION_DATE' => '2098-06-15', 'RETAINED_DATE' => '2098-06-16',
            'CUTOFF' => '2098-09-20', 'DROPPED_DATE' => '2098-09-24', 'PAYMENTS' => 3,
            'RETENTION_DATE' => '2098-05-01'];
        $check = static fn (array $changes): bool => $eligible->invoke($command, array_replace($base, $changes),
            $window[0], '2098-09-01', $window[1]);
        $this->assertTrue($check([]), 'Three payments and cancellation four days after cutoff remain eligible.');
        $this->assertTrue($check(['DROPPED_DATE' => null]));
        $this->assertFalse($check(['DROPPED_DATE' => '2098-09-19']));
        $this->assertFalse($check(['DROPPED_DATE' => '2098-09-20']), 'Existing on-cutoff exclusion is preserved.');
        $this->assertFalse($check(['PAYMENTS' => 2]));
        $this->assertFalse($check(['RECONSIDERATION_DATE' => null]), 'Custom retention date cannot substitute for an actual status.');
        $this->assertFalse($check(['RECONSIDERATION_DATE' => '2098-05-31']));
        $this->assertFalse($check(['RECONSIDERATION_DATE' => '2098-10-01']));
        $this->assertFalse($check(['RETAINED_DATE' => null]));
        $this->assertFalse($check(['RETAINED_DATE' => '2098-06-14']));
        $this->assertFalse($check(['RETAINED_DATE' => '2098-09-21']));
        $this->assertFalse($check(['CUTOFF' => '2098-10-01']));
    }

    public function test_reconsideration_lookup_is_scoped_to_confirmed_window(): void
    {
        $command = new GenerateRetentionBonusCommission;
        $source = new LookbackGuardConnector([]);
        $result = (new ReflectionMethod($command, 'fetchReconsiderationDates'))->invoke($command,
            $source, 377650, '101', '2098-06-01', '2098-09-30');
        $this->assertSame([], $result);
        $this->assertStringContainsString("AS DATE) >= '2098-06-01'", $source->sql);
        $this->assertStringContainsString("AS DATE) < '2098-10-01'", $source->sql);
    }

    public function test_three_payments_must_clear_by_the_individual_cutoff(): void
    {
        $command = new GenerateRetentionBonusCommission;
        $count = new ReflectionMethod($command, 'paymentCountByCutoff');
        $this->assertSame(3, $count->invoke($command, ['2098-06-20', '2098-07-20', '2098-09-20'], '2098-09-20'));
        $this->assertSame(2, $count->invoke($command, ['2098-06-20', '2098-07-20', '2098-09-21'], '2098-09-20'));
        $this->assertSame(2, $count->invoke($command, ['2098-06-20', '2098-07-20', '2098-10-01'], '2098-09-20'));
        $this->assertSame(0, $count->invoke($command, [], '2098-09-20'));
        $this->assertSame(0, $count->invoke($command, ['2098-06-20'], null));
        $this->assertSame(3, $count->invoke($command, ['2098-06-20', '2098-06-20', '2098-07-20'], '2098-09-20'),
            'Separate transactions on the same date must not be collapsed.');
        $eligible = new ReflectionMethod($command, 'isEligibleLookbackRow');
        $row = ['RECONSIDERATION_DATE' => '2098-06-15', 'RETAINED_DATE' => '2098-06-16',
            'CUTOFF' => '2098-09-20', 'DROPPED_DATE' => '2098-09-24',
            'PAYMENTS' => $count->invoke($command, ['2098-06-20', '2098-07-20', '2098-09-21'], '2098-09-20')];
        $this->assertFalse($eligible->invoke($command, $row, '2098-06-01', '2098-09-01', '2098-09-30'));
    }

    public function test_payment_history_uses_cleared_active_deposits_and_pacific_dates(): void
    {
        $source = new LookbackGuardConnector([
            ['CONTACT_ID' => '101', 'CLEARED_DATE' => '2098-06-20'],
            ['CONTACT_ID' => '101', 'CLEARED_DATE' => '2098-06-20'],
            ['contact_id' => '102', 'cleared_date' => '2098-08-15'],
        ]);
        $command = new GenerateRetentionBonusCommission;
        $map = (new ReflectionMethod($command, 'fetchClearedPaymentDates'))->invoke($command, $source, '101,102');
        $this->assertSame(['101' => ['2098-06-20', '2098-06-20'], '102' => ['2098-08-15']], $map);
        foreach (["TRANS_TYPE = 'D'", 'CLEARED_DATE IS NOT NULL', 'RETURNED_DATE IS NULL',
            "RETURN_CODE = ''", '_FIVETRAN_DELETED = FALSE', "CONVERT_TIMEZONE('America/Los_Angeles', CLEARED_DATE)",
            'CONTACT_ID IN (101,102)'] as $fragment) {
            $this->assertStringContainsString($fragment, $source->sql);
        }
        $this->assertSame(0, $source->writes);
    }

    public function test_unavailable_payment_history_blocks_instead_of_using_lifetime_counts(): void
    {
        $source = new LookbackGuardConnector([], false);
        $command = new GenerateRetentionBonusCommission;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lookback payment history is unavailable');
        (new ReflectionMethod($command, 'fetchClearedPaymentDates'))->invoke($command, $source, '101');
    }

    public function test_invalid_payment_history_blocks_calculation(): void
    {
        $source = new LookbackGuardConnector([['CONTACT_ID' => '101', 'CLEARED_DATE' => 'not-a-date']]);
        $command = new GenerateRetentionBonusCommission;
        $this->expectException(UnexpectedValueException::class);
        (new ReflectionMethod($command, 'fetchClearedPaymentDates'))->invoke($command, $source, '101');
    }

    public function test_payment_history_accepts_explicit_success_and_rejects_malformed_data(): void
    {
        $command = new GenerateRetentionBonusCommission;
        $fetch = new ReflectionMethod($command, 'fetchClearedPaymentDates');
        $this->assertSame(['101' => ['2098-01-01']], $fetch->invoke($command,
            new LookbackGuardConnector([['CONTACT_ID' => '101', 'CLEARED_DATE' => '2098-01-01']], true), '101'));
        $malformed = new class extends DBConnector {
            public function __construct() {}
            public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array { return ['data' => 'invalid']; }
        };
        $this->expectException(\RuntimeException::class);
        $fetch->invoke($command, $malformed, '101');
    }

    public function test_migrated_payments_preserve_max_source_count_after_cutoff_filtering(): void
    {
        $command = new GenerateRetentionBonusCommission;
        $apply = new ReflectionMethod($command, 'applyPaymentCountsByCutoff');
        $deposits = static fn (array $dates): LookbackGuardConnector => new LookbackGuardConnector(array_map(
            static fn (string $date): array => ['CONTACT_ID' => '101', 'CLEARED_DATE' => $date], $dates));
        $row = ['ID' => '101', 'CUTOFF' => '2098-09-20', 'PAYMENTS' => 99];
        $one = ['2098-06-20'];
        $three = ['2098-06-20', '2098-07-20', '2098-09-20'];
        $this->assertSame(3, $apply->invoke($command, [$row], $deposits($one), $deposits($three), '101')[0]['PAYMENTS']);
        $this->assertSame(3, $apply->invoke($command, [$row], $deposits($three), $deposits($one), '101')[0]['PAYMENTS']);
        $this->assertSame(3, $apply->invoke($command, [$row], $deposits($three), $deposits($three), '101')[0]['PAYMENTS'],
            'Mirrored payments must not be summed to six.');
        $late = ['2098-06-20', '2098-07-20', '2098-09-21'];
        $this->assertSame(2, $apply->invoke($command, [$row], $deposits($one), $deposits($late), '101')[0]['PAYMENTS'],
            'Timing applies independently to both histories, without lifetime fallback.');
        $this->assertSame(0, $apply->invoke($command, [$row], $deposits([]), $deposits([]), '101')[0]['PAYMENTS']);
    }

    public function test_other_company_history_failure_blocks_even_if_current_has_three(): void
    {
        $command = new GenerateRetentionBonusCommission;
        $current = new LookbackGuardConnector(array_fill(0, 3, ['CONTACT_ID' => '101', 'CLEARED_DATE' => '2098-06-20']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lookback payment history is unavailable');
        (new ReflectionMethod($command, 'applyPaymentCountsByCutoff'))->invoke($command,
            [['ID' => '101', 'CUTOFF' => '2098-09-20']], $current, new LookbackGuardConnector([], false), '101');
    }

    public function test_failed_base_query_cannot_be_published_as_an_empty_report(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lookback base contacts query failed');
        $this->fetch(new LookbackGuardConnector([], false));
    }

    public function test_failed_status_queries_cannot_be_treated_as_no_qualifying_clients(): void
    {
        $command = new GenerateRetentionBonusCommission;
        foreach ([
            ['fetchReconsiderationDates', [377650, '101', '2098-01-01', '2098-04-30']],
            ['fetchRetainedDates', ['101']],
        ] as [$method, $args]) {
            try {
                (new ReflectionMethod($command, $method))->invoke($command, new LookbackGuardConnector([], false), ...$args);
                $this->fail($method . ' must stop on a failed source query.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('query failed', $error->getMessage());
            }
        }
    }

    public function test_failed_azure_enrollment_or_violation_lookup_stops_before_persistence(): void
    {
        $command = new GenerateRetentionBonusCommission;
        $source = new class extends DBConnector {
            public function __construct() {}
            public function querySqlServer(string $sql, array $params = []): array
            {
                return ['success' => false, 'error' => 'temporary database outage'];
            }
        };
        foreach ([
            ['fetchEnrollmentMap', [['LLG-101']]],
            ['fetchViolationMap', [['101']]],
        ] as [$method, $args]) {
            try {
                (new ReflectionMethod($command, $method))->invoke($command, $source, ...$args);
                $this->fail($method . ' accepted a failed Azure query.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('lookup failed', $error->getMessage());
            }
        }
    }

    private function fetch(LookbackGuardConnector $source): array
    {
        return (new ReflectionMethod(GenerateRetentionBonusCommission::class, 'fetchBase'))->invoke(
            new GenerateRetentionBonusCommission,
            $source,
            ['custom_agent' => 742096, 'custom_date' => 742101, 'custom_results' => 742105, 'recon_status_id' => 377650],
            '2098-01-01', '2098-01-31'
        );
    }
}

final class LookbackGuardConnector extends DBConnector
{
    public int $reads = 0;
    public int $writes = 0;
    public string $sql = '';

    public function __construct(private array $rows, private ?bool $success = null) {}

    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        $this->reads++;
        $this->sql = $sql;
        $result = ['data' => $this->rows, 'rowCount' => count($this->rows), 'columns' => []];
        if ($this->success !== null) $result['success'] = $this->success;
        return $result;
    }

    public function querySqlServer(string $sql, array $params = []): array
    {
        $this->writes++;
        throw new \LogicException('No SQL Server calls are allowed in the source guard test.');
    }
}
