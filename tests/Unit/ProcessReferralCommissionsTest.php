<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\ReferralCommissions\ProcessReferralCommissions;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The rules of ProcessReferralCommissions / ProcessReferralCommissionsCCS (CMD LDR.xlsm), checked
 * against rows the VBA actually wrote to production (TblFundings PK 11596 / TblPayrollAdjustments
 * PK 25562, imported 25 Aug 2026), and the per-row flow through fakes for the three data sources.
 */
class ProcessReferralCommissionsTest extends TestCase
{
    // ── Rules ──────────────────────────────────────────────────────────────

    public function test_payroll_date_is_the_tenth_of_the_next_month(): void
    {
        // Jerry Anderson: funded 2026-08-24, imported 2026-08-25 -> Payroll_Date 2026-09-10 (PK 25562).
        $this->assertSame('2026-09-10', ProcessReferralCommissions::payrollDate('2026-08-24', '2026-08-25'));
    }

    public function test_a_tenth_already_passed_moves_to_the_month_after(): void
    {
        // Funded 2 Jul, file processed 25 Aug: 10 Aug is gone, so 10 Sep.
        $this->assertSame('2026-09-10', ProcessReferralCommissions::payrollDate('2026-07-02', '2026-08-25'));
        // VBA compares with `<`: a payroll date equal to today stands.
        $this->assertSame('2026-09-10', ProcessReferralCommissions::payrollDate('2026-08-03', '2026-09-10'));
        $this->assertSame('2026-10-10', ProcessReferralCommissions::payrollDate('2026-08-03', '2026-09-11'));
        // Year end.
        $this->assertSame('2027-01-10', ProcessReferralCommissions::payrollDate('2026-12-15', '2026-12-16'));
    }

    public function test_funding_note_matches_what_the_vba_stored(): void
    {
        $note = ProcessReferralCommissions::fundingNote([
            'loan_amount' => 4200.0, 'rate_text' => '35.8', 'term_text' => '60', 'lender' => 'Upstart',
        ]);

        $this->assertSame(
            "Personal Loan Funded By: Upstart\r\nFinal Loan Amount: \$4,200\r\nAPR: 35.8%\r\nInterest Rate: 35.8%\r\nLoan Term: 60 Months",
            $note
        );
        $this->assertSame('$11,000', ProcessReferralCommissions::dollars(11000.0));
        $this->assertSame('$0', ProcessReferralCommissions::dollars(null));
        $this->assertSame('8037433974', ProcessReferralCommissions::numericOnly('(803) 743-3974'));
    }

    // ── Flow ───────────────────────────────────────────────────────────────

    public function test_lt_pass_for_a_usa_agent_writes_payroll_fundings_and_crm_in_the_vba_order(): void
    {
        $command = $this->command(ldr: [
            'TblContacts' => ['LLG-1246800345' => ['Client' => 'Rieco Owens', 'Agent' => 'Jane Doe', 'Status' => 'x', 'LLG_ID' => 'LLG-1246800345', 'Email' => 'r@x.com', 'City' => 'COLUMBIA', 'State' => 'SC', 'Phone' => '(803) 743-3974']],
            'TblEmployees' => ['Jane Doe' => ['PK' => 682, 'Location' => 'USA']],
            'TblEnrollment' => [],
            'processed' => [],
        ]);

        $outcome = $this->processRow($command, 'lt', $this->row());

        $this->assertSame('processed', $outcome);
        $this->assertSame([
            "INSERT TblPayrollAdjustments [682, 'Commission', '2026-09-10', 50, 'Funded - LLG-1246800345 - Rieco Owens - \$4,700 - Upstart']",
            'CRM lt 1246800345 client_status = Funded',
            'CRM lt 1246800345 notebody = Personal Loan Funded By: Upstart | Final Loan Amount: $4,700 | APR: 35.8% | Interest Rate: 35.8% | Loan Term: 60 Months',
            "INSERT TblFundings ['Rieco Owens', 4700, 35.8, 35.8, 60, '8037433974', 'r@x.com', 'COLUMBIA', 'SC', '682', 'LLG-1246800345', '2026-08-21', 'Monevo', 'Upstart', 65.8, '2026-08-25', <note>]",
            'CRM lt 1246800345 funded_by = Upstart',
            'CRM lt 1246800345 loan_amount = 4700',
            'CRM lt 1246800345 apr = 35.8%',
            'CRM lt 1246800345 interest_rate = 35.8%',
            'CRM lt 1246800345 loan_term = 60',
        ], $command->writes);
    }

