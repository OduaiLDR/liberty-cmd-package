<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateAdvanceRecoupReport\GenerateAdvanceRecoupReport;
use Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport\GenerateEnrollmentSummaryReport;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Executes the real report builders with fixture connectors and a capturing email transport. */
final class ReportPrivateDeliveryTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/report-private-review-' . bin2hex(random_bytes(6));
        mkdir($this->storage . '/app', 0777, true);
        $app = new class($this->storage) extends Container {
            public function __construct(private string $reportStorage) {}

            public function storagePath(string $path = ''): string
            {
                return $this->reportStorage . ($path === '' ? '' : '/' . ltrim($path, '/\\'));
            }
        };
        $app->instance('log', new class {
            public function __call(string $method, array $arguments): void {}
        });
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/app/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->storage . '/app');
        rmdir($this->storage);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    public function test_enrollment_test_send_has_hidden_first_month_detail_and_no_companion_send(): void
    {
        $db = new ReportFixtureConnector();
        $mail = new ReportCapturingMailer();
        $command = new class($db, $mail) extends GenerateEnrollmentSummaryReport {
            public function __construct(private DBConnector $db, private EmailSenderService $mail)
            {
                parent::__construct();
            }

            protected function initializeSqlServerConnector(): DBConnector { return $this->db; }
            protected function emailSender(): EmailSenderService { return $this->mail; }
        };
        $output = $this->configure($command, ['--date' => '2026-09-01', '--test' => true]);

        self::assertSame(Command::SUCCESS, $command->handle());
        self::assertCount(1, $mail->deliveries);
        $sent = $mail->deliveries[0];
        self::assertSame(['oduai@libertydebtrelief.com'], $sent['to']);
        self::assertSame([], $sent['cc']);
        self::assertSame([], $sent['bcc']);
        self::assertSame('direct', $sent['route']);
        self::assertStringContainsString('write skipped', $output->fetch());

        $path = $this->storage . '/app/captured-enrollment.xlsx';
        file_put_contents($path, base64_decode($sent['attachments'][0]['contentBytes'], true));
        $book = IOFactory::load($path);
        $detail = $book->getSheetByName('Sellable Debt Paying Detail');
        self::assertNotNull($detail);
        self::assertSame(Worksheet::SHEETSTATE_HIDDEN, $detail->getSheetState());
        self::assertSame(Worksheet::SHEETSTATE_VISIBLE, $book->getSheet(0)->getSheetState());
        self::assertSame(
            ['LLG_ID', 'Name', 'Debt_Amount', 'First_Payment_Date', 'First_Payment_Cleared_Date'],
            $detail->rangeToArray('A3:E3')[0]
        );
        self::assertSame('000123', $detail->getCell('A4')->getValue());
        self::assertSame('Fixture Client', $detail->getCell('B4')->getValue());
        self::assertEquals(ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-09-07')), $detail->getCell('D4')->getValue());
        self::assertEquals(ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-09-09')), $detail->getCell('E4')->getValue());
        $detailTotal = $detail->getCell('C4')->getValue() + $detail->getCell('C5')->getValue();
        $summaryRows = (new ReflectionProperty(GenerateEnrollmentSummaryReport::class, 'rows'))->getValue($command);
        $summaryRow = array_values(array_filter($summaryRows, fn ($row) => $row['label'] === 'Sellable Debt Paying in September'))[0];
        self::assertEquals($summaryRow['values']['Total'], $detailTotal);
        $book->disconnectWorksheets();

        $detailCalls = array_values(array_filter($db->sql, fn ($call) => str_contains($call['sql'], 'Client AS Name')));
        self::assertCount(1, $detailCalls);
        self::assertSame(['2026-09-01', '2026-09-30'], $detailCalls[0]['params']);
        $summaryCalls = array_values(array_filter($db->sql, static fn ($call) =>
            str_contains($call['sql'], 'SELECT SUM(Debt_Amount)')
            && str_contains($call['sql'], 'Cancel_Date IS NULL AND NSF_Date IS NULL')
            && str_contains($call['sql'], "Enrollment_Status IN('LDR Enrolled', 'ProLaw Enrolled', 'Approved')")
        ));
        $where = static function (string $sql): string {
            $sql = substr($sql, strpos($sql, 'WHERE'));
            $sql = preg_replace('/ORDER BY.*$/s', '', $sql);
            return trim(preg_replace('/\s+/', ' ', $sql));
        };
        self::assertSame($where($summaryCalls[0]['sql']), $where($detailCalls[0]['sql']));
    }

    public function test_epf_private_email_contains_real_pdf_and_workbook_with_progress_law_sender(): void
    {
        [$command, $mail] = $this->epfCommand();
        $this->configure($command, ['--month' => '2026-09', '--test' => true]);
        self::assertSame(Command::SUCCESS, $command->handle());
        self::assertCount(1, $mail->deliveries);
        $sent = $mail->deliveries[0];
        self::assertSame(['oduai@libertydebtrelief.com'], $sent['to']);
        self::assertSame([], $sent['cc']);
        self::assertSame([], $sent['bcc']);
        self::assertSame('invoices@progresslaw.com', $sent['from']);
        self::assertCount(2, $sent['attachments']);
        self::assertSame('EPF Adjustments - September 2026.xlsx', $sent['attachments'][0]['name']);
        self::assertSame('PL-PL-20260930.pdf', $sent['attachments'][1]['name']);
        self::assertSame('application/pdf', $sent['attachments'][1]['contentType']);
        self::assertStringStartsWith('%PDF-', base64_decode($sent['attachments'][1]['contentBytes'], true));
    }

    public function test_epf_normal_email_keeps_table_report_routing_with_progress_law_sender(): void
    {
        [$command, $mail] = $this->epfCommand();
        $this->configure($command, ['--month' => '2026-09']);
        self::assertSame(Command::SUCCESS, $command->handle());
        self::assertCount(1, $mail->deliveries);
        self::assertSame('table', $mail->deliveries[0]['route']);
        self::assertSame(['AdvanceRecoupReport'], $mail->deliveries[0]['reports']);
        self::assertSame(['LDR'], $mail->deliveries[0]['companies']);
        self::assertSame('invoices@progresslaw.com', $mail->deliveries[0]['from']);
        self::assertCount(2, $mail->deliveries[0]['attachments']);
    }

    public function test_epf_local_only_generates_pdf_without_email(): void
    {
        [$command, $mail] = $this->epfCommand();
        $this->configure($command, ['--month' => '2026-09', '--local-only' => true]);
        self::assertSame(Command::SUCCESS, $command->handle());
        self::assertSame([], $mail->deliveries);
        self::assertFileExists($this->storage . '/app/PL-PL-20260930.pdf');
    }

    private function configure(Command $command, array $options): BufferedOutput
    {
        $input = new ArrayInput($options, $command->getDefinition());
        $output = new BufferedOutput();
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));
        return $output;
    }

    private function epfCommand(): array
    {
        $db = new ReportFixtureConnector();
        $mail = new ReportCapturingMailer();
        $command = new class($db, $mail) extends GenerateAdvanceRecoupReport {
            public function __construct(private DBConnector $db, private EmailSenderService $mail)
            {
                parent::__construct();
            }

            protected function initializeSnowflakeConnector(): DBConnector { return $this->db; }
            protected function initializeSqlServerConnector(): DBConnector { return $this->db; }
            protected function emailSender(): EmailSenderService { return $this->mail; }
        };
        return [$command, $mail];
    }
}

