<?php

namespace Cmd\Reports\Console\Commands\GenerateSyncSummary;

use PDO;

class StatusBuilder
{
    private const MAX_LOG_HISTORY_PER_MACRO = 8;

    public function __construct(private PDO $sqlConnection)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildRows(): array
    {
        $scheduleMap = $this->loadKernelScheduleMap();
        $codeMetadata = $this->loadCodeMetadata();
        $items = array_merge($this->loadReportItems(), $this->loadAutomationItems());
        $logHistoryMap = $this->loadRecentLogHistoryMap($codeMetadata);

        $rows = [];
        foreach ($items as $item) {
            $code = $this->matchCodeMetadata(
                (string) ($item['type'] ?? ''),
                (string) ($item['name'] ?? ''),
                $codeMetadata
            );

            $candidates = $this->buildInventoryCandidates(
                (string) ($item['type'] ?? ''),
                (string) ($item['name'] ?? ''),
                $item['candidates'] ?? [],
                $code
            );

            $logData = $this->resolveLogData(
                $candidates,
                (string) ($item['scope'] ?? ''),
                (string) ($item['type'] ?? ''),
                $logHistoryMap
            );
            [$displaySchedule, $scheduleSource, $timing] = $this->resolveScheduleAndTiming(
                (string) ($item['schedule_text'] ?? ''),
                (string) ($code['base_signature'] ?? ''),
                $scheduleMap,
                $logData
            );

            $rows[] = [
                'name' => (string) ($item['name'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
                'scope' => (string) ($item['scope'] ?? ''),
                'source' => (string) ($item['source'] ?? ''),
                'command' => (string) ($code['signature'] ?? '[Not found in package]'),
                'description' => (string) ($code['description'] ?? ''),
                'schedule' => $displaySchedule,
                'schedule_source' => $scheduleSource,
                'last_scheduled' => $timing['last_scheduled'],
                'last_run' => $this->formatDateTime($logData['best']['last_run'] ?? null),
                'last_result' => (string) ($logData['best']['result'] ?? ''),
                'status' => $timing['status'],
                'next_expected' => $timing['next_expected'],
                'is_overdue' => $timing['is_overdue'],
                'needs_attention' => $timing['needs_attention'],
                'evidence' => $timing['evidence'],
                '_candidates' => $candidates,
            ];
        }

        usort($rows, function (array $left, array $right): int {
            return [$left['type'], $left['name'], $left['scope']] <=> [$right['type'], $right['name'], $right['scope']];
        });

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>>|null $trackedRows
     * @return array<int, array<string, mixed>>
     */
    public function filterTrackedLogRows(array $rows, ?array $trackedRows = null): array
    {
        $trackedRows ??= $this->buildRows();

        $lookup = [];
        foreach ($trackedRows as $row) {
            foreach (($row['_candidates'] ?? []) as $candidate) {
                $normalized = $this->normalizeName((string) $candidate);
                if ($normalized !== '') {
                    $lookup[$normalized] = true;
                }
            }
        }

        return array_values(array_filter($rows, function (array $row) use ($lookup): bool {
            $macro = trim((string) ($row['Macro_Name'] ?? $row['Macro'] ?? ''));
            if ($macro === '') {
                return false;
            }

            return isset($lookup[$this->normalizeName($macro)]);
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadReportItems(): array
    {
        $sql = "
            SELECT DISTINCT
                LTRIM(RTRIM([Report_Name])) AS Report_Name,
                LTRIM(RTRIM(COALESCE([Company], ''))) AS Company,
                LTRIM(RTRIM(COALESCE([Schedule], ''))) AS Schedule
            FROM dbo.TblReports
            WHERE [Report_Name] IS NOT NULL
              AND LTRIM(RTRIM([Report_Name])) <> ''
            ORDER BY LTRIM(RTRIM([Report_Name])), LTRIM(RTRIM(COALESCE([Company], '')))
        ";

        $stmt = $this->sqlConnection->query($sql);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];

        foreach ($records as $record) {
            $name = trim((string) ($record['Report_Name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $items[] = [
                'name' => $name,
                'type' => 'Report',
                'scope' => trim((string) ($record['Company'] ?? '')) ?: 'All',
                'source' => 'TblReports',
                'schedule_text' => trim((string) ($record['Schedule'] ?? '')),
                'candidates' => [$name],
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadAutomationItems(): array
    {
        $sql = "
            SELECT DISTINCT
                LTRIM(RTRIM([Automation_Name])) AS Automation_Name,
                LTRIM(RTRIM(COALESCE([Table_Name], ''))) AS Table_Name,
                LTRIM(RTRIM(COALESCE([Schedule], ''))) AS Schedule
            FROM dbo.TblAutomation
            WHERE [Automation_Name] IS NOT NULL
              AND LTRIM(RTRIM([Automation_Name])) <> ''
            ORDER BY LTRIM(RTRIM([Automation_Name]))
        ";

        $stmt = $this->sqlConnection->query($sql);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];

        foreach ($records as $record) {
            $name = trim((string) ($record['Automation_Name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $items[] = [
                'name' => $name,
                'type' => 'Automation',
                'scope' => trim((string) ($record['Table_Name'] ?? '')) ?: 'N/A',
                'source' => 'TblAutomation',
                'schedule_text' => trim((string) ($record['Schedule'] ?? '')),
                'candidates' => [$name],
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCodeMetadata(): array
    {
        $rows = [];

        foreach ($this->loadPackageCommandFiles() as $filePath) {
            $shortName = basename($filePath, '.php');
            if (in_array($shortName, ['SeedCmdReportPermissions', 'TestDatabaseConnections'], true)) {
                continue;
            }

            $type = null;
            if (str_starts_with($shortName, 'Generate')) {
                $type = 'Report';
            } elseif (preg_match('/^(Sync|Update|Import|Refresh|Process)/', $shortName)) {
                $type = 'Automation';
            }

            if ($type === null) {
                continue;
            }

            $contents = (string) file_get_contents($filePath);
            $signature = $this->extractQuotedProperty($contents, 'signature') ?? $this->fallbackSignature($shortName);
            $baseSignature = strtok(trim(preg_replace('/\s+/', ' ', $signature) ?? $signature), ' ') ?: $signature;
            $description = $this->extractQuotedProperty($contents, 'description') ?? '';

            $rows[] = [
                'type' => $type,
                'short_name' => $shortName,
                'signature' => $signature,
                'base_signature' => $baseSignature,
                'description' => $description,
                'candidates' => $this->buildCandidates($shortName, $this->humanizeName($shortName)),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $metadataRows
     */
    private function matchCodeMetadata(string $preferredType, string $name, array $metadataRows): ?array
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        foreach ([true, false] as $sameTypeOnly) {
            foreach ($metadataRows as $metadata) {
                if ($sameTypeOnly && (string) ($metadata['type'] ?? '') !== $preferredType) {
                    continue;
                }

                foreach (($metadata['candidates'] ?? []) as $candidate) {
                    if ($this->normalizeName((string) $candidate) === $normalized) {
                        return $metadata;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $inventoryCandidates
     * @param array<string, mixed>|null $code
     * @return array<int, string>
     */
    private function buildInventoryCandidates(string $type, string $name, array $inventoryCandidates, ?array $code): array
    {
        $candidates = array_merge([$name], $inventoryCandidates, $code['candidates'] ?? []);

        if ($code !== null) {
            $candidates[] = (string) ($code['signature'] ?? '');
            $candidates[] = (string) ($code['base_signature'] ?? '');
        }

        if ($type === 'Automation' && strcasecmp($name, 'UpdateDebtAccounts') === 0) {
            $candidates[] = 'SyncDebtAccounts';
        }

        if ($type === 'Automation' && strcasecmp($name, 'SyncSettledDebts') === 0) {
            $candidates[] = 'SyncSettledDebtsData';
            $candidates[] = 'Sync Settled Debts Data';
        }

        if ($type === 'Report' && strcasecmp($name, 'LendingUSAStatusUpdateReport') === 0) {
            $candidates[] = 'LendingUSAStatusReport';
            $candidates[] = 'UpdateLendingUSAStatuses';
        }

        if ($type === 'Report' && strcasecmp($name, 'ProgramCompletionsReport') === 0) {
            $candidates[] = 'ProcessProgramCompletions';
        }

        return array_values(array_unique(array_filter(array_map('trim', $candidates))));
    }

    /**
     * @param array<int, array<string, mixed>> $metadataRows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function loadRecentLogHistoryMap(array $metadataRows): array
    {
        $sql = "
            WITH RankedLog AS (
                SELECT
                    LTRIM(RTRIM([Macro])) AS Macro_Name,
                    LTRIM(RTRIM(COALESCE([Description], ''))) AS Description_Text,
                    LTRIM(RTRIM(COALESCE([Action], ''))) AS Action_Text,
                    LTRIM(RTRIM(COALESCE([Result], ''))) AS Result_Text,
                    [Timestamp] AS Last_Run_Date,
                    ROW_NUMBER() OVER (
                        PARTITION BY LTRIM(RTRIM([Macro]))
                        ORDER BY [Timestamp] DESC, [PK] DESC
                    ) AS Row_Number
                FROM dbo.TblLog
                WHERE [Macro] IS NOT NULL
                  AND LTRIM(RTRIM([Macro])) <> ''
            )
            SELECT Macro_Name, Description_Text, Action_Text, Result_Text, Last_Run_Date
            FROM RankedLog
            WHERE Row_Number <= " . self::MAX_LOG_HISTORY_PER_MACRO . "
        ";

        $stmt = $this->sqlConnection->query($sql);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $historyMap = [];

        foreach ($records as $record) {
            $macro = trim((string) ($record['Macro_Name'] ?? ''));
            $normalized = $this->normalizeName($macro);
            if ($normalized === '') {
                continue;
            }

            $entry = [
                'macro' => $macro,
                'description' => trim((string) ($record['Description_Text'] ?? '')),
                'action' => trim((string) ($record['Action_Text'] ?? '')),
                'result' => trim((string) ($record['Result_Text'] ?? '')),
                'last_run' => $this->parseSqlServerTimestamp((string) ($record['Last_Run_Date'] ?? '')),
                'source' => 'TblLog',
                'scopes' => $this->extractScopes(
                    trim((string) ($record['Description_Text'] ?? '')),
                    trim((string) ($record['Result_Text'] ?? ''))
                ),
            ];

            $this->appendHistoryEntry($historyMap, $normalized, $entry);

            $actionNormalized = $this->normalizeName((string) ($entry['action'] ?? ''));
            if ($actionNormalized !== '') {
                $this->appendHistoryEntry($historyMap, $actionNormalized, $entry);
            }
        }

        foreach ($this->loadLaravelLogFallbackMap($metadataRows) as $normalized => $entries) {
            foreach ($entries as $entry) {
                $this->appendHistoryEntry($historyMap, $normalized, $entry);
            }
        }

        foreach ($historyMap as &$entries) {
            usort($entries, fn(array $left, array $right): int => ($right['last_run'] ?? null) <=> ($left['last_run'] ?? null));
            $entries = array_slice($entries, 0, self::MAX_LOG_HISTORY_PER_MACRO);
        }
        unset($entries);

        return $historyMap;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $historyMap
     * @param array<string, mixed> $entry
     */
    private function appendHistoryEntry(array &$historyMap, string $normalized, array $entry): void
    {
        $historyMap[$normalized] ??= [];

        foreach ($historyMap[$normalized] as $existing) {
            if (($existing['source'] ?? '') === ($entry['source'] ?? '')
                && ($existing['last_run'] ?? null) == ($entry['last_run'] ?? null)
                && ($existing['result'] ?? '') === ($entry['result'] ?? '')
            ) {
                return;
            }
        }

        $historyMap[$normalized][] = $entry;
    }

    /**
     * @param array<int, array<string, mixed>> $metadataRows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function loadLaravelLogFallbackMap(array $metadataRows): array
    {
        $logPath = storage_path('logs/laravel.log');
        if (!is_file($logPath)) {
            return [];
        }

        $contents = (string) @file_get_contents($logPath);
        if ($contents === '') {
            return [];
        }

        $map = [];

        foreach ($metadataRows as $metadata) {
            $shortName = trim((string) ($metadata['short_name'] ?? ''));
            if ($shortName === '') {
                continue;
            }

            $normalizedCandidates = [];
            foreach (($metadata['candidates'] ?? []) as $candidate) {
                $normalized = $this->normalizeName((string) $candidate);
                if ($normalized !== '') {
                    $normalizedCandidates[$normalized] = true;
                }
            }

            if (empty($normalizedCandidates)) {
                continue;
            }

            $patterns = [
                '/^\[([0-9:\-\s]+)\]\s+local\.(INFO|ERROR|WARNING):\s+' . preg_quote($shortName, '/') . ' command finished\.(.*)$/mi',
                '/^\[([0-9:\-\s]+)\]\s+local\.(INFO|ERROR|WARNING):\s+' . preg_quote($shortName, '/') . ': connection finished\.(.*)$/mi',
            ];

            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
                    continue;
                }

                $lastMatch = end($matches);
                if (!is_array($lastMatch)) {
                    continue;
                }

                $entry = [
                    'macro' => (string) ($metadata['base_signature'] ?? $shortName),
                    'description' => $shortName . ' fallback',
                    'action' => 'LARAVEL_LOG',
                    'result' => trim(sprintf('%s %s', $lastMatch[2] ?? 'INFO', trim((string) ($lastMatch[3] ?? '')))),
                    'last_run' => $this->parseLaravelLogTimestamp((string) ($lastMatch[1] ?? '')),
                    'source' => 'Laravel log',
                    'scopes' => $this->extractScopes($shortName, trim((string) ($lastMatch[3] ?? ''))),
                ];

                foreach (array_keys($normalizedCandidates) as $normalized) {
                    $this->appendHistoryEntry($map, $normalized, $entry);
                }
                break;
            }
        }

        return $map;
    }

    /**
     * @param array<int, string> $candidates
     * @param array<string, array<int, array<string, mixed>>> $historyMap
     * @return array{best:array<string, mixed>|null,history:array<int, array<string, mixed>>,match_quality:string}
     */
    private function resolveLogData(array $candidates, string $scope, string $type, array $historyMap): array
    {
        $entries = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeName((string) $candidate);
            if ($normalized === '' || !isset($historyMap[$normalized])) {
                continue;
            }

            foreach ($historyMap[$normalized] as $entry) {
                $dedupeKey = implode('|', [
                    (string) ($entry['source'] ?? ''),
                    (string) ($entry['macro'] ?? ''),
                    (string) (($entry['last_run'] ?? null) instanceof \DateTimeImmutable ? $entry['last_run']->format('c') : ''),
                    (string) ($entry['result'] ?? ''),
                ]);
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $entries[] = $entry;
            }
        }

        usort($entries, fn(array $left, array $right): int => ($right['last_run'] ?? null) <=> ($left['last_run'] ?? null));

        if ($entries === []) {
            return [
                'best' => null,
                'history' => [],
                'match_quality' => 'none',
            ];
        }

        if ($type !== 'Report') {
            $tblLogEntries = array_values(array_filter(
                $entries,
                static fn(array $entry): bool => (string) ($entry['source'] ?? '') === 'TblLog'
            ));

            if ($tblLogEntries === []) {
                return [
                    'best' => null,
                    'history' => [],
                    'match_quality' => 'none',
                ];
            }

            return [
                'best' => $tblLogEntries[0],
                'history' => $tblLogEntries,
                'match_quality' => 'generic',
            ];
        }

        $bestEntry = null;
        $bestScore = -1;

        foreach ($entries as $entry) {
            $score = $this->scoreScopeMatch((string) $scope, $entry['scopes'] ?? []);
            if ($score > $bestScore) {
                $bestEntry = $entry;
                $bestScore = $score;
                continue;
            }

            if ($score === $bestScore && ($entry['last_run'] ?? null) > ($bestEntry['last_run'] ?? null)) {
                $bestEntry = $entry;
            }
        }

        $matchQuality = match (true) {
            $bestScore >= 3 => 'exact',
            $bestScore === 2 => 'shared',
            $bestScore === 1 => 'generic',
            default => 'scope_mismatch',
        };

        return [
            'best' => $bestEntry,
            'history' => $entries,
            'match_quality' => $matchQuality,
        ];
    }

    /**
     * @return array<string, array{label:string, source:string, interval_minutes?:int, daily_times?:array<int, string>}>
     */
    private function loadKernelScheduleMap(): array
    {
        $kernelPath = base_path('app/Console/Kernel.php');
        if (!is_file($kernelPath)) {
            return [];
        }

        $contents = (string) file_get_contents($kernelPath);
        preg_match_all('/\$schedule->command\(\'([^\']+)\'\)([^;]*);/', $contents, $matches, PREG_SET_ORDER);

        $map = [];
        foreach ($matches as $match) {
            $commandString = trim($match[1]);
            $chain = $match[2] ?? '';
            $baseCommand = strtok(trim(preg_replace('/\s+/', ' ', $commandString) ?? $commandString), ' ') ?: '';
            if ($baseCommand === '') {
                continue;
            }

            $parsed = $this->parseKernelScheduleChain($chain);
            $parsed['source'] = 'Laravel scheduler';
            $map[$baseCommand] = $parsed;
        }

        return $map;
    }

    /**
     * @return array{label:string, interval_minutes?:int, daily_times?:array<int, string>}
     */
    private function parseKernelScheduleChain(string $chain): array
    {
        if (str_contains($chain, 'everyFiveMinutes')) {
            return ['label' => 'Every 5 minutes', 'interval_minutes' => 5];
        }

        if (str_contains($chain, 'everyTenMinutes')) {
            return ['label' => 'Every 10 minutes', 'interval_minutes' => 10];
        }

        if (preg_match_all("/dailyAt\\('([^']+)'\\)/", $chain, $dailyMatches) && !empty($dailyMatches[1])) {
            $times = array_values(array_unique($dailyMatches[1]));

            return [
                'label' => 'Daily at ' . implode(', ', $times),
                'daily_times' => $times,
            ];
        }

        if (str_contains($chain, 'daily()')) {
            return [
                'label' => 'Daily',
                'daily_times' => ['23:59'],
            ];
        }

        return ['label' => 'Scheduled'];
    }

    /**
     * @param array<string, array<string, mixed>> $scheduleMap
     * @param array{best:array<string, mixed>|null,history:array<int, array<string, mixed>>,match_quality:string} $logData
     * @return array{0:string,1:string,2:array{is_overdue:bool,needs_attention:bool,status:string,last_scheduled:string,next_expected:string,evidence:string}}
     */
    private function resolveScheduleAndTiming(string $explicitSchedule, string $baseSignature, array $scheduleMap, array $logData): array
    {
        if ($explicitSchedule !== '') {
            return [
                $explicitSchedule,
                'Inventory table',
                $this->determineScheduleTextTiming($explicitSchedule, $logData),
            ];
        }

        if ($baseSignature !== '' && isset($scheduleMap[$baseSignature])) {
            return [
                (string) ($scheduleMap[$baseSignature]['label'] ?? 'Scheduled'),
                (string) ($scheduleMap[$baseSignature]['source'] ?? 'Laravel scheduler'),
                $this->determineKernelTiming($scheduleMap[$baseSignature], $logData),
            ];
        }

        return [
            'Not scheduled',
            'No schedule found',
            $this->determineUnscheduledTiming($logData),
        ];
    }

    /**
     * @param array{label:string, source:string, interval_minutes?:int, daily_times?:array<int, string>} $schedule
     * @param array{best:array<string, mixed>|null,history:array<int, array<string, mixed>>,match_quality:string} $logData
     * @return array{is_overdue:bool,needs_attention:bool,status:string,last_scheduled:string,next_expected:string,evidence:string}
     */
    private function determineKernelTiming(array $schedule, array $logData): array
    {
        $now = new \DateTimeImmutable('now', $this->appTimeZone());
        $lastRun = $logData['best']['last_run'] ?? null;
        $lastResult = (string) ($logData['best']['result'] ?? '');
        $evidence = $this->buildEvidenceLabel($logData);

        if (isset($schedule['interval_minutes'])) {
            $intervalMinutes = (int) $schedule['interval_minutes'];
            $lastDue = $now->modify('-' . $intervalMinutes . ' minutes');
            $nextDue = $now->modify('+' . $intervalMinutes . ' minutes');

            if ($this->isFailureResult($lastResult)) {
                return [
                    'is_overdue' => false,
                    'needs_attention' => true,
                    'status' => 'Failed Last Run',
                    'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
                    'next_expected' => $nextDue->format('Y-m-d g:i A'),
                    'evidence' => $evidence,
                ];
            }

            if ($lastRun === null) {
                return [
                    'is_overdue' => true,
                    'needs_attention' => true,
                    'status' => 'No Run Evidence',
                    'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
                    'next_expected' => $nextDue->format('Y-m-d g:i A'),
                    'evidence' => $evidence,
                ];
            }

            $isOverdue = $lastRun < $lastDue;

            return [
                'is_overdue' => $isOverdue,
                'needs_attention' => $isOverdue,
                'status' => $isOverdue ? 'Missed' : 'On Time',
                'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
                'next_expected' => $nextDue->format('Y-m-d g:i A'),
                'evidence' => $evidence,
            ];
        }

        if (!empty($schedule['daily_times'])) {
            $window = $this->buildWeeklyWindow([1, 2, 3, 4, 5, 6, 7], array_values($schedule['daily_times']), $now);

            return $this->determineWindowTiming(
                $window['last_due'],
                $window['next_due'],
                $logData,
                (string) ($schedule['label'] ?? 'Scheduled')
            );
        }

        return [
            'is_overdue' => false,
            'needs_attention' => false,
            'status' => $this->isFailureResult($lastResult) ? 'Failed Last Run' : 'Scheduled',
            'last_scheduled' => '',
            'next_expected' => (string) ($schedule['label'] ?? 'Scheduled'),
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array{best:array<string, mixed>|null,history:array<int, array<string, mixed>>,match_quality:string} $logData
     * @return array{is_overdue:bool,needs_attention:bool,status:string,last_scheduled:string,next_expected:string,evidence:string}
     */
    private function determineScheduleTextTiming(string $scheduleText, array $logData): array
    {
        $window = $this->buildScheduleWindow($scheduleText, new \DateTimeImmutable('now', $this->appTimeZone()));
        if ($window === null) {
            return [
                'is_overdue' => false,
                'needs_attention' => false,
                'status' => $this->isFailureResult((string) ($logData['best']['result'] ?? '')) ? 'Failed Last Run' : 'Scheduled',
                'last_scheduled' => '',
                'next_expected' => $scheduleText,
                'evidence' => $this->buildEvidenceLabel($logData),
            ];
        }

        return $this->determineWindowTiming(
            $window['last_due'],
            $window['next_due'],
            $logData,
            $scheduleText
        );
    }

    /**
     * @param array{best:array<string, mixed>|null,history:array<int, array<string, mixed>>,match_quality:string} $logData
     * @return array{is_overdue:bool,needs_attention:bool,status:string,last_scheduled:string,next_expected:string,evidence:string}
     */
    private function determineWindowTiming(
        ?\DateTimeImmutable $lastDue,
        ?\DateTimeImmutable $nextDue,
        array $logData,
        string $fallbackLabel
    ): array {
        $now = new \DateTimeImmutable('now', $this->appTimeZone());
        $nextExpected = $nextDue?->format('Y-m-d g:i A') ?? $fallbackLabel;
        $lastRun = $logData['best']['last_run'] ?? null;
        $lastResult = (string) ($logData['best']['result'] ?? '');
        $matchQuality = (string) ($logData['match_quality'] ?? 'none');
        $history = $logData['history'] ?? [];
        $evidence = $this->buildEvidenceLabel($logData);
        $bestScopes = $logData['best']['scopes'] ?? [];

        if ($lastDue === null) {
            return [
                'is_overdue' => false,
                'needs_attention' => false,
                'status' => $this->isFailureResult($lastResult) ? 'Failed Last Run' : 'Pending',
                'last_scheduled' => '',
                'next_expected' => $nextExpected,
                'evidence' => $evidence,
            ];
        }

        if ($this->isFailureResult($lastResult)) {
            return [
                'is_overdue' => false,
                'needs_attention' => true,
                'status' => 'Failed Last Run',
                'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
                'next_expected' => $nextExpected,
                'evidence' => $evidence,
            ];
        }

        if ($matchQuality === 'scope_mismatch') {
            return [
                'is_overdue' => true,
                'needs_attention' => true,
                'status' => 'Other Scope Logged',
                'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
                'next_expected' => $nextExpected,
                'evidence' => $evidence,
            ];
        }

        if (!$lastRun instanceof \DateTimeImmutable) {
            return [
                'is_overdue' => true,
                'needs_attention' => true,
                'status' => 'No Run Evidence',
                'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
                'next_expected' => $nextExpected,
                'evidence' => $evidence,
            ];
        }

        $isOverdue = $lastRun < $lastDue;
        if ($isOverdue) {
            if (($logData['best']['source'] ?? '') === 'Laravel log') {
                return [
                    'is_overdue' => false,
                    'needs_attention' => true,
                    'status' => 'Log Gap',
                    'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
                    'next_expected' => $nextExpected,
                    'evidence' => $evidence,
                ];
            }

            $observedPattern = $this->detectObservedDailyPattern($history, $bestScopes, $lastDue, $lastRun);
            if ($observedPattern !== null) {
                $observedStatus = 'On Time';
                if ($observedPattern['next_due']->format('Y-m-d') === $now->format('Y-m-d')) {
                    $observedStatus = 'Pending';
                }

                return [
                    'is_overdue' => false,
                    'needs_attention' => false,
                    'status' => $observedStatus,
                    'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
                    'next_expected' => $observedPattern['next_due']->format('Y-m-d g:i A'),
                    'evidence' => $evidence . ' (observed cadence)',
                ];
            }

            return [
                'is_overdue' => true,
                'needs_attention' => true,
                'status' => 'Missed',
                'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
                'next_expected' => $nextExpected,
                'evidence' => $evidence,
            ];
        }

        $status = 'On Time';
        if ($nextDue !== null && $nextDue->format('Y-m-d') === $now->format('Y-m-d')) {
            $status = 'Pending';
        }

        return [
            'is_overdue' => false,
            'needs_attention' => false,
            'status' => $status,
            'last_scheduled' => $lastDue->format('Y-m-d g:i A'),
            'next_expected' => $nextExpected,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array{best:array<string, mixed>|null,history:array<int, array<string, mixed>>,match_quality:string} $logData
     * @return array{is_overdue:bool,needs_attention:bool,status:string,last_scheduled:string,next_expected:string,evidence:string}
     */
    private function determineUnscheduledTiming(array $logData): array
    {
        $lastRun = $logData['best']['last_run'] ?? null;
        $lastResult = (string) ($logData['best']['result'] ?? '');
        $matchQuality = (string) ($logData['match_quality'] ?? 'none');
        $evidence = $this->buildEvidenceLabel($logData);

        if (!$lastRun instanceof \DateTimeImmutable) {
            return [
                'is_overdue' => false,
                'needs_attention' => false,
                'status' => 'Unscheduled',
                'last_scheduled' => '',
                'next_expected' => 'Not scheduled',
                'evidence' => $evidence,
            ];
        }

        if ($matchQuality === 'scope_mismatch') {
            return [
                'is_overdue' => false,
                'needs_attention' => true,
                'status' => 'Other Scope Logged',
                'last_scheduled' => '',
                'next_expected' => 'Not scheduled',
                'evidence' => $evidence,
            ];
        }

        return [
            'is_overdue' => false,
            'needs_attention' => false,
            'status' => $this->isFailureResult($lastResult) ? 'Failed Last Run' : 'Logged',
            'last_scheduled' => '',
            'next_expected' => 'Not scheduled',
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<int, string> $scopes
     */
    private function scoreScopeMatch(string $scope, array $scopes): int
    {
        $scope = strtoupper(trim($scope));
        if ($scope === '' || $scope === 'ALL' || $scope === 'N/A') {
            return 1;
        }

        $scopes = array_values(array_unique(array_filter(array_map(static fn(string $value): string => strtoupper(trim($value)), $scopes))));
        if ($scopes === []) {
            return 1;
        }

        if (in_array($scope, $scopes, true)) {
            return 3;
        }

        if (in_array('ALL', $scopes, true)) {
            return 2;
        }

        if ($scope === 'LDR' && in_array('LDR', $scopes, true)) {
            return 3;
        }

        if ($scope === 'PLAW' && in_array('PLAW', $scopes, true)) {
            return 3;
        }

        return 0;
    }

    /**
     * @return array<int, string>
     */
    private function extractScopes(string $description, string $result): array
    {
        $haystack = strtoupper(trim($description . ' ' . $result));
        if ($haystack === '') {
            return [];
        }

        $scopes = [];

        if (preg_match('/COMPANY=([A-Z,\s]+)/', $haystack, $matches)) {
            $parts = preg_split('/\s*,\s*/', trim((string) ($matches[1] ?? ''))) ?: [];
            foreach ($parts as $part) {
                $normalized = strtoupper(trim($part));
                if (in_array($normalized, ['ALL', 'LDR', 'PLAW'], true)) {
                    $scopes[] = $normalized;
                }
            }
        }

        if (str_contains($haystack, 'DP_LDR') || preg_match('/\bLDR\b/', $haystack)) {
            $scopes[] = 'LDR';
        }

        if (str_contains($haystack, 'DP_PLAW') || preg_match('/\bPLAW\b/', $haystack)) {
            $scopes[] = 'PLAW';
        }

        if (preg_match('/\bALL\b/', $haystack)) {
            $scopes[] = 'ALL';
        }

        return array_values(array_unique($scopes));
    }

    private function parseSqlServerTimestamp(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, $this->appTimeZone());
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseLaravelLogTimestamp(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, $this->appTimeZone());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{best:array<string, mixed>|null,history:array<int, array<string, mixed>>,match_quality:string} $logData
     */
    private function buildEvidenceLabel(array $logData): string
    {
        $best = $logData['best'] ?? null;
        if (!is_array($best)) {
            return 'No execution log';
        }

        $source = (string) ($best['source'] ?? 'TblLog');
        if (($logData['match_quality'] ?? '') === 'scope_mismatch') {
            return $source . ' (other scope)';
        }

        return $source;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<int, string> $preferredScopes
     * @return array{last_due:\DateTimeImmutable,next_due:\DateTimeImmutable}|null
     */
    private function detectObservedDailyPattern(array $history, array $preferredScopes, \DateTimeImmutable $configuredLastDue, \DateTimeImmutable $lastRun): ?array
    {
        $successfulRuns = [];
        $preferredScopes = array_values(array_unique(array_filter(array_map(static fn(string $value): string => strtoupper(trim($value)), $preferredScopes))));

        foreach ($history as $entry) {
            $run = $entry['last_run'] ?? null;
            if (!$run instanceof \DateTimeImmutable) {
                continue;
            }

            if ($this->isFailureResult((string) ($entry['result'] ?? ''))) {
                continue;
            }

            if (($entry['source'] ?? '') !== 'TblLog') {
                continue;
            }

            $entryScopes = array_values(array_unique(array_filter(array_map(static fn(string $value): string => strtoupper(trim($value)), $entry['scopes'] ?? []))));
            if ($preferredScopes !== [] && $entryScopes !== [] && array_intersect($preferredScopes, $entryScopes) === []) {
                continue;
            }

            $successfulRuns[] = $run;
            if (count($successfulRuns) === 5) {
                break;
            }
        }

        if (count($successfulRuns) < 3) {
            return null;
        }

        $runMinutes = [];
        for ($index = 0; $index < count($successfulRuns) - 1; $index++) {
            $hours = abs($successfulRuns[$index]->getTimestamp() - $successfulRuns[$index + 1]->getTimestamp()) / 3600;
            if ($hours < 18 || $hours > 30) {
                return null;
            }

            $runMinutes[] = ((int) $successfulRuns[$index]->format('H') * 60) + (int) $successfulRuns[$index]->format('i');
        }

        $runMinutes[] = ((int) end($successfulRuns)->format('H') * 60) + (int) end($successfulRuns)->format('i');
        sort($runMinutes);

        if (($runMinutes[array_key_last($runMinutes)] - $runMinutes[0]) > 120) {
            return null;
        }

        $medianMinute = $runMinutes[(int) floor(count($runMinutes) / 2)];
        $observedHour = intdiv($medianMinute, 60);
        $observedMinute = $medianMinute % 60;

        $now = new \DateTimeImmutable('now', $this->appTimeZone());
        $observedToday = $now->setTime($observedHour, $observedMinute);
        $nextDue = $observedToday > $now ? $observedToday : $observedToday->modify('+1 day');
        $lastDue = $nextDue->modify('-1 day');

        if (abs($configuredLastDue->getTimestamp() - $lastDue->getTimestamp()) < 1 * 3600) {
            return null;
        }

        if ($lastRun < $lastDue->modify('-6 hours')) {
            return null;
        }

        return [
            'last_due' => $lastDue,
            'next_due' => $nextDue,
        ];
    }

    private function appTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone((string) (config('app.timezone') ?: 'UTC'));
    }

    /**
     * @return array{last_due:\DateTimeImmutable|null, next_due:\DateTimeImmutable|null}|null
     */
    private function buildScheduleWindow(string $scheduleText, \DateTimeImmutable $now): ?array
    {
        $scheduleText = trim($scheduleText);

        if (preg_match('/^Daily(?: at)?\s+(.+)$/i', $scheduleText, $matches)) {
            return $this->buildWeeklyWindow([1, 2, 3, 4, 5, 6, 7], $this->parseTimeList($matches[1]), $now);
        }

        if (preg_match('/^Monthly\s+(.+?)\s+([0-9:,APM\s]+)$/i', $scheduleText, $matches)) {
            return $this->buildMonthlyWindow($this->parseMonthlyDays($matches[1]), $this->parseTimeList($matches[2]), $now);
        }

        if (preg_match('/^([A-Za-z,\-\s]+)\s+([0-9:,APM\s]+)$/i', $scheduleText, $matches)) {
            return $this->buildWeeklyWindow($this->parseWeekdays($matches[1]), $this->parseTimeList($matches[2]), $now);
        }

        return null;
    }

    /**
     * @param array<int, int> $weekdays
     * @param array<int, string> $times
     * @return array{last_due:\DateTimeImmutable|null, next_due:\DateTimeImmutable|null}
     */
    private function buildWeeklyWindow(array $weekdays, array $times, \DateTimeImmutable $now): array
    {
        if (empty($weekdays) || empty($times)) {
            return ['last_due' => null, 'next_due' => null];
        }

        $occurrences = [];
        for ($offset = -7; $offset <= 7; $offset++) {
            $date = $now->modify(($offset >= 0 ? '+' : '') . $offset . ' day');
            if (!in_array((int) $date->format('N'), $weekdays, true)) {
                continue;
            }

            foreach ($times as $time) {
                $occurrences[] = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $time, $this->appTimeZone());
            }
        }

        usort($occurrences, fn(\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);

        $lastDue = null;
        $nextDue = null;
        foreach ($occurrences as $occurrence) {
            if ($occurrence <= $now) {
                $lastDue = $occurrence;
            } elseif ($nextDue === null) {
                $nextDue = $occurrence;
            }
        }

        return ['last_due' => $lastDue, 'next_due' => $nextDue];
    }

    /**
     * @param array<int, int> $days
     * @param array<int, string> $times
     * @return array{last_due:\DateTimeImmutable|null, next_due:\DateTimeImmutable|null}
     */
    private function buildMonthlyWindow(array $days, array $times, \DateTimeImmutable $now): array
    {
        if (empty($days) || empty($times)) {
            return ['last_due' => null, 'next_due' => null];
        }

        $occurrences = [];
        for ($offset = -1; $offset <= 1; $offset++) {
            $month = $now->modify(($offset >= 0 ? '+' : '') . $offset . ' month');
            $year = (int) $month->format('Y');
            $monthNumber = (int) $month->format('m');
            $maxDay = cal_days_in_month(CAL_GREGORIAN, $monthNumber, $year);

            foreach ($days as $day) {
                if ($day > $maxDay) {
                    continue;
                }

                $date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $monthNumber, $day), $this->appTimeZone());
                foreach ($times as $time) {
                    $occurrences[] = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $time, $this->appTimeZone());
                }
            }
        }

        usort($occurrences, fn(\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);

        $lastDue = null;
        $nextDue = null;
        foreach ($occurrences as $occurrence) {
            if ($occurrence <= $now) {
                $lastDue = $occurrence;
            } elseif ($nextDue === null) {
                $nextDue = $occurrence;
            }
        }

        return ['last_due' => $lastDue, 'next_due' => $nextDue];
    }

    /**
     * @return array<int, string>
     */
    private function parseTimeList(string $raw): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $times = [];

        foreach ($parts as $part) {
            $time = strtoupper(trim(preg_replace('/\s+/', ' ', $part) ?? $part));
            if ($time === '') {
                continue;
            }

            if (preg_match('/^\d{1,2}[AP]M$/', $time)) {
                $time = substr($time, 0, -2) . ':00 ' . substr($time, -2);
            } elseif (preg_match('/^\d{1,2}:\d{2}[AP]M$/', $time)) {
                $time = substr($time, 0, -2) . ' ' . substr($time, -2);
            }

            $times[] = $time;
        }

        return array_values(array_unique($times));
    }

    /**
     * @return array<int, int>
     */
    private function parseWeekdays(string $raw): array
    {
        $raw = trim($raw);
        if (str_contains($raw, '-')) {
            [$start, $end] = array_map('trim', explode('-', $raw, 2));
            $startNumber = $this->mapWeekday($start);
            $endNumber = $this->mapWeekday($end);
            if ($startNumber !== null && $endNumber !== null && $startNumber <= $endNumber) {
                return range($startNumber, $endNumber);
            }
        }

        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $weekdays = [];
        foreach ($parts as $part) {
            $weekday = $this->mapWeekday($part);
            if ($weekday !== null) {
                $weekdays[] = $weekday;
            }
        }

        return array_values(array_unique($weekdays));
    }

    /**
     * @return array<int, int>
     */
    private function parseMonthlyDays(string $raw): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $days = [];
        foreach ($parts as $part) {
            if (preg_match('/(\d{1,2})/', $part, $matches)) {
                $days[] = (int) $matches[1];
            }
        }

        return array_values(array_unique(array_filter($days, fn(int $day): bool => $day >= 1 && $day <= 31)));
    }

    private function mapWeekday(string $value): ?int
    {
        return match (strtolower(trim($value))) {
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            'sunday' => 7,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function loadPackageCommandFiles(): array
    {
        $path = dirname(__DIR__);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($fileInfo->getPathname());
            if (str_contains($contents, 'extends Command')) {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }

    private function extractQuotedProperty(string $contents, string $property): ?string
    {
        $pattern = '/protected\\s+\\$' . preg_quote($property, '/') . "\\s*=\\s*'([^']*)'/s";
        if (preg_match($pattern, $contents, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function fallbackSignature(string $shortName): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', ':$0', $shortName) ?? $shortName);
    }

    /**
     * @return array<int, string>
     */
    private function buildCandidates(string $shortName, string $name): array
    {
        $withoutGenerate = preg_replace('/^Generate/', '', $shortName) ?? $shortName;
        $withoutReport = preg_replace('/Report$/', '', $withoutGenerate) ?? $withoutGenerate;
        $withoutCommand = preg_replace('/Command$/', '', $withoutReport) ?? $withoutReport;
        $humanizedBase = $this->humanizeName($withoutReport);

        $candidates = [
            $shortName,
            $withoutGenerate,
            $withoutReport,
            $withoutCommand,
            $name,
            str_replace(' ', '', $name),
            $humanizedBase,
            str_replace(' ', '', $humanizedBase),
        ];

        if ($shortName === 'UpdateLendingUSAStatuses') {
            $candidates[] = 'LendingUSAStatusReport';
            $candidates[] = 'LendingUSA Status Report';
            $candidates[] = 'LendingUSAStatusUpdateReport';
        }

        if ($shortName === 'GenerateLookbackSummaryReport') {
            $candidates[] = 'Lookback Summary';
        }

        if ($shortName === 'GenerateEnrollmentSummaryReport') {
            $candidates[] = 'EnrollmentSummary';
            $candidates[] = 'Enrollment Summary';
        }

        if ($shortName === 'GenerateDroppedReport') {
            $candidates[] = 'DroppedReport';
            $candidates[] = 'Dropped Report';
        }

        if ($shortName === 'GenerateReportSummary') {
            $candidates[] = 'ReportSummary';
            $candidates[] = 'Report Summary';
        }

        if ($shortName === 'GenerateSyncSummary') {
            $candidates[] = 'SyncSummary';
            $candidates[] = 'Sync Summary';
        }

        if ($shortName === 'ProcessProgramCompletions') {
            $candidates[] = 'ProgramCompletionsReport';
            $candidates[] = 'Program Completions Report';
        }

        if ($shortName === 'SyncDebtAccounts') {
            $candidates[] = 'UpdateDebtAccounts';
        }

        if ($shortName === 'SyncSettledDebtsData') {
            $candidates[] = 'SyncSettledDebts';
            $candidates[] = 'Sync Settled Debts';
        }

        if ($shortName === 'SyncEnrollmentData') {
            $candidates[] = 'SyncEnrollmentDataTemp';
            $candidates[] = 'Sync Enrollment Data Temp';
            $candidates[] = 'Enrollment Data Temp';
            $candidates[] = 'SYNC_ENROLLMENT_DATA';
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function humanizeName(string $value): string
    {
        $name = trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $value) ?? $value);

        return str_replace(
            ['E P F', 'U S A', 'N S F', 'P O A', 'L D R', 'P L A W', 'C C S'],
            ['EPF', 'USA', 'NSF', 'POA', 'LDR', 'PLAW', 'CCS'],
            $name
        );
    }

    private function normalizeName(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? $value);
    }

    private function formatDateTime(?\DateTimeImmutable $value): string
    {
        return $value?->format('Y-m-d H:i:s') ?? '';
    }

    private function isFailureResult(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return $normalized !== ''
            && (str_contains($normalized, 'fail') || str_contains($normalized, 'error'))
            && !str_contains($normalized, 'success');
    }
}