    public function test_offshore_agent_gets_a_zero_commission_row_and_no_status_change_when_enrolled(): void
    {
        $command = $this->command(ldr: [
            'TblContacts' => ['LLG-1246800345' => ['Client' => 'Rieco Owens', 'Agent' => 'Omar A', 'Phone' => '', 'Email' => '', 'City' => '', 'State' => '']],
            'TblEmployees' => ['Omar A' => ['PK' => 771, 'Location' => 'Jordan']],
            'TblEnrollment' => ['LLG-1246800345' => 'LDR Enrolled (NSF-1)'],
            'processed' => [],
        ]);

        $this->assertSame('processed', $this->processRow($command, 'lt', $this->row()));
        $this->assertStringStartsWith("INSERT TblPayrollAdjustments [771, 'Commission', '2026-09-10', 0, ", $command->writes[0]);
        $this->assertNotContains('CRM lt 1246800345 client_status = Funded', $command->writes, 'an enrolled client keeps its status');
        $this->assertStringStartsWith('CRM lt 1246800345 notebody = ', $command->writes[1]);
        $this->assertStringContainsString(", NULL, '', '', '', '771', ", $command->writes[2], 'blank phone is NULL; Agent is the PK as text');
    }

    public function test_unknown_agent_is_pk_zero_and_a_processed_row_is_skipped(): void
    {
        $ldr = [
            'TblContacts' => ['LLG-1246800345' => ['Client' => 'Rieco Owens', 'Agent' => 'Nobody Known']],
            'TblEmployees' => [],
            'TblEnrollment' => [],
            'processed' => [],
        ];

        $command = $this->command(ldr: $ldr);
        $this->assertSame('processed', $this->processRow($command, 'lt', $this->row()));
        $this->assertStringStartsWith("INSERT TblPayrollAdjustments [0, 'Commission', '2026-09-10', 0, ", $command->writes[0], 'Val() of a missing PK is 0, and 0 is not a USA employee');

        // The VBA's duplicate test: Agent_ID + Category + the LLG id in Notes.
        $ldr['processed'][] = ['agent' => 0, 'llg' => 'LLG-1246800345'];
        $command = $this->command(ldr: $ldr);
        $this->assertSame('already', $this->processRow($command, 'lt', $this->row()));
        $this->assertSame([], $command->writes);
    }

    public function test_a_row_whose_contact_is_missing_is_dropped(): void
    {
        $command = $this->command(ldr: ['TblContacts' => [], 'TblEmployees' => [], 'TblEnrollment' => [], 'processed' => []]);

        $this->assertSame('not_in_contacts', $this->processRow($command, 'lt', $this->row()));
        $this->assertSame([], $command->writes);
        $this->assertSame(['ldr TblContacts LLG_ID=LLG-1246800345'], $command->lookups, 'nothing else is looked up for a row the VBA deleted first');
    }

    public function test_ccs_pass_reads_the_ccs_database_and_writes_agent_id_zero(): void
    {
        $command = $this->command(
            ldr: [
                'TblContacts' => [],
                'TblEmployees' => [905 => ['PK' => 905, 'Location' => 'USA']],   // the CCS PK, checked against LDR — as the VBA did
                'TblEnrollment' => [],
                'processed' => [['agent' => 905, 'llg' => 'LLG-1246800345']],    // an lt row for the same client must NOT block the ccs pass
            ],
            ccs: [
                'TblContacts' => ['1246800345' => ['Client' => 'Rieco Owens', 'Agent' => 'CCS Agent', 'Phone' => '8037433974', 'Email' => 'r@x.com', 'City' => 'COLUMBIA', 'State' => 'SC']],
                'TblEmployees' => ['CCS Agent' => ['PK' => 905]],
            ]
        );

        $this->assertSame('processed', $this->processRow($command, 'ccs', $this->row()));
        $this->assertContains('ccs TblContacts CID=1246800345', $command->lookups);
        $this->assertContains('ccs TblEmployees Employee_Name=CCS Agent', $command->lookups);
        $this->assertStringStartsWith("INSERT TblPayrollAdjustments [0, 'Commission', '2026-09-10', 50, ", $command->writes[0], 'Agent_ID 0, but the USA test still used the PK');
        $this->assertSame('CRM ccs 1246800345 client_status = Funded Completed', $command->writes[1]);
        $this->assertStringContainsString(", '905', 'LLG-1246800345', ", $command->writes[3], 'TblFundings.Agent is the CCS PK');
    }

    // ── Harness ────────────────────────────────────────────────────────────

