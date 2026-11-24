<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

/** Employee-only explanation of immutable prices. No source queries, tier lookup or allocation from totals. */
final class CustomerServiceCalculationSnapshot
{
    private const MAX_BATCH_SELECTIONS = 8;
    private const MAX_BATCH_BYTES = 14 * 1024 * 1024;

    /** Select an employee from independently captured components, without requiring an unrelated complete CS bundle. */
    public static function forArchivedAgent(array $artifacts, string $period, string $report, string $agentKey, string $sourceScope, bool $preferStructured = true): array
    {
        self::validateArchivedSelection($period, $report, $agentKey, $sourceScope);
        $family = match ($report) { 'retention_lookback' => 'lookback', 'retention_regular' => 'retention', default => 'nsf' };
        $lookbackRun = $preferStructured && $family === 'lookback' ? self::lookbackRun($artifacts) : null;
        return self::selectArchivedAgent($artifacts, $period, $report, $family, $agentKey, $sourceScope, $lookbackRun);
    }

    /** Replay the employee's Lookback evidence using only trusted archive metadata. */
    public static function forStructuredLookbackAgent(array $artifacts, string $period, string $runId, string $agentKey, string $sourceScope): array
    {
        self::validateArchivedSelection($period, 'retention_lookback', $agentKey, $sourceScope);
        if (!preg_match('/^[a-f0-9]{32}$/D', $runId)) self::invalid();

        return self::selectArchivedAgent($artifacts, $period, 'retention_lookback', 'lookback', $agentKey, $sourceScope, $runId);
    }

