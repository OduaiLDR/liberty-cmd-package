<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class NsfManagerReadinessTest extends TestCase
{
    public function test_team_leader_reads_run_ids_from_the_same_workbooks_as_nsf_rows(): void
    {
        $directory = sys_get_temp_dir() . '/nsf-manager-readiness-' . bin2hex(random_bytes(8));
        mkdir($directory);

        try {
            $command = new GenerateRetentionManagerCommission();
            $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
            $method = new \ReflectionMethod($command, 'loadNsfCommissionRowsFromXlsx');
            $runIds = [];
            foreach ([
                'ldr' => ['LDR', '11111111111111111111111111111111'],
                'plaw' => ['Progress Law', '22222222222222222222222222222222'],
            ] as $source => [$display, $runId]) {
                $path = $directory . '/' . $source . '.xlsx';
                $book = new Spreadsheet();
                $data = $book->getActiveSheet();
                $data->setTitle('NSF Data');
                $data->fromArray([['ID', 'Agent', 'NSF Returned Date', 'NSF Action', 'NSF Recoup Date', 'Cleared Date', 'Valid Commission'],
                    [1, 'Synthetic NSF Agent', null, 'Resubmitted', null, '2098-09-02', '1.00']], null, 'A1');
                $status = $book->createSheet();
                $status->setTitle('Run Status');
                $status->fromArray([['Field', 'Value'], ['Run ID', $runId]], null, 'A1');
                (new Xlsx($book))->save($path);
                $book->disconnectWorksheets();

                $args = [$path, $display, &$runIds, $source];
                $rows = $method->invokeArgs($command, $args);
                $this->assertCount(1, $rows);
                $this->assertSame('Synthetic NSF Agent', $rows[0]['AGENT']);
            }
            $this->assertSame([
                'ldr' => '11111111111111111111111111111111',
                'plaw' => '22222222222222222222222222222222',
            ], $runIds);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }
}
