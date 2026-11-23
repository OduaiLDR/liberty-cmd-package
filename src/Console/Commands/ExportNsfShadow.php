<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\NsfReportReadiness;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ExportNsfShadow extends Command
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    protected $signature = 'payroll:export-nsf-shadow {period} {runId}';
    protected $description = 'Export both verified NSF structured shadow captures for a payroll run';

    public function handle(PayrollReportArtifactArchive $archive): int
    {
        // Runner's automation executor persists Artisan::call() output in its job log.
        // Only the direct Artisan CLI supplies ArgvInput and a real console output together.
        if (PHP_SAPI !== 'cli' || $this->input::class !== ArgvInput::class
            || !($this->getOutput()->getOutput() instanceof ConsoleOutput)) {
            return self::FAILURE;
        }
        $period = (string) $this->argument('period');
        $runId = (string) $this->argument('runId');
        $readinessIds = [$runId];
        $sources = [];

        try {
            foreach (['ldr', 'plaw'] as $source) {
                // Filesystem warnings can include private archive paths if a file becomes unreadable.
                $artifact = @$archive->read('nsf_' . $source, $period, $runId);
                $context = $artifact['context'];
                $capture = $context['nsfStructuredCapture'] ?? null;
                if (($context['queryOnly'] ?? null) !== true || !is_array($capture)
                    || ($capture['version'] ?? null) !== 1 || ($capture['source'] ?? null) !== $source
                    || ($capture['period'] ?? null) !== $period || ($capture['runId'] ?? null) !== $runId
                    || !in_array($capture['rosterBasis'] ?? null, ['managed', 'fallback'], true)) {
                    return self::FAILURE;
                }
                foreach (['contacts', 'agents', 'unassigned'] as $field) {
                    if (!is_array($capture[$field] ?? null) || !array_is_list($capture[$field])) {
                        return self::FAILURE;
                    }
                }

                $readiness = $capture['readiness'] ?? null;
                if (!is_array($readiness) || ($readiness['source'] ?? null) !== $source || ($readiness['period'] ?? null) !== $period) {
                    return self::FAILURE;
                }
                $readinessId = $readiness['runId'] ?? null;
                if (!is_string($readinessId) || !preg_match('/^[a-f0-9]{32}$/D', $readinessId)
                    || in_array($readinessId, $readinessIds, true) || !$this->matchesReadinessTiming($readiness, $period)) {
                    return self::FAILURE;
                }
                $readinessIds[] = $readinessId;

                $captureJson = json_encode($capture, self::JSON_FLAGS);
                $sources[$source] = [
                    'workbookSha256' => $artifact['sha256'],
                    'captureJson' => $captureJson,
                    'captureSha256' => hash('sha256', $captureJson),
                ];
            }

            $envelope = json_encode(['version' => 1, 'runId' => $runId, 'period' => $period, 'sources' => $sources], self::JSON_FLAGS);
        } catch (\Throwable) {
            return self::FAILURE;
        }

        $this->getOutput()->writeln($envelope, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

        return self::SUCCESS;
    }

    private function matchesReadinessTiming(array $readiness, string $period): bool
    {
        if (!is_string($readiness['startedAt'] ?? null)) {
            return false;
        }
        $startedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $readiness['startedAt'], new DateTimeZone('UTC'));
        if ($startedAt === false) {
            return false;
        }
        foreach (NsfReportReadiness::timing($period, $startedAt) as $field => $value) {
            if (($readiness[$field] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