    public static function validateArchivedSelection(string $period, string $report, string $agentKey, string $sourceScope): void
    {
        if (!in_array($report, ['nsf', 'retention_lookback', 'retention_regular'], true)
            || !in_array($sourceScope, ['ldr', 'plaw', 'both'], true)
            || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])-01$/D', $period)
            || !mb_check_encoding($agentKey, 'UTF-8') || mb_strlen($agentKey, 'UTF-8') < 1 || mb_strlen($agentKey, 'UTF-8') > 200
            || preg_match('/[\x00-\x1f\x7f]/u', $agentKey) || self::key($agentKey) !== $agentKey) {
            throw new \InvalidArgumentException('Archived calculation requires an exact normalized employee, report, company and month.');
        }
    }

    private static function selectArchivedAgent(array $artifacts, string $period, string $report, string $family, string $agentKey, string $sourceScope, ?string $structuredRunId = null): array
    {
        if (count($artifacts) !== 2 || array_diff([$family . '_ldr', $family . '_plaw'], array_keys($artifacts))) self::invalid();
        $regularLines = $family === 'retention' ? self::regularPayrollLines($artifacts, $period, [$agentKey]) : [];
        $requiresWorkbook = $structuredRunId === null && $regularLines === [];
        // Validate both sources even for a one-company request.
        $validated = [];
        $structuredLines = $structuredRunId === null ? [] : self::verifiedLookbackLines($artifacts, $period, $structuredRunId);
        foreach (['ldr', 'plaw'] as $source) {
            $key = $family . '_' . $source;
            $artifact = $artifacts[$key];
            if ($requiresWorkbook) self::validateArchivedArtifact($artifact, $key, $period);
            if ($report === 'retention_regular' && isset($artifact['context']['legacyEvidence'])) {
                $evidence = $artifact['context']['legacyEvidence'];
                if (($evidence['source'] ?? null) !== $source || ($evidence['period'] ?? null) !== $period
                    || ($evidence['workbookSha256'] ?? null) !== $artifact['sha256']
                    || ($evidence['historicalLinePricing'] ?? null) !== 'unverified' || ($evidence['eligibleForPayment'] ?? null) !== false
                    || array_key_exists('commissionResults', $artifact['context'])) self::invalid();
                $parsed = LegacyRetentionWorkbook::read($artifact['path'], $source, $artifact['sha256']);
                if ($parsed['entries'] !== ($evidence['summaryEntries'] ?? null)) self::invalid();
                $matches = array_values(array_filter($parsed['entries'], static fn ($row) => self::archivedAgentKey(self::key($row['agent'])) === self::archivedAgentKey($agentKey)));
                if (count($matches) > 1) self::invalid();
                $amount = $matches ? $matches[0]['commission_cents'] / 100 : null;
                $validated[$source] = [$artifact, $amount, true, $matches ? self::key($matches[0]['agent']) : $agentKey];
            } else {
                $context = $artifact['context']['commissionResults'] ?? null;
                $kind = $family === 'nsf' ? 'nsf' : 'retention';
                $component = $family === 'lookback' ? 'bonusCommission' : 'commission';
                if (!is_array($context) || ($context['reportKind'] ?? null) !== $kind || ($context['source'] ?? null) !== $source
                    || ($context['period'] ?? null) !== $period || ($context['component'] ?? null) !== $component
                    || ($context['complete'] ?? null) !== true || !is_array($context['rows'] ?? null)
                    || !array_is_list($context['rows']) || ($family === 'nsf' && ($context['coverageBasis'] ?? null) !== PayrollCommissionResultSnapshot::NSF_ALL_PRICED_AGENTS)) self::invalid();
                $checked = PayrollCommissionResultSnapshot::component($kind, $source, $period, $component, $context['rows'], $context['coverageBasis'] ?? null);
                if (json_encode($checked, JSON_THROW_ON_ERROR) !== json_encode($context, JSON_THROW_ON_ERROR)) self::invalid();
                $matches = array_values(array_filter($context['rows'], static fn ($row) => self::archivedAgentKey($row['agentKey']) === self::archivedAgentKey($agentKey)));
                if (count($matches) > 1) self::invalid();
                $validated[$source] = [$artifact, $matches ? (float) $matches[0]['amount'] : null, false, $matches ? $matches[0]['agentKey'] : $agentKey];
            }
        }
        $components = [];
        foreach ($sourceScope === 'both' ? ['ldr', 'plaw'] : [$sourceScope] as $source) {
            [$artifact, $amount, $legacy, $sourceAgentKey] = $validated[$source];
            if ($amount === null) {
                if ($sourceScope !== 'both') throw new \RuntimeException('The pinned source has no exact captured employee identity.');
                continue;
            }
            $entry = ['reportKey' => $family . '_' . $source, 'source' => $source,
                'sourceAgentKey' => $sourceAgentKey,
                'artifactSha256' => $artifact['sha256'], 'contextSha256' => $artifact['contextSha256'],
                'component' => $family === 'lookback' ? 'bonusCommission' : 'commission', 'amount' => $amount,
                'status' => 'verified', 'lines' => []];
            if ($legacy) {
                $entry = self::unavailable($entry, 'The original workbook preserves account data and the saved total, but not verified historical selected line prices.');
                $entry['originalRows'] = self::legacyOriginalRows($artifact, $period, $sourceAgentKey);
            } elseif ($family === 'retention' && isset($artifact['context']['regularPricing'])) {
                $entry['lines'] = isset($regularLines[$source])
                    ? self::structuredAgentLines($regularLines[$source], $sourceAgentKey) : self::regular($artifact, $period, $sourceAgentKey);
            } elseif ($amount != 0.0) {
                $lines = match (true) {
                    $structuredRunId !== null => $structuredLines[$source][$sourceAgentKey],
                    $family === 'nsf' => self::nsf($artifact, $sourceAgentKey, $amount),
                    $family === 'lookback' => self::lookback($artifact, $sourceAgentKey),
                    default => null,
                };
                if ($lines === null) $entry = self::unavailable($entry, 'The original workbook does not retain trustworthy selected line prices for this component.');
                else $entry['lines'] = $lines;
            }
            if (count($entry['lines']) > 20000) self::invalid();
            if ($entry['status'] === 'verified' && round(array_sum(array_column($entry['lines'], 'amount')), 2) !== round((float) $amount, 2)) self::invalid();
            if ($requiresWorkbook && (is_link($artifact['path']) || !hash_equals($artifact['sha256'], (string) hash_file('sha256', $artifact['path'])))) self::invalid();
            $components[] = $entry;
        }
        return ['kind' => 'cs_archived_agent_calculation_v1', 'version' => 1, 'period' => $period,
            'report' => $report, 'agentKey' => $agentKey, 'sourceScope' => $sourceScope, 'components' => $components];
    }

    /** Existing explicitly allowlisted spellings only; never fuzzy identity or mailbox matching. */
    private static function archivedAgentKey(string $key): string
    {
        return ['aleph bolanos' => 'aleph bolaños', 'andrea mendoze' => 'andrea mendoza', 'andrea galves' => 'andrea galvez'][$key] ?? $key;
    }

    private static function validateArchivedArtifact(mixed $artifact, string $key, string $period, bool $requireWorkbook = true): void
    {
        if (!is_array($artifact) || ($artifact['key'] ?? null) !== $key || ($artifact['period'] ?? $period) !== $period
            || !is_array($artifact['context'] ?? null)
            || !is_string($artifact['contextSha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $artifact['contextSha256'])
            || hash('sha256', json_encode($artifact['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== $artifact['contextSha256']) self::invalid();
        if ($requireWorkbook) {
            if (!is_string($artifact['path'] ?? null)) self::invalid();
            self::validateWorkbookFile($artifact['path'], $artifact['sha256'] ?? null, $artifact['byteLength'] ?? null);
        } elseif (!is_string($artifact['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'])) {
            self::invalid();
        }
    }

    private static function validateWorkbookFile(string $path, mixed $sha256, mixed $byteLength = null): void
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) || is_link($path) || !is_file($path) || !is_readable($path)
            || !is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) self::invalid();
        clearstatcache(true, $path);
        if (filesize($path) < 4 || filesize($path) > 32 * 1024 * 1024
            || $byteLength !== null && $byteLength !== filesize($path)
            || !hash_equals($sha256, (string) hash_file('sha256', $path))) self::invalid();
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) self::invalid();
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) { $entry = $zip->statIndex($i); if (!is_array($entry)) self::invalid(); $size += (int) ($entry['size'] ?? 0); }
            if ($size > 128 * 1024 * 1024 || $zip->locateName('xl/workbook.xml') === false || $zip->locateName('[Content_Types].xml') === false) self::invalid();
        } finally { $zip->close(); }
    }

    /** Read a workbook selected from a trusted historical record, without creating a capture or claiming roster coverage. */
    public static function forPinnedNsfWorkbook(string $path, string $sha256, string $period, string $source, string $agent): array
    {
        $agentKey = self::key($agent);
        self::validateArchivedSelection($period, 'nsf', $agentKey, $source);
        if ($source === 'both' || $agentKey === 'unassigned agents') self::invalid();
        self::validateWorkbookFile($path, $sha256);
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly(['Commission', 'Data', 'Agent Summary', 'NSF Data', 'Run Status']);
        $book = $reader->load($path);
        try {
            $hasRunMetadata = self::checkPinnedNsfScope($book, $period, $source);
            $old = $book->getSheetByName('Commission');
            $modern = $book->getSheetByName('Agent Summary');
            if (($old === null) === ($modern === null)) self::invalid();
            $summary = $modern ?? $old;
            $data = $book->getSheetByName($modern ? 'NSF Data' : 'Data');
            if ($book->getSheetByName($modern ? 'Data' : 'NSF Data')) self::invalid();
            $columns = self::headers($summary);
            // The original PHP formatter called its NSF employee column "NGO".
            if (!isset($columns['commission']) || isset($columns['ngo']) === isset($columns['nsf agent'])) self::invalid();
            $nameColumn = $columns['nsf agent'] ?? $columns['ngo'];
            $row = null;
            $sourceName = null;
            $lastRow = $summary->getHighestDataRow();
            if ($lastRow > 10000 || ($data && $data->getHighestDataRow() > 100000)) self::invalid();
            $headers = $data ? self::headers($data) : [];
            if (isset($headers['nsf returned date'])) {
                $lastContact = $data->getHighestDataRow();
                for ($r = 2; $r <= $lastContact; $r++) {
                    $returned = self::value($data, $headers['nsf returned date'], $r);
                    if (!self::missingOriginalValue($returned) && substr(self::dateValue($returned), 0, 7) !== substr($period, 0, 7)) self::invalid();
                }
            }
            for ($r = 2; $r <= $lastRow; $r++) {
                $rawName = self::value($summary, $nameColumn, $r);
                if (is_string($rawName) && str_starts_with($rawName, '=')) self::invalid();
                $name = self::key((string) $rawName);
                if (self::archivedAgentKey($name) !== self::archivedAgentKey($agentKey)) continue;
                if ($row !== null) self::invalid();
                $row = $r;
                $sourceName = (string) $rawName;
            }
            $amountCents = $row === null ? null : self::nsfCents(self::value($summary, $columns['commission'], $row));
            $status = $row === null ? 'missing' : ($amountCents === 0 ? 'verified' : 'total_only');
            $lines = [];
            if ($amountCents !== null) {
                // The "NGO" layout priced exact raw strings. The later normalized calculator added run metadata.
                $normalized = isset($columns['ngo']) ? false : ($hasRunMetadata ? true : null);
                $detail = self::pinnedNsfLines($summary, $data, $columns, $headers, $row, $sourceName, $normalized, $amountCents);
                if ($detail !== null) {
                    $lines = $detail;
                    $status = 'verified';
                }
            }
            if (is_link($path) || !hash_equals($sha256, (string) hash_file('sha256', $path))) self::invalid();
            return ['kind' => 'nsf_employee_statement_v1', 'period' => $period, 'source' => $source,
                'agentKey' => $agentKey, 'workbookSha256' => $sha256, 'status' => $status, 'amountCents' => $amountCents, 'lines' => $lines];
        } finally { $book->disconnectWorksheets(); }
    }

    private static function pinnedNsfLines(Worksheet $summary, ?Worksheet $data, array $columns, array $headers, int $row, string $sourceName, ?bool $normalized, int $amountCents): ?array
    {
        $rate = self::savedNsfRate($summary, $columns, $row);
        $rateCents = $rate === null ? null : self::nsfCents($rate);
        $saved = [];
        foreach (['clears', 'assignments', 'actions', 'ratio'] as $field) {
            $value = isset($columns[$field]) ? self::value($summary, $columns[$field], $row) : null;
            $saved[$field] = self::missingOriginalValue($value) ? null : self::number($value);
            $number = $saved[$field];
            if ($number !== null && ($number < 0 || ($field === 'ratio' ? $number > 1 : floor($number) !== $number))) self::invalid();
        }
        $clears = $saved['clears'];
        // Check independent saved facts even when the workbook cannot explain every account.
        $assignments = $saved['assignments'];
        $actions = $saved['actions'];
        if ($assignments !== null && (($actions !== null && $actions > $assignments)
            || ($clears !== null && $clears > $assignments)
            || ($actions !== null && $saved['ratio'] !== null
                && round($actions / max(1, $assignments), 4) !== round($saved['ratio'], 4)))) self::invalid();
        if (($amountCents > 0 && ($clears === 0.0 || $rateCents === 0))
            || ($rateCents !== null && $clears !== null && $rateCents * $clears !== (float) $amountCents)) self::invalid();
        if (!$data || !isset($headers['agent'])) return null;
        $exactRows = $normalizedRows = [];
        $sourceKey = self::nsfProducerKey($sourceName);
        $last = $data->getHighestDataRow();
        for ($r = 2; $r <= $last; $r++) {
            $name = (string) self::value($data, $headers['agent'], $r);
            if ($name === $sourceName) $exactRows[] = $r;
            if (self::nsfProducerKey($name) === $sourceKey) $normalizedRows[] = $r;
        }
        $groups = match ($normalized) {
            true => [$normalizedRows],
            false => [$exactRows],
            null => $exactRows === $normalizedRows ? [$exactRows] : [$exactRows, $normalizedRows],
        };
        if ($groups === [[]]) {
            if ($assignments > 0.0 || $actions > 0.0 || $clears > 0.0 || $saved['ratio'] > 0.0) self::invalid();
            return null;
        }
        $candidates = [];
        foreach ($groups as $rows) {
            if ($assignments !== null && count($rows) !== (int) $assignments) continue;
            $facts = self::nsfContactFacts($data, $headers, $rows, true);
            $detail = self::matchPinnedNsfEvidence($facts, $headers, $saved, $rateCents, $amountCents);
            // Original NGO workbooks could contain the same contact twice. Their literal total is still readable.
            if ($detail !== false && $facts['duplicateIds']) $detail = $normalized === false ? null : false;
            if ($detail !== false) $candidates[] = $detail;
        }
        if (!$candidates) self::invalid();
        // In an unmarked transition-era workbook both grouping rules can fit incomplete evidence.
        if (count($candidates) === 2 && $candidates[0] !== $candidates[1]) return null;
        return $candidates[0];
    }

    private static function nsfProducerKey(string $name): string
    {
        // Exact normalization of GenerateNSFCommissionReport::buildCommissionRows from 2686bdd onward.
        return strtolower((string) preg_replace('/\s+/', ' ', trim($name)));
    }

    /** False means this grouping contradicts the summary; null preserves the recorded total without lines. */
    private static function matchPinnedNsfEvidence(array $facts, array $headers, array $saved, ?int $rateCents, int $amountCents): array|false|null
    {
        $clears = $saved['clears'];
        $eligible = count($facts['eligible']);
        $possible = $eligible + $facts['unknownFlags'];
        $denominator = max(1, $facts['assignments']);
        $minRatio = round($facts['actions'] / $denominator, 4);
        $maxRatio = round(($facts['actions'] + $facts['unknownActions']) / $denominator, 4);
        if (($amountCents > 0 && $possible === 0)
            || ($clears !== null && ($eligible > $clears || $possible < $clears))
            || ($rateCents !== null && ($eligible * $rateCents > $amountCents || $possible * $rateCents < $amountCents))
            || ($saved['assignments'] !== null && $saved['assignments'] !== (float) $facts['assignments'])
            || ($saved['actions'] !== null && ($facts['actions'] > $saved['actions'] || $facts['actions'] + $facts['unknownActions'] < $saved['actions']))
            || ($saved['ratio'] !== null && (round($saved['ratio'], 4) < $minRatio || round($saved['ratio'], 4) > $maxRatio))) return false;
        if ($amountCents === 0) return [];
        if ($clears === null || $rateCents === null || $facts['unknownFlags'] || $facts['incompleteDetail'] || !isset($headers['id'])) return null;
        if ($eligible > 20000) self::invalid();
        return array_map(static fn (array $line): array => ['clientId' => $line['clientId'], 'rateCents' => $rateCents, 'amountCents' => $rateCents]
            + array_intersect_key($line, ['paymentDate' => true]), $facts['eligible']);
    }

    /** Old files can lack scope metadata. When it exists it must agree with the supplied archive record. */
    private static function checkPinnedNsfScope(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $period, string $source): bool
    {
        $properties = $book->getProperties();
        $timing = NsfReportReadiness::timing($period);
        foreach (['NSF Payment Cutoff (Pacific)' => 'cutoffPacific', 'NSF Final Runs After (UTC)' => 'finalAfter'] as $field => $key) {
            $saved = $properties->getCustomPropertyValue($field);
            if ($saved !== null && $saved !== $timing[$key]) self::invalid();
        }
        $sheet = $book->getSheetByName('Run Status');
        $hasCutoff = $properties->getCustomPropertyValue('NSF Final Runs After (UTC)') !== null;
        if (!$sheet) return $hasCutoff;
        $lastRow = $sheet->getHighestDataRow();
        if ($lastRow > 1000) self::invalid();
        $brands = $source === 'ldr' ? ['ldr', 'liberty debt relief'] : ['plaw', 'progress law'];
        $seen = [];
        for ($row = 1; $row <= $lastRow; $row++) {
            $key = self::key((string) self::value($sheet, 1, $row));
            if (!in_array($key, ['source', 'period start', 'period end', 'payment cutoff (pacific)', 'final runs may start after (utc)'], true)) continue;
            if (isset($seen[$key])) self::invalid();
            $seen[$key] = true;
            $value = self::value($sheet, 2, $row);
            if ($key === 'source') {
                if (!in_array(self::key((string) $value), $brands, true)) self::invalid();
            } elseif ($key === 'payment cutoff (pacific)' || $key === 'final runs may start after (utc)') {
                if ($value !== $timing[$key === 'payment cutoff (pacific)' ? 'cutoffPacific' : 'finalAfter']) self::invalid();
            } elseif (self::dateValue($value) !== ($key === 'period start' ? $period : (new \DateTimeImmutable($period))->format('Y-m-t'))) self::invalid();
        }
        return $hasCutoff || isset($seen['source'], $seen['period start']);
    }

    private static function nsfCents(mixed $value): int
    {
        $number = self::number($value);
        if ($number < 0 || $number > 90071992547409 || round($number, 2) !== $number) self::invalid();
        return (int) round($number * 100);
    }

    /** Unpriced original facts are deliberately separate from selected payable calculation lines. */
    private static function legacyOriginalRows(array $artifact, string $period, string $agentKey): array
    {
        $book = self::book($artifact);
        try {
            $sheet = $book->getSheetByName('Retention Data');
            if (!$sheet) self::invalid();
            $lastRow = $sheet->getHighestDataRow();
            if ($lastRow > 100000) self::invalid();
            $headers = self::headers($sheet);
            foreach (['id', 'retention agent', 'retention date', 'retention payment date', 't1 rate', 't2 rate', 't3 rate'] as $required) if (!isset($headers[$required])) self::invalid();
            $rows = [];
            for ($r = 2; $r <= $lastRow; $r++) {
                $name = self::value($sheet, $headers['retention agent'], $r);
                if (!is_string($name) || self::key($name) !== $agentKey) continue;
                $row = ['detailRow' => $r, 'clientId' => self::id(self::value($sheet, $headers['id'], $r))];
                foreach (['retention date' => 'retentionDate', 'retention payment date' => 'paymentDate'] as $header => $field) {
                    $value = self::dateValue(self::value($sheet, $headers[$header], $r));
                    if ($value !== null) $row[$field] = $value;
                }
                // These are only candidate earnings for this saved report, not
                // verified selected prices. Never mix historical assignments
                // or unretained accounts into the reporting-month detail.
                if (!isset($row['retentionDate'], $row['paymentDate']) || substr($row['paymentDate'], 0, 7) !== substr($period, 0, 7)) continue;
                $rates = [null, null, null, null];
                foreach ([1, 2, 3, 4] as $tier) if (isset($headers['t' . $tier . ' rate'])) {
                    $value = self::value($sheet, $headers['t' . $tier . ' rate'], $r);
                    if ($value === null || $value === '') continue;
                    $rate = self::number($value);
                    if ($rate < 0) self::invalid();
                    $rates[$tier - 1] = $rate;
                }
                $row['rates'] = $rates;
                $rows[] = $row;
                if (count($rows) > 20000) self::invalid();
            }
            return $rows;
        } finally { $book->disconnectWorksheets(); }
    }

    public static function forAgent(array $artifacts, string $period, string $reportKind, string $agentKey, string $sourceScope, bool $preferStructuredLookback = true): array
    {
        self::validateBundleSelection($period, $reportKind, $agentKey, $sourceScope);
        $sources = self::sources($artifacts, $period);
        $structured = $reportKind === 'retention' ? self::bundleStructuredLines($sources, $period, [$agentKey], $preferStructuredLookback) : [];
        $calculation = self::bundleCalculation($sources, $period, $reportKind, $agentKey, $sourceScope);
        foreach ($calculation['components'] as $index => $component) {
            $key = $component['reportKey'];
            $lines = isset($structured[$key]) ? self::structuredAgentLines($structured[$key], $agentKey) : null;
            $calculation['components'][$index] = self::bundleComponent($sources[$key], $component, $period, $agentKey, structuredLines: $lines);
        }
        return $calculation;
    }

    /** Share verification and workbook loading across this batch only. */
    public static function forAgents(array $artifacts, string $period, array $selections): array
    {
        if (!array_is_list($selections) || count($selections) < 1 || count($selections) > self::MAX_BATCH_SELECTIONS) {
            throw new \InvalidArgumentException('Calculations require one to eight ordered employee selections.');
        }
        foreach ($selections as $selection) {
            if (!is_array($selection) || count($selection) !== 3 || !is_string($selection['reportKind'] ?? null)
                || !is_string($selection['agentKey'] ?? null) || !is_string($selection['sourceScope'] ?? null)
                || !mb_check_encoding($selection['agentKey'], 'UTF-8') || mb_strlen($selection['agentKey'], 'UTF-8') > 200
                || preg_match('/[\x00-\x1f\x7f]/u', $selection['agentKey'])) {
                throw new \InvalidArgumentException('Calculations require exact employee, report kind and source scope selections.');
            }
            self::validateBundleSelection($period, $selection['reportKind'], $selection['agentKey'], $selection['sourceScope']);
        }

        $sources = self::sources($artifacts, $period);
        $results = $locations = [];
        $structured = null;
        $structuredAttempted = false;
        $failure = ['ok' => false, 'error' => 'Frozen Customer Service calculation evidence is invalid or incomplete for the selected employee.'];
        foreach ($selections as $selectionIndex => $selection) {
            try {
                if ($selection['reportKind'] === 'retention') {
                    if (!$structuredAttempted) {
                        $structuredAttempted = true;
                        $structured = self::bundleStructuredLines($sources, $period, array_column($selections, 'agentKey'));
                    }
                    if ($structured === null) self::invalid();
                }
                $calculation = self::bundleCalculation($sources, $period, $selection['reportKind'], $selection['agentKey'], $selection['sourceScope']);
                $results[] = ['ok' => true, 'calculation' => $calculation];
                foreach ($calculation['components'] as $componentIndex => $component) {
                    $locations[$component['reportKey']][] = [$selectionIndex, $componentIndex];
                }
            } catch (\Throwable) {
                $results[] = $failure;
            }
        }

        // Count completed component payloads conservatively before retaining more
        // rows. The remaining transport headroom covers the controller envelope.
        $bytes = strlen(json_encode($results, JSON_THROW_ON_ERROR));
        foreach ($locations as $key => $selectionsForWorkbook) {
            $artifact = $sources[$key];
            // sources() already validates every present regular pricing record.
            $regularPricing = $artifact['context']['regularPricing'] ?? null;
            $workbook = null;
            $workbookAttempted = false;
            try {
                foreach ($selectionsForWorkbook as [$selectionIndex, $componentIndex]) {
                    if (!$results[$selectionIndex]['ok']) continue;
                    $calculation = $results[$selectionIndex]['calculation'];
                    $entry = $calculation['components'][$componentIndex];
                    try {
                        $agentKey = $calculation['agentKey'];
                        $lines = isset($structured[$key]) ? self::structuredAgentLines($structured[$key], $agentKey) : null;
                        if ($lines === null && self::needsBundleWorkbook($artifact, $entry)) {
                            if (!$workbookAttempted) {
                                $workbookAttempted = true;
                                $workbook = self::book($artifact);
                            }
                            if ($workbook === null) self::invalid();
                        }
                        $component = self::bundleComponent($artifact, $entry, $period, $agentKey, $workbook, $regularPricing, $lines);
                        $componentBytes = strlen(json_encode($component, JSON_THROW_ON_ERROR));
                    } catch (\Throwable) {
                        $results[$selectionIndex] = $failure;
                        continue;
                    }
                    $bytes += $componentBytes;
                    if ($bytes > self::MAX_BATCH_BYTES) throw new \LengthException('Customer Service calculation results exceed the batch limit.');
                    $results[$selectionIndex]['calculation']['components'][$componentIndex] = $component;
                }
            } finally {
                $workbook?->disconnectWorksheets();
            }
        }
        return $results;
    }

    private static function validateBundleSelection(string $period, string $reportKind, string $agentKey, string $sourceScope): void
    {
        if (!in_array($reportKind, ['retention', 'nsf'], true) || !in_array($sourceScope, ['ldr', 'plaw', 'both'], true)
            || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])-01$/D', $period)
            || $agentKey === '' || self::key($agentKey) !== $agentKey) throw new \InvalidArgumentException('Calculation lookup requires an exact normalized employee identity, report kind, source scope and month.');
    }

    private static function bundleCalculation(array $sources, string $period, string $reportKind, string $agentKey, string $sourceScope): array
    {
        $components = [];
        $families = $reportKind === 'nsf' ? ['nsf' => 'commission'] : ['retention' => 'commission', 'lookback' => 'bonusCommission'];
        foreach ($sourceScope === 'both' ? ['ldr', 'plaw'] : [$sourceScope] as $source) {
            $present = false;
            foreach ($families as $family => $_) $present = $present || self::result($sources[$family . '_' . $source], $agentKey) !== null;
            if (!$present) {
                if ($sourceScope !== 'both') throw new \RuntimeException('The pinned source has no exact captured employee identity.');
                continue;
            }
            foreach ($families as $family => $component) {
                $artifact = $sources[$family . '_' . $source];
                // Complete counterpart components prove an absent agent is zero, not an invented extra source.
                $amount = self::result($artifact, $agentKey) ?? 0.0;
                $components[] = ['reportKey' => $family . '_' . $source, 'source' => $source, 'artifactSha256' => $artifact['sha256'],
                    'component' => $component, 'amount' => $amount, 'status' => 'verified', 'lines' => []];
            }
        }
        return ['version' => 1, 'reportKind' => $reportKind, 'period' => $period, 'agentKey' => $agentKey,
            'sourceScope' => $sourceScope, 'components' => $components];
    }

    private static function bundleStructuredLines(array $sources, string $period, array $agentKeys, bool $preferStructuredLookback = true): array
    {
        $lines = [];
        foreach (self::regularPayrollLines($sources, $period, $agentKeys) as $source => $agents) {
            $lines['retention_' . $source] = $agents;
        }
        $lookbackRun = $preferStructuredLookback ? self::lookbackRun($sources) : null;
        if ($lookbackRun !== null) {
            foreach (self::verifiedLookbackLines($sources, $period, $lookbackRun) as $source => $agents) {
                $lines['lookback_' . $source] = $agents;
            }
        }
        return $lines;
    }

    private static function needsBundleWorkbook(array $artifact, array $entry): bool
    {
        return str_starts_with($entry['reportKey'], 'retention_')
            ? array_key_exists('regularPricing', $artifact['context']) : $entry['amount'] != 0.0;
    }

    private static function bundleComponent(array $artifact, array $entry, string $period, string $agentKey, ?Spreadsheet $workbook = null, ?array $regularPricing = null, ?array $structuredLines = null): array
    {
        $family = explode('_', $entry['reportKey'], 2)[0];
        $amount = $entry['amount'];
        if ($family === 'retention' && array_key_exists('regularPricing', $artifact['context'])) {
            $entry['lines'] = $structuredLines ?? self::regular($artifact, $period, $agentKey, $workbook, $regularPricing);
        } elseif ($amount != 0.0) {
            if ($family === 'retention') return self::unavailable($entry, 'Original regular Retention line prices were not captured.');
            $lines = $structuredLines ?? ($family === 'lookback' ? self::lookback($artifact, $agentKey, $workbook) : self::nsf($artifact, $agentKey, $amount, $workbook));
            if ($lines === null) return self::unavailable($entry, 'The original workbook does not retain trustworthy selected line prices for this component.');
            $entry['lines'] = $lines;
        }
        $sum = 0.0;
        foreach ($entry['lines'] as $line) $sum += (float) $line['amount'];
        if (!is_finite($sum) || round($sum, 2) !== round($amount, 2)) self::invalid();
        return $entry;
    }

    private static function sources(array $artifacts, string $period): array
    {
        if (count($artifacts) !== 6) self::invalid();
        $sources = [];
        foreach ($artifacts as $name => $artifact) {
            if (!is_array($artifact)) self::invalid();
            $key = $artifact['key'] ?? (is_string($name) ? $name : null);
            if (!in_array($key, PayrollReportArtifactArchive::SOURCE_KEYS, true) || isset($sources[$key])
                || is_string($name) && $name !== $key || !is_string($artifact['path'] ?? null)
                || !is_file($artifact['path']) || is_link($artifact['path']) || !is_array($artifact['context'] ?? null)
                || !is_string($artifact['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'])
                || isset($artifact['period']) && $artifact['period'] !== $period) self::invalid();
            clearstatcache(true, $artifact['path']);
            if (filesize($artifact['path']) < 4 || filesize($artifact['path']) > 32 * 1024 * 1024
                || array_key_exists('byteLength', $artifact) && (!is_int($artifact['byteLength']) || $artifact['byteLength'] !== filesize($artifact['path']))
                || !hash_equals($artifact['sha256'], (string) hash_file('sha256', $artifact['path']))) self::invalid();
            $zip = new ZipArchive;
            if ($zip->open($artifact['path']) !== true) self::invalid();
            try { if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) self::invalid(); }
            finally { $zip->close(); }
            $sources[$key] = $artifact;
        }
        if (array_diff(PayrollReportArtifactArchive::SOURCE_KEYS, array_keys($sources))) self::invalid();
        try { PayrollCommissionResultSnapshot::merge($sources, $period); }
        catch (\Throwable $error) { throw new \RuntimeException('Frozen Customer Service calculation evidence is invalid or incomplete.', 0, $error); }
        foreach ($sources as $artifact) foreach ($artifact['context']['commissionResults']['rows'] as $row) {
            if (!is_string($row['agent'] ?? null) || (preg_replace('/\s+/u', ' ', trim($row['agent'])) ?? '') !== $row['agent']
                || ($row['agentKey'] ?? null) !== self::key($row['agent'])
                || (!is_int($row['amount'] ?? null) && !is_float($row['amount'] ?? null))
                || !is_finite((float) $row['amount']) || round((float) $row['amount'], 2) !== (float) $row['amount']) self::invalid();
        }
        return $sources;
    }

    private static function result(array $artifact, string $agentKey): ?float
    {
        foreach ($artifact['context']['commissionResults']['rows'] as $row) if ($row['agentKey'] === $agentKey) return (float) $row['amount'];
        return null;
    }

    private static function regular(array $artifact, string $period, string $agentKey, ?Spreadsheet $book = null, ?array $proof = null): array
    {
        $proof ??= RetentionPricingSnapshot::validate($artifact['context']['regularPricing'], $artifact['context']['commissionResults']);
        $ownsBook = $book === null;
        $book ??= self::book($artifact);
        try {
            $sheet = $book->getSheetByName('Retention Data') ?? $book->getSheetByName('Retention Commission Report');
            if (!$sheet) self::invalid();
            $lastRow = $sheet->getHighestDataRow();
            $headers = self::headers($sheet);
            foreach (['id', 'retention agent', 'retention date', 'retention payment date', 't1 rate', 't2 rate', 't3 rate'] as $required) if (!isset($headers[$required])) self::invalid();
            $selected = [];
            foreach ($proof['rows'] as $row) {
                if ($row['agentKey'] !== $agentKey) continue;
                $r = $row['detailRow'];
                if ($r > $lastRow || self::id(self::value($sheet, $headers['id'], $r)) !== $row['clientId']
                    || self::key((string) self::value($sheet, $headers['retention agent'], $r)) !== $agentKey
                    || self::dateValue(self::value($sheet, $headers['retention date'], $r)) !== $row['retentionDate']
                    || self::dateValue(self::value($sheet, $headers['retention payment date'], $r)) !== $row['paymentDate']) self::invalid();
                foreach ([1, 2, 3, 4] as $tier) {
                    $value = isset($headers['t' . $tier . ' rate']) ? self::number(self::value($sheet, $headers['t' . $tier . ' rate'], $r)) : 0.0;
                    if ($value !== (float) $row['rates'][$tier - 1]) self::invalid();
                }
                if (isset($headers['applied tier']) && self::number(self::value($sheet, $headers['applied tier'], $r)) !== (float) $row['appliedTier']) self::invalid();
                if (isset($headers['payable commission']) && self::number(self::value($sheet, $headers['payable commission'], $r)) !== (float) $row['amount']) self::invalid();
                $selected[$r] = self::regularLine($row);
            }
            // A balanced omission could leave an unchanged aggregate. Bind every payable employee row, including tier zero.
            for ($r = 2; $r <= $lastRow; $r++) {
                if (self::key((string) self::value($sheet, $headers['retention agent'], $r)) !== $agentKey) continue;
                $retained = self::dateValue(self::value($sheet, $headers['retention date'], $r));
                $payment = self::dateValue(self::value($sheet, $headers['retention payment date'], $r));
                if ($retained !== null && $payment !== null && substr($payment, 0, 7) === substr($period, 0, 7) && !isset($selected[$r])) self::invalid();
            }
            return array_values($selected);
        } finally { if ($ownsBook) $book->disconnectWorksheets(); }
    }

    private static function regularPayrollLines(array $artifacts, string $period, array $agentKeys): array
    {
        $inputs = RetentionStructuredCapture::payrollInputs($period, $artifacts);
        if ($inputs === null) return [];
        $output = [];
        $selectedAgents = array_fill_keys(array_map(self::archivedAgentKey(...), $agentKeys), true);
        foreach ($inputs['rowsBySource'] as $source => $rows) {
            $context = $artifacts['retention_' . $source]['context'];
            if (!is_array($context['regularPricing'] ?? null) || !is_array($context['commissionResults'] ?? null)) self::invalid();
            $proof = RetentionPricingSnapshot::validate($context['regularPricing'], $context['commissionResults']);
            if ($proof['source'] !== $source || $proof['period'] !== $period) self::invalid();
            $output[$source] = self::regularStructuredLines($rows, $proof['rows'], $period, $selectedAgents, $context['regularPricingDates'] ?? []);
        }
        return $output;
    }

    private static function regularStructuredLines(array $rows, array $pricing, string $period, array $selectedAgents, array $pricingDates): array
    {
        $selected = $output = $unverified = [];
        foreach ($pricing as $price) {
            $agentKey = $price['agentKey'];
            if (!isset($selectedAgents[self::archivedAgentKey($agentKey)])) continue;
            $index = $price['detailRow'] - 2;
            $row = $rows[$index] ?? null;
            if ($row !== null) $row = ($pricingDates['LLG-' . $row['ID']] ?? []) + $row;
            if ($row === null || $row['ID'] !== $price['clientId'] || self::key($row['RETENTION_AGENT']) !== $agentKey
                || $row['RETENTION_DATE'] !== $price['retentionDate'] || $row['RETENTION_PAYMENT_DATE'] !== $price['paymentDate']
                || array_map(static fn ($rate) => $rate === null ? null : (float) $rate, [$row['T1'], $row['T2'], $row['T3'], $row['T4']])
                    !== array_map(static fn ($rate) => (float) $rate, $price['rates'])) {
                $unverified[$agentKey] = null;
                continue;
            }
            $selected[$index] = true;
            $output[$agentKey][] = self::regularLine($price);
        }
        foreach ($rows as $index => $row) {
            if (isset($selected[$index])) continue;
            $agentKey = self::key($row['RETENTION_AGENT']);
            if (!isset($selectedAgents[self::archivedAgentKey($agentKey)])) continue;
            $row = ($pricingDates['LLG-' . $row['ID']] ?? []) + $row;
            if ($row['RETENTION_DATE'] !== null && $row['RETENTION_PAYMENT_DATE'] !== null
                && substr($row['RETENTION_PAYMENT_DATE'], 0, 7) === substr($period, 0, 7)) {
                $unverified[$agentKey] = null;
            }
        }
        return array_replace($output, $unverified);
    }

    private static function structuredAgentLines(array $lines, string $agentKey): array
    {
        if (array_key_exists($agentKey, $lines) && $lines[$agentKey] === null) self::invalid();
        return $lines[$agentKey] ?? [];
    }

    private static function regularLine(array $row): array
    {
        return ['clientId' => $row['clientId'], 'retentionDate' => $row['retentionDate'], 'paymentDate' => $row['paymentDate'],
            'retainedMonth' => $row['retainedMonth'], 'appliedTier' => $row['appliedTier'],
            'rate' => $row['appliedTier'] === 0 ? 0 : $row['rates'][$row['appliedTier'] - 1], 'amount' => $row['amount']];
    }

    private static function lookbackRun(array $artifacts): ?string
    {
        $ldr = $artifacts['lookback_ldr'] ?? [];
        $plaw = $artifacts['lookback_plaw'] ?? [];
        if (!array_key_exists('lookbackPricing', $ldr['context'] ?? [])
            && !array_key_exists('lookbackPricing', $plaw['context'] ?? [])) return null;
        $identity = $ldr['identity'] ?? null;
        if (is_string($identity) && preg_match('/^[a-f0-9]{64}$/D', $identity) && ($plaw['identity'] ?? null) === $identity) {
            return null;
        }
        if (!is_string($identity) || !preg_match('/^[a-f0-9]{32}$/D', $identity)) self::invalid();
        return $identity;
    }

    private static function verifiedLookbackLines(array $artifacts, string $period, string $runId): array
    {
        $lines = [];
        $captureId = $queryOnly = null;
        foreach (['ldr', 'plaw'] as $source) {
            $key = 'lookback_' . $source;
            $artifact = $artifacts[$key] ?? null;
            self::validateArchivedArtifact($artifact, $key, $period, false);
            $context = $artifact['context'];
            $pricing = $context['lookbackPricing'] ?? null;
            $result = $context['commissionResults'] ?? null;
            $mode = $context['queryOnly'] ?? false;
            if (($artifact['identity'] ?? null) !== $runId || ($artifact['period'] ?? null) !== $period
                || (array_key_exists('queryOnly', $context) && !is_bool($context['queryOnly']))
                || ($queryOnly !== null && $queryOnly !== $mode)
                || !is_array($pricing) || !is_array($result) || !is_array($result['rows'] ?? null)) self::invalid();
            $checked = PayrollCommissionResultSnapshot::component('retention', $source, $period, 'bonusCommission', $result['rows']);
            if (json_encode($checked, JSON_THROW_ON_ERROR) !== json_encode($result, JSON_THROW_ON_ERROR)) self::invalid();
            $lines[$source] = LookbackPricingSnapshot::validatedLines($pricing, $checked);
            if ($captureId !== null && $captureId !== $pricing['captureId']) self::invalid();
            $captureId = $pricing['captureId'];
            $queryOnly = $mode;
        }
        return $lines;
    }

    private static function lookback(array $artifact, string $agentKey, ?Spreadsheet $book = null): ?array
    {
        $ownsBook = $book === null;
        $book ??= self::book($artifact);
        try {
            $sheet = $book->getSheetByName('Retention Data');
            if (!$sheet) return null;
            $lastRow = $sheet->getHighestDataRow();
            $headers = self::headers($sheet);
            foreach (['id', 'retention agent', 'retention commission'] as $required) if (!isset($headers[$required])) return null;
            $lines = []; $seen = [];
            for ($r = 2; $r <= $lastRow; $r++) {
                if (self::key((string) self::value($sheet, $headers['retention agent'], $r)) !== $agentKey) continue;
                $amount = self::value($sheet, $headers['retention commission'], $r);
                if (is_string($amount) && str_starts_with($amount, '=')) return null;
                $id = self::id(self::value($sheet, $headers['id'], $r));
                if (isset($seen[$id])) self::invalid();
                $seen[$id] = true;
                $line = ['clientId' => $id, 'amount' => self::number($amount)];
                foreach (['retention date' => 'retentionDate', 'first payment date' => 'paymentDate'] as $header => $field) if (isset($headers[$header])) {
                    $date = self::dateValue(self::value($sheet, $headers[$header], $r));
                    if ($date !== null) $line[$field] = $date;
                }
                $lines[] = $line;
            }
            if (!$lines) return null;
            return $lines;
        } finally { if ($ownsBook) $book->disconnectWorksheets(); }
    }

    private static function nsf(array $artifact, string $agentKey, float $amount, ?Spreadsheet $book = null): ?array
    {
        $ownsBook = $book === null;
        $book ??= self::book($artifact);
        try {
            $summary = $book->getSheetByName('Agent Summary');
            $data = $book->getSheetByName('NSF Data');
            if (!$summary || !$data) return null;
            $lastSummaryRow = $summary->getHighestDataRow();
            $lastDataRow = $data->getHighestDataRow();
            $columns = self::headers($summary); $headers = self::headers($data);
            foreach (['nsf agent', 'rate', 'clears', 'commission'] as $required) if (!isset($columns[$required])) return null;
            foreach (['id', 'agent', 'valid commission'] as $required) if (!isset($headers[$required])) return null;
            $matches = [];
            for ($r = 2; $r <= $lastSummaryRow; $r++) if (self::key((string) self::value($summary, $columns['nsf agent'], $r)) === $agentKey) $matches[] = $r;
            if (!$matches) return null;
            if (count($matches) !== 1) self::invalid();
            $row = $matches[0];
            if (round(self::number(self::value($summary, $columns['commission'], $row)), 2) !== round($amount, 2)) self::invalid();
            return self::nsfLines($summary, $data, $columns, $headers, $row, $agentKey, $lastDataRow);
        } finally { if ($ownsBook) $book->disconnectWorksheets(); }
    }

    private static function savedNsfRate(Worksheet $summary, array $columns, int $row): ?float
    {
        if (!isset($columns['rate'])) return null;
        $value = self::value($summary, $columns['rate'], $row);
        if (self::missingOriginalValue($value)) return null;
        $cell = Coordinate::stringFromColumnIndex($columns['rate']) . $row;
        $comment = $summary->getComments()[$cell] ?? null;
        if ($comment && stripos($comment->getText()->getPlainText(), 'reconciled from the original frozen commission amount') !== false) return null;
        return self::number($value);
    }

    private static function missingOriginalValue(mixed $value): bool
    {
        return $value === null || $value === '' || (is_string($value) && str_starts_with($value, '='));
    }

    private static function nsfLines(Worksheet $summary, Worksheet $data, array $columns, array $headers, int $row, string $agentKey, int $lastDataRow): ?array
    {
        $rate = self::savedNsfRate($summary, $columns, $row);
        if ($rate === null) return null;
        $clears = self::number(self::value($summary, $columns['clears'], $row));
        if ($clears < 0 || floor($clears) !== $clears) self::invalid();
        $rows = [];
        for ($r = 2; $r <= $lastDataRow; $r++) {
            if (self::key((string) self::value($data, $headers['agent'], $r)) === $agentKey) $rows[] = $r;
        }
        $facts = self::nsfContactFacts($data, $headers, $rows, false);
        if ($facts['assignments'] === 0) return null;
        if (!$facts['eligible'] || count($facts['eligible']) !== (int) $clears) self::invalid();
        foreach (['assignments', 'actions'] as $header) if (isset($columns[$header])
            && self::number(self::value($summary, $columns[$header], $row)) !== (float) $facts[$header]) self::invalid();
        if (isset($columns['ratio']) && round(self::number(self::value($summary, $columns['ratio'], $row)), 4)
            !== round($facts['actions'] / $facts['assignments'], 4)) self::invalid();
        return array_map(static fn (array $line): array => ['clientId' => $line['clientId'], 'rate' => $rate, 'amount' => $rate]
            + array_intersect_key($line, ['paymentDate' => true]), $facts['eligible']);
    }

    private static function nsfContactFacts(Worksheet $data, array $headers, array $rows, bool $allowIncomplete): array
    {
        $eligible = $seen = [];
        $assignments = $actions = $unknownFlags = $unknownActions = 0;
        $duplicateIds = $incompleteDetail = false;
        foreach ($rows as $r) {
            $assignments++;
            $line = [];
            if (isset($headers['id'])) {
                $value = self::value($data, $headers['id'], $r);
                if ($allowIncomplete && self::missingOriginalValue($value)) {
                    $incompleteDetail = true;
                } else {
                    $id = self::id($value);
                    if (isset($seen[$id])) {
                        if (!$allowIncomplete) self::invalid();
                        $duplicateIds = true;
                    }
                    $seen[$id] = true;
                    $line['clientId'] = $id;
                }
            }
            $action = isset($headers['nsf action']) ? (string) self::value($data, $headers['nsf action'], $r) : '';
            if ($allowIncomplete && (!isset($headers['nsf action']) || str_starts_with($action, '='))) $unknownActions++;
            elseif (trim($action) !== '') $actions++;
            $flag = isset($headers['valid commission']) ? self::value($data, $headers['valid commission'], $r) : null;
            if ($allowIncomplete && self::missingOriginalValue($flag)) {
                $unknownFlags++;
                continue;
            }
            if (!CustomerServiceRosterAmendment::frozenValidity($flag)) continue;
            if (isset($headers['cleared date'])) {
                $value = self::value($data, $headers['cleared date'], $r);
                if ($allowIncomplete && is_string($value) && str_starts_with($value, '=')) {
                    $incompleteDetail = true;
                } else {
                    $date = self::dateValue($value);
                    if ($date !== null) $line['paymentDate'] = $date;
                }
            }
            $eligible[] = $line;
        }
        return compact('eligible', 'assignments', 'actions', 'unknownActions', 'unknownFlags', 'duplicateIds', 'incompleteDetail');
    }

    private static function headers(Worksheet $sheet): array
    {
        $headers = [];
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($c = 1; $c <= $lastColumn; $c++) {
            $value = self::value($sheet, $c, 1);
            if ($value === null || $value === '') continue;
            $key = self::key((string) $value);
            if (isset($headers[$key])) self::invalid();
            $headers[$key] = $c;
        }
        return $headers;
    }
    private static function value(Worksheet $sheet, int $column, int $row): mixed
    {
        $coordinate = Coordinate::stringFromColumnIndex($column) . $row;
        // getCell() creates missing cells, changing the sheet for the next selection.
        if (!$sheet->cellExists($coordinate)) return null;
        $cell = $sheet->getCell($coordinate);
        if ($cell->getDataType() === DataType::TYPE_FORMULA) return (string) $cell->getValue();
        return $cell->getValue();
    }
    private static function book(array $artifact): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        try { return IOFactory::load($artifact['path']); }
        catch (\Throwable $error) { throw new \RuntimeException('The original Customer Service workbook is unreadable.', 0, $error); }
    }
    private static function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0) {
            try { $value = ExcelDate::excelToDateTimeObject($value, new \DateTimeZone('UTC'))->format('Y-m-d'); }
            catch (\Throwable) { self::invalid(); }
        }
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) return $value;
        self::invalid();
    }
    private static function number(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) || !is_finite((float) $value)) self::invalid();
        return (float) $value;
    }
    private static function id(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || trim((string) $value) === '' || is_float($value) && !is_finite($value)) self::invalid();
        return trim((string) $value);
    }
    private static function key(string $value): string { return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '', 'UTF-8'); }
    private static function unavailable(array $entry, string $reason): array { return array_replace($entry, ['status' => 'unavailable', 'reason' => $reason, 'lines' => []]); }
    private static function invalid(): never { throw new \RuntimeException('Frozen Customer Service calculation evidence is invalid or incomplete.'); }
}