    /** Rieco Owens, TblFundings PK 11595: funded 2026-08-21, $4,700 @ 35.8% / 60 months, Upstart, commission 65.80. */
    private function row(): array
    {
        return [
            'row' => 2, 'client_id' => '1246800345', 'funding_date' => '2026-08-21', 'commission' => 65.8,
            'loan_amount' => 4700.0, 'loan_amount_text' => '4700', 'rate' => 35.8, 'rate_text' => '35.8',
            'term' => 60, 'term_text' => '60', 'lender' => 'Upstart',
        ];
    }

    private function processRow(ProcessReferralCommissions $command, string $pass, array $row): string
    {
        $method = new ReflectionMethod($command, 'processRow');

        return $method->invoke($command, $pass, $row, '2026-08-25');
    }

    private function command(array $ldr, array $ccs = []): FakeReferralCommissions
    {
        $command = new FakeReferralCommissions($ldr, $ccs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        return $command;
    }
}

/**
 * Answers the command's six lookups from arrays and records every write instead of performing it.
 */
final class FakeReferralCommissions extends ProcessReferralCommissions
{
    /** @var list<string> */
    public array $writes = [];

    /** @var list<string> */
    public array $lookups = [];

    public function __construct(private readonly array $ldrData, private readonly array $ccsData)
    {
        parent::__construct();
    }

    protected function ldrSelect(string $sql, array $params): array
    {
        if (str_contains($sql, 'FROM TblContacts')) {
            $this->lookups[] = "ldr TblContacts LLG_ID={$params[0]}";
            $row = $this->ldrData['TblContacts'][$params[0]] ?? null;

            return $row === null ? [] : [$row];
        }
        if (str_contains($sql, 'PK FROM TblEmployees')) {
            $this->lookups[] = "ldr TblEmployees Employee_Name={$params[0]}";
            $row = $this->ldrData['TblEmployees'][$params[0]] ?? null;

            return $row === null ? [] : [['PK' => $row['PK']]];
        }
        if (str_contains($sql, 'Location FROM TblEmployees')) {
            $this->lookups[] = "ldr TblEmployees PK={$params[0]}";
            foreach ($this->ldrData['TblEmployees'] as $row) {
                if ((int) $row['PK'] === (int) $params[0]) {
                    return [['Location' => $row['Location']]];
                }
            }

            return [];
        }
        if (str_contains($sql, 'FROM TblEnrollment')) {
            $this->lookups[] = "ldr TblEnrollment LLG_ID={$params[0]}";
            $status = $this->ldrData['TblEnrollment'][$params[0]] ?? null;

            return $status === null ? [] : [['Enrollment_Status' => $status]];
        }
        if (str_contains($sql, 'FROM TblPayrollAdjustments')) {
            $this->lookups[] = "ldr TblPayrollAdjustments Agent_ID={$params[0]} Notes LIKE {$params[1]}";
            $n = 0;
            foreach ($this->ldrData['processed'] as $done) {
                if ($done['agent'] === $params[0] && str_contains($params[1], $done['llg'])) {
                    $n++;
                }
            }

            return [['n' => $n]];
        }

        throw new \LogicException("unexpected LDR query: {$sql}");
    }

    protected function ccsSelect(string $sql, array $params): array
    {
        if (str_contains($sql, 'FROM TblContacts')) {
            $this->lookups[] = "ccs TblContacts CID={$params[0]}";
            $row = $this->ccsData['TblContacts'][$params[0]] ?? null;

            return $row === null ? [] : [$row];
        }
        if (str_contains($sql, 'FROM TblEmployees')) {
            $this->lookups[] = "ccs TblEmployees Employee_Name={$params[0]}";
            $row = $this->ccsData['TblEmployees'][$params[0]] ?? null;

            return $row === null ? [] : [['PK' => $row['PK']]];
        }

        throw new \LogicException("unexpected CCS query: {$sql}");
    }

    protected function ldrExecute(string $sql, array $params, string $describe): void
    {
        $table = preg_match('/INSERT INTO (\w+)/', $sql, $m) ? $m[1] : '?';
        $this->writes[] = "INSERT {$table} [" . implode(', ', array_map(static function (mixed $v): string {
            if ($v === null) {
                return 'NULL';
            }
            if (is_string($v) && str_starts_with($v, 'Personal Loan Funded By')) {
                return '<note>';
            }

            return is_string($v) ? "'{$v}'" : (string) $v;
        }, $params)) . ']';
    }

    protected function crmUpdate(string $tenant, string $contactId, string $field, string $value): void
    {
        $this->writes[] = "CRM {$tenant} {$contactId} {$field} = " . str_replace(["\r\n", "\n"], ' | ', $value);
    }
}
