<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class RetentionManagerSnapshotPrerequisitesTest extends TestCase
{
    private Container $previous;
    private string $root;
    private mixed $previousLog;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $this->previousLog = Log::getFacadeRoot();
        Log::swap(new NullLogger);
        $this->root = sys_get_temp_dir() . '/retention-prerequisites-' . bin2hex(random_bytes(8));
        Container::setInstance(new class($this->root) extends Container {
            public function __construct(private string $root) {}
            public function storagePath(string $path = ''): string { return $this->root . '/' . $path; }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) unlink($file);
        Container::setInstance($this->previous);
        Log::swap($this->previousLog);
        parent::tearDown();
    }

    public function test_all_requires_both_retention_and_both_nsf_inputs_before_work_starts(): void
    {
        $command = new GenerateRetentionManagerCommission;
        $paths = $command->requiredSnapshotPaths('all', '2026-09-01');
        $this->assertCount(4, $paths);
        $this->assertStringEndsWith('/retention' . DIRECTORY_SEPARATOR . 'Retention Commission Report - LDR - 09-2026.xlsx', $paths[0]);
        $this->assertStringEndsWith('Retention Commission Report - Progress Law - 09-2026.xlsx', $paths[1]);
        $this->assertStringContainsString('/2026-09/nsf', $paths[2]);
        $this->assertStringContainsString('/2026-09/nsf', $paths[3]);
    }

    public function test_report_specific_requirements_and_explicit_paths_are_preserved(): void
    {
        $command = new GenerateRetentionManagerCommission;
        $this->assertCount(2, $command->requiredSnapshotPaths('nick', '2026-09-01'));
        $this->assertCount(2, $command->requiredSnapshotPaths('anthony', '2026-09-01'));
        $this->assertSame([], $command->requiredSnapshotPaths('reconcile', '2026-09-01'));
        $this->assertSame(['a.xlsx', 'b.xlsx'], $command->requiredSnapshotPaths('both', '2026-09-01', ['retention-ldr' => 'a.xlsx', 'retention-plaw' => 'b.xlsx']));
        $this->assertSame(['all.xlsx'], $command->requiredSnapshotPaths('rama', '2026-09-01', ['all-data-xlsx' => 'all.xlsx']));
    }

    public function test_missing_inputs_return_failure_before_any_reader_or_database_is_reached(): void
    {
        $command = new GenerateRetentionManagerCommission;
        $input = new ArrayInput(['report' => 'all', 'period' => '2026-09-01', '--require-snapshots' => true, '--no-email' => true], $command->getDefinition());
        $output = new BufferedOutput;
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));
        $this->assertSame(1, $command->handle());
        $message = $output->fetch();
        $this->assertStringContainsString('Required commission snapshot files are not ready', $message);
        $this->assertStringContainsString('Retention Commission Report - LDR - 09-2026.xlsx', $message);
        $this->assertStringContainsString('NSF Commission Report - Progress Law - 09-2026.xlsx', $message);
    }

    public function test_incomplete_explicit_pair_is_a_configuration_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GenerateRetentionManagerCommission)->requiredSnapshotPaths('all', '2026-09-01', ['retention-ldr' => 'a.xlsx']);
    }

    public function test_empty_publication_is_not_ready_and_only_missing_inputs_are_reported(): void
    {
        $ready = tempnam(sys_get_temp_dir(), 'ret-ready-');
        $empty = tempnam(sys_get_temp_dir(), 'ret-empty-');
        $this->files = [$ready, $empty];
        file_put_contents($ready, 'published bytes');
        $this->assertSame([$empty, 'missing.xlsx'], (new GenerateRetentionManagerCommission)->missingSnapshotPaths([$ready, $empty, 'missing.xlsx']));
    }
}
