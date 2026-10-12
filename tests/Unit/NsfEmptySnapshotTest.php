<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\Formatter;
use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Tests\Support\RecordingSqlServerConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\NullLogger;
use ReflectionMethod;

/** Filesystem-only contract: successful empty NSF data must replace stale manager inputs. */
final class NsfEmptySnapshotTest extends TestCase
{
    public function test_empty_rerun_writes_zero_roster_workbook_and_replaces_manager_snapshot(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsf-empty-' . bin2hex(random_bytes(8));
        $originalContainer = Container::getInstance();
        $originalLog = Log::getFacadeRoot();
        $files = [];
        mkdir($root . DIRECTORY_SEPARATOR . 'app', 0777, true);
        $app = new class($root) extends Container {
            public function __construct(private string $testStorage) {}

            public function storagePath(string $path = ''): string
            {
                return $this->testStorage . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path);
            }
        };
        Container::setInstance($app);
        Log::swap(new NullLogger);

        try {
            $formatter = new Formatter;
            $nsf = new GenerateNSFCommissionReport;
            $manager = new GenerateRetentionManagerCommission;
            $save = new ReflectionMethod($nsf, 'saveSnapshotCopy');
            $load = new ReflectionMethod($manager, 'loadNsfCommissionRowsFromXlsx');
            $paths = new ReflectionMethod($manager, 'defaultNsfSnapshotPaths');
            $buildSummary = new ReflectionMethod($nsf, 'buildCommissionRows');
            $summary = $buildSummary->invoke($nsf, [], ['Synthetic Agent'], new RecordingSqlServerConnector);
            $this->assertCount(1, $summary);
            $this->assertEquals(0, $summary[0]['commission']);
            $this->assertEquals(0, $summary[0]['assignments']);

            foreach (['LDR', 'Progress Law'] as $index => $source) {
                $old = $formatter->buildWorkbook([
                    ['ID' => '900001', 'AGENT' => 'Synthetic Agent', 'NSF_ACTION' => 'Recoup',
                        'NSF_RETURNED_DATE' => '2098-01-02', 'NSF_RECOUP_DATE' => '2098-01-03',
                        'CLEARED_DATE' => '2098-01-04', 'valid_commission' => true],
                ], $summary, $source, '2098-01-01', '2098-01-31');
                $files[] = $old['path'];
                $snapshot = $save->invoke($nsf, $old, '2098-01-01');
                $files[] = $snapshot;
                $this->assertCount(1, $load->invoke($manager, $snapshot, $source));

                $empty = $formatter->buildWorkbook([], $summary, $source, '2098-01-01', '2098-01-31');
                $this->assertSame($snapshot, $save->invoke($nsf, $empty, '2098-01-01'));
                $selected = $paths->invoke($manager, '2098-01-01');
                $this->assertSame($snapshot, $selected[$index]);
                $this->assertSame([], $load->invoke($manager, $selected[$index], $source));

                $workbook = IOFactory::load($selected[$index]);
                try {
                    $this->assertSame('Synthetic Agent', $workbook->getSheetByName('Agent Summary')->getCell('A2')->getValue());
                    $this->assertEquals(0, $workbook->getSheetByName('Agent Summary')->getCell('I2')->getValue());
                    $this->assertSame('ID', $workbook->getSheetByName('NSF Data')->getCell('A1')->getValue());
                    $this->assertNull($workbook->getSheetByName('NSF Data')->getCell('A2')->getValue());
                } finally {
                    $workbook->disconnectWorksheets();
                }
            }
        } finally {
            foreach (array_unique($files) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            foreach (['app/commission-snapshots/2098-01/nsf', 'app/commission-snapshots/2098-01', 'app/commission-snapshots', 'app', ''] as $relative) {
                $path = $root . ($relative === '' ? '' : DIRECTORY_SEPARATOR . $relative);
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
            Container::setInstance($originalContainer);
            if ($originalLog === null) {
                Log::clearResolvedInstance('log');
            } else {
                Log::swap($originalLog);
            }
        }
    }
}
