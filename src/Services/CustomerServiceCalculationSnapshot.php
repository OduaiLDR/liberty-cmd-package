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
    public static function forArchivedAgent(array $artifacts, string $period, string $report, string $agentKey, string $sourceScope): array
    {
        self::validateArchivedSelection($period, $report, $agentKey, $sourceScope);
        $family = match ($report) { 'retention_lookback' => 'lookback', 'retention_regular' => 'retention', default => 'nsf' };
        return self::selectArchivedAgent($artifacts, $period, $report, $family, $agentKey, $sourceScope);
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

    private static function selectArchivedAgent(array $artifacts, string $period, string $report, string $family, string $agentKey, string $sourceScope): array
    {
        if (count($artifacts) !== 2 || array_diff([$family . '_ldr', $family . '_plaw'], array_keys($artifacts))) self::invalid();
        // Both source files and contexts are validated even for a one-company request.
        $validated = [];
        foreach (['ldr', 'plaw'] as $source) {
            $key = $family . '_' . $source;
            $artifact = $artifacts[$key];
            self::validateArchivedArtifact($artifact, $key, $period);
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
                $entry['lines'] = self::regular($artifact, $period, $sourceAgentKey);
            } elseif ($amount != 0.0) {
                $lines = match ($family) { 'nsf' => self::nsf($artifact, $sourceAgentKey, $amount), 'lookback' => self::lookback($artifact, $sourceAgentKey), default => null };
                if ($lines === null) $entry = self::unavailable($entry, 'The original workbook does not retain trustworthy selected line prices for this component.');
                else $entry['lines'] = $lines;
            }
            if (count($entry['lines']) > 20000) self::invalid();
            if ($entry['status'] === 'verified' && round(array_sum(array_column($entry['lines'], 'amount')), 2) !== round((float) $amount, 2)) self::invalid();
            if (is_link($artifact['path']) || !hash_equals($artifact['sha256'], (string) hash_file('sha256', $artifact['path']))) self::invalid();
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

    private static function validateArchivedArtifact(mixed $artifact, string $key, string $period): void
    {
        if (!is_array($artifact) || ($artifact['key'] ?? null) !== $key || ($artifact['period'] ?? $period) !== $period
            || !is_array($artifact['context'] ?? null) || !is_string($artifact['path'] ?? null)
            || is_link($artifact['path']) || !is_file($artifact['path']) || !is_readable($artifact['path'])
            || !is_string($artifact['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'])
            || !is_string($artifact['contextSha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $artifact['contextSha256'])
            || hash('sha256', json_encode($artifact['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== $artifact['contextSha256']) self::invalid();
        clearstatcache(true, $artifact['path']);
        if (filesize($artifact['path']) < 4 || filesize($artifact['path']) > 32 * 1024 * 1024
            || isset($artifact['byteLength']) && $artifact['byteLength'] !== filesize($artifact['path'])
            || !hash_equals($artifact['sha256'], (string) hash_file('sha256', $artifact['path']))) self::invalid();
        $zip = new ZipArchive;
        if ($zip->open($artifact['path'], ZipArchive::RDONLY) !== true) self::invalid();
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) { $entry = $zip->statIndex($i); if (!is_array($entry)) self::invalid(); $size += (int) ($entry['size'] ?? 0); }
            if ($size > 128 * 1024 * 1024 || $zip->locateName('xl/workbook.xml') === false || $zip->locateName('[Content_Types].xml') === false) self::invalid();
        } finally { $zip->close(); }
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

    public static function forAgent(array $artifacts, string $period, string $reportKind, string $agentKey, string $sourceScope): array
    {
        self::validateBundleSelection($period, $reportKind, $agentKey, $sourceScope);
        $sources = self::sources($artifacts, $period);
        $calculation = self::bundleCalculation($sources, $period, $reportKind, $agentKey, $sourceScope);
        foreach ($calculation['components'] as $index => $component) {
            $calculation['components'][$index] = self::bundleComponent($sources[$component['reportKey']], $component, $period, $agentKey);
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
        $failure = ['ok' => false, 'error' => 'Frozen Customer Service calculation evidence is invalid or incomplete for the selected employee.'];
        foreach ($selections as $selectionIndex => $selection) {
            try {
                $calculation = self::bundleCalculation($sources, $period, $selection['reportKind'], $selection['agentKey'], $selection['sourceScope']);
                $results[] = ['ok' => true, 'calculation' => $calculation];
                foreach ($calculation['components'] as $componentIndex => $component) {
                    $locations[$component['reportKey']][] = [$selectionIndex, $componentIndex];
                }
            } catch (\RuntimeException) {
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
                        if (self::needsBundleWorkbook($artifact, $entry)) {
                            if (!$workbookAttempted) {
                                $workbookAttempted = true;
                                $workbook = self::book($artifact);
                            }
                            if ($workbook === null) self::invalid();
                        }
                        $component = self::bundleComponent($artifact, $entry, $period, $calculation['agentKey'], $workbook, $regularPricing);
                    } catch (\Throwable) {
                        $results[$selectionIndex] = $failure;
                        continue;
                    }
                    $bytes += strlen(json_encode($component, JSON_THROW_ON_ERROR));
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

    private static function needsBundleWorkbook(array $artifact, array $entry): bool
    {
        return str_starts_with($entry['reportKey'], 'retention_')
            ? array_key_exists('regularPricing', $artifact['context']) : $entry['amount'] != 0.0;
    }

    private static function bundleComponent(array $artifact, array $entry, string $period, string $agentKey, ?Spreadsheet $workbook = null, ?array $regularPricing = null): array
    {
        $family = explode('_', $entry['reportKey'], 2)[0];
        $amount = $entry['amount'];
        if ($family === 'retention' && array_key_exists('regularPricing', $artifact['context'])) {
            $entry['lines'] = self::regular($artifact, $period, $agentKey, $workbook, $regularPricing);
        } elseif ($amount != 0.0) {
            if ($family === 'retention') return self::unavailable($entry, 'Original regular Retention line prices were not captured.');
            $lines = $family === 'lookback' ? self::lookback($artifact, $agentKey, $workbook) : self::nsf($artifact, $agentKey, $amount, $workbook);
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
                $selected[$r] = ['clientId' => $row['clientId'], 'retentionDate' => $row['retentionDate'], 'paymentDate' => $row['paymentDate'],
                    'retainedMonth' => $row['retainedMonth'], 'appliedTier' => $row['appliedTier'],
                    'rate' => $row['appliedTier'] === 0 ? 0 : $row['rates'][$row['appliedTier'] - 1], 'amount' => $row['amount']];
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
            $rateValue = self::value($summary, $columns['rate'], $row);
            if ($rateValue === null || $rateValue === '' || is_string($rateValue) && str_starts_with($rateValue, '=')) return null;
            $rateCell = Coordinate::stringFromColumnIndex($columns['rate']) . $row;
            $comment = $summary->getComments()[$rateCell] ?? null;
            if ($comment && stripos($comment->getText()->getPlainText(), 'reconciled from the original frozen commission amount') !== false) return null;
            $rate = self::number($rateValue);
            $clears = self::number(self::value($summary, $columns['clears'], $row));
            if ($clears < 0 || floor($clears) !== $clears) self::invalid();
            $lines = []; $seen = []; $assignments = $actions = 0;
            for ($r = 2; $r <= $lastDataRow; $r++) {
                if (self::key((string) self::value($data, $headers['agent'], $r)) !== $agentKey) continue;
                $id = self::id(self::value($data, $headers['id'], $r));
                if (isset($seen[$id])) self::invalid();
                $seen[$id] = true; $assignments++;
                if (isset($headers['nsf action']) && trim((string) self::value($data, $headers['nsf action'], $r)) !== '') $actions++;
                $valid = CustomerServiceRosterAmendment::frozenValidity(self::value($data, $headers['valid commission'], $r));
                if (!$valid) continue;
                $line = ['clientId' => $id, 'rate' => $rate, 'amount' => $rate];
                if (isset($headers['cleared date'])) {
                    $date = self::dateValue(self::value($data, $headers['cleared date'], $r));
                    if ($date !== null) $line['paymentDate'] = $date;
                }
                $lines[] = $line;
            }
            if ($assignments === 0) return null;
            if (count($lines) !== (int) $clears || !$lines) self::invalid();
            foreach (['assignments' => $assignments, 'actions' => $actions] as $header => $count) if (isset($columns[$header])
                && self::number(self::value($summary, $columns[$header], $row)) !== (float) $count) self::invalid();
            if (isset($columns['ratio']) && round(self::number(self::value($summary, $columns['ratio'], $row)), 4) !== round($actions / $assignments, 4)) self::invalid();
            return $lines;
        } finally { if ($ownsBook) $book->disconnectWorksheets(); }
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
