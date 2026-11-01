<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport\GenerateRetentionCommissionReport;
use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use ReflectionMethod;

final class RetentionSnapshotPublicationTest extends TestCase
{
    private string $root;
    private Container $previous;
    private array $file;
    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/retention-publish-' . bin2hex(random_bytes(8));
        $this->previous = Container::getInstance();
        mkdir($this->root . '/app', 0777, true);
        Container::setInstance(new class($this->root) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . '/' . $path; }
        });
        $this->file = ['path' => $this->root . '/app/source.xlsx', 'filename' => 'Retention Commission (LDR) - All.xlsx'];
        file_put_contents($this->file['path'], 'new synthetic workbook bytes');
        $this->destination = $this->root . '/app/commission-snapshots/2098-01/retention/Retention Commission Report - LDR - 01-2098.xlsx';
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    private function publish(GenerateRetentionCommissionReport $command, callable $persist): string
    {
        return (new ReflectionMethod($command, 'persistWithSnapshot'))->invoke($command, $this->file, 'LDR', '2098-01-01', $persist);
    }

    private function previousSnapshot(): void
    {
        mkdir(dirname($this->destination), 0777, true);
        file_put_contents($this->destination, 'previous snapshot');
    }

    public function test_stages_verified_bytes_before_persisting_then_publishes_the_expected_manager_path(): void
    {
        $this->previousSnapshot();
        $persisted = false;
        $path = $this->publish(new GenerateRetentionCommissionReport, function () use (&$persisted): void {
            $this->assertSame('previous snapshot', file_get_contents($this->destination));
            $this->assertCount(1, glob(dirname($this->destination) . '/.retention-*.tmp'));
            $persisted = true;
        });
        $this->assertTrue($persisted);
        $this->assertSame(realpath($this->destination), realpath($path));
        $this->assertSame(file_get_contents($this->file['path']), file_get_contents($path));
        $this->assertSame([], glob(dirname($path) . '/.retention-*.tmp'));
    }

    public function test_copy_failure_does_not_persist_or_replace_the_previous_snapshot(): void
    {
        $this->previousSnapshot();
        $command = new class extends GenerateRetentionCommissionReport {
            protected function copySnapshotFile(string $source, string $destination): bool { return false; }
        };
        try {
            $this->publish($command, fn () => $this->fail('No database writes on copy failure.'));
            $this->fail('Expected copy failure.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Cannot copy retention', $error->getMessage());
        }
        $this->assertSame('previous snapshot', file_get_contents($this->destination));
    }

    public function test_partial_copy_is_rejected_before_persistence(): void
    {
        $command = new class extends GenerateRetentionCommissionReport {
            protected function copySnapshotFile(string $source, string $destination): bool { file_put_contents($destination, 'partial'); return true; }
        };
        $this->expectExceptionMessage('snapshot verification failed');
        $this->publish($command, fn () => $this->fail('No database writes on corrupt copy.'));
    }

    public function test_persistence_failure_preserves_the_previous_snapshot(): void
    {
        $this->previousSnapshot();
        try {
            $this->publish(new GenerateRetentionCommissionReport, static fn () => throw new \RuntimeException('synthetic write failure'));
            $this->fail('Expected persistence failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic write failure', $error->getMessage());
        }
        $this->assertSame('previous snapshot', file_get_contents($this->destination));
        $this->assertSame([], glob(dirname($this->destination) . '/.retention-*.tmp'));
    }

    public function test_directory_and_publish_failures_are_explicit(): void
    {
        mkdir($this->destination, 0777, true);
        $this->expectExceptionMessage('results were saved, but the manager snapshot could not be published');
        $this->publish(new GenerateRetentionCommissionReport, static function (): void {});
    }

    public function test_directory_creation_failure_prevents_persistence(): void
    {
        file_put_contents($this->root . '/app/commission-snapshots', 'blocking file');
        $this->expectExceptionMessage('Cannot create retention commission snapshot directory');
        $this->publish(new GenerateRetentionCommissionReport, fn () => $this->fail('No database writes on mkdir failure.'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('snapshotCompanies')]
    public function test_published_snapshot_keeps_the_existing_name_and_matches_the_manager_reader(string $company, int $index): void
    {
        $command = new GenerateRetentionCommissionReport;
        $this->file['filename'] = "Retention Commission ({$company}) - All.xlsx";
        $published = (new ReflectionMethod($command, 'saveSnapshotCopy'))->invoke($command, $this->file, $company, '2098-01-01');
        $expected = $this->root . "/app/commission-snapshots/2098-01/retention/Retention Commission Report - {$company} - 01-2098.xlsx";
        $this->assertSame(str_replace('\\', '/', $expected), str_replace('\\', '/', $published));
        $readerPaths = (new ReflectionMethod(GenerateRetentionManagerCommission::class, 'defaultRetentionSnapshotPaths'))
            ->invoke(new GenerateRetentionManagerCommission, '2098-01-01');
        $this->assertSame(realpath($published), realpath($readerPaths[$index]));
        $this->assertSame(file_get_contents($this->file['path']), file_get_contents($readerPaths[$index]));
        $this->assertSame([], glob(dirname($published) . '/.retention-*.tmp'));
    }

    public static function snapshotCompanies(): array
    {
        return ['LDR' => ['LDR', 0], 'Progress Law' => ['Progress Law', 1]];
    }
}
