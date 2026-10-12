<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\GenerateNSFCommissionReport;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use ReflectionMethod;

final class NsfSnapshotPublicationTest extends TestCase
{
    private string $root;
    private Container $originalContainer;
    private array $file;
    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsf-publish-' . bin2hex(random_bytes(8));
        $this->originalContainer = Container::getInstance();
        mkdir($this->root . '/app', 0777, true);
        Container::setInstance(new class($this->root) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . '/' . $path; }
        });
        $this->file = ['path' => $this->root . '/app/synthetic.xlsx', 'filename' => 'synthetic.xlsx'];
        file_put_contents($this->file['path'], 'new synthetic workbook bytes');
        $this->destination = $this->root . '/app/commission-snapshots/2098-01/nsf/synthetic.xlsx';
    }

    protected function tearDown(): void
    {
        // Remove only this test's randomly named temporary directory, child-first.
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        Container::setInstance($this->originalContainer);
        parent::tearDown();
    }

    private function previousSnapshot(): void
    {
        mkdir(dirname($this->destination), 0777, true);
        file_put_contents($this->destination, 'previous snapshot');
    }

    private function publish(GenerateNSFCommissionReport $command, callable $persist): string
    {
        return (new ReflectionMethod(GenerateNSFCommissionReport::class, 'persistWithSnapshot'))->invoke($command, $this->file, '2098-01-01', $persist);
    }

    private function assertNoStagedFiles(): void
    {
        $this->assertSame([], glob(dirname($this->destination) . '/.nsf-*.tmp'));
    }

    public function test_snapshot_is_staged_before_persistence_and_published_after_it(): void
    {
        $this->previousSnapshot();
        $persisted = false;
        $destination = $this->publish(new GenerateNSFCommissionReport, function () use (&$persisted): void {
            $this->assertSame('previous snapshot', file_get_contents($this->destination));
            $staged = glob(dirname($this->destination) . '/.nsf-*.tmp');
            $this->assertCount(1, $staged);
            $this->assertSame(file_get_contents($this->file['path']), file_get_contents($staged[0]));
            $persisted = true;
        });
        $this->assertTrue($persisted);
        $this->assertSame(realpath($this->destination), realpath($destination));
        $this->assertSame(file_get_contents($this->file['path']), file_get_contents($destination));
        $this->assertNoStagedFiles();
    }

    public function test_missing_workbook_prevents_persistence(): void
    {
        unlink($this->file['path']);
        $this->expectExceptionMessage('workbook file is missing');
        $this->publish(new GenerateNSFCommissionReport, fn () => $this->fail('No SQL write before a valid workbook.'));
    }

    public function test_directory_creation_failure_prevents_persistence(): void
    {
        file_put_contents($this->root . '/app/commission-snapshots', 'blocking file');
        $this->expectExceptionMessage('Cannot create NSF commission snapshot directory');
        $this->publish(new GenerateNSFCommissionReport, fn () => $this->fail('No SQL write on mkdir failure.'));
    }

    public function test_copy_failure_keeps_previous_snapshot_and_prevents_persistence(): void
    {
        $this->previousSnapshot();
        $command = new class extends GenerateNSFCommissionReport {
            protected function copySnapshotFile(string $source, string $destination): bool { return false; }
        };
        try {
            $this->publish($command, fn () => $this->fail('No SQL write on copy failure.'));
            $this->fail('Expected copy failure.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Cannot copy NSF', $error->getMessage());
        }
        $this->assertSame('previous snapshot', file_get_contents($this->destination));
        $this->assertNoStagedFiles();
    }

    public function test_partial_copy_fails_verification_before_persistence(): void
    {
        $this->previousSnapshot();
        $command = new class extends GenerateNSFCommissionReport {
            protected function copySnapshotFile(string $source, string $destination): bool
            {
                file_put_contents($destination, 'truncated');
                return true;
            }
        };
        try {
            $this->publish($command, fn () => $this->fail('No SQL write on corrupt staged file.'));
            $this->fail('Expected verification failure.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('verification failed', $error->getMessage());
        }
        $this->assertSame('previous snapshot', file_get_contents($this->destination));
        $this->assertNoStagedFiles();
    }

    public function test_database_failure_does_not_publish_staged_snapshot(): void
    {
        $this->previousSnapshot();
        try {
            $this->publish(new GenerateNSFCommissionReport, static fn () => throw new \RuntimeException('synthetic database rollback'));
            $this->fail('Expected persistence failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic database rollback', $error->getMessage());
        }
        $this->assertSame('previous snapshot', file_get_contents($this->destination));
        $this->assertNoStagedFiles();
    }

    public function test_publish_failure_reports_committed_database_without_success_or_delivery(): void
    {
        mkdir($this->destination, 0777, true); // A directory cannot be replaced by the file.
        $persisted = false;
        $delivered = false;
        try {
            $this->publish(new GenerateNSFCommissionReport, static function () use (&$persisted): void { $persisted = true; });
            $delivered = true; // Models the delivery branch reached only after publication returns.
            $this->fail('Expected snapshot publish failure.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('results were saved', $error->getMessage());
            $this->assertStringContainsString('No email was sent', $error->getMessage());
            $this->assertStringContainsString('Reconcile and rerun', $error->getMessage());
        }
        $this->assertTrue($persisted);
        $this->assertFalse($delivered);
        $this->assertDirectoryExists($this->destination);
        $this->assertNoStagedFiles();
    }
}