final class ReportFixtureConnector extends DBConnector
{
    public array $sql = [];
    public function __construct() {}

    public function querySqlServer(string $sql, array $params = []): array
    {
        $this->sql[] = compact('sql', 'params');
        $data = [];
        if (str_contains($sql, 'Client AS Name')) {
            $data = [
                ['LLG_ID' => '000123', 'Name' => 'Fixture Client', 'Debt_Amount' => 125.5,
                    'First_Payment_Date' => '2026-09-07', 'First_Payment_Cleared_Date' => '2026-09-09'],
                ['LLG_ID' => '000124', 'Name' => 'Second Fixture', 'Debt_Amount' => 74.5,
                    'First_Payment_Date' => null, 'First_Payment_Cleared_Date' => null],
            ];
        } elseif (str_contains($sql, 'SELECT REPLACE(LLG_ID')) {
            $data = [['CONTACT_ID' => '1001', 'EPF_Rate' => 0.29]];
        } elseif (preg_match('/SELECT\s+(SUM|COUNT)\(/', $sql)) {
            $data = [['v' => str_contains($sql, 'Cancel_Date IS NULL AND NSF_Date IS NULL')
                && str_contains($sql, "Enrollment_Status IN('LDR Enrolled', 'ProLaw Enrolled', 'Approved')") ? 200 : 0]];
        }
        return ['success' => true, 'data' => $data, 'row_count' => count($data)];
    }

    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        $data = [];
        if (str_contains($sql, 'FROM CONTACTS')) {
            $data = [['ID' => '1001', 'FIRSTNAME' => 'Fixture', 'LASTNAME' => 'Client']];
        } elseif (str_contains($sql, "TRANS_TYPE = 'SA'")) {
            $data = [['CONTACT_ID' => '1001', 'TRANS_TYPE' => 'SA', 'AMOUNT' => 100,
                'PROCESS_DATE' => '2026-09-10', 'CLEARED_DATE' => '2026-09-11', 'MEMO' => 'Fixture advance']];
        }
        return ['success' => true, 'data' => $data, 'row_count' => count($data)];
    }
}

final class ReportCapturingMailer extends EmailSenderService
{
    public array $deliveries = [];
    public function __construct() {}

    public function sendMailHtml(string $subject, string $body, array $to, array $cc = [], array $bcc = [], array $attachments = [], ?string $fromAddress = null): bool
    {
        $this->deliveries[] = ['route' => 'direct', 'from' => $fromAddress] + compact('subject', 'body', 'to', 'cc', 'bcc', 'attachments');
        return true;
    }

    public function sendMailUsingTblReportsHtml(DBConnector $connector, array $reportNames, array $companies, string $subject, string $body, array $attachments = [], bool $includeEnvExtras = true, bool $strictCompany = false, ?string $fromAddress = null): bool
    {
        $this->deliveries[] = ['route' => 'table', 'from' => $fromAddress, 'reports' => $reportNames] + compact('companies', 'subject', 'body', 'attachments');
        return true;
    }
}
