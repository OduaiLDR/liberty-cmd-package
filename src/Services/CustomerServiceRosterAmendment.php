<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/** Promotes verified membership using original priced evidence; never queries or reprices. */
final class CustomerServiceRosterAmendment
{
    private const HEADERS = [
        'retention' => ['Retention Agent', 'Assigned', 'Retained', '% Retained', 'Tier', 'Commission', 'Location', 'Company'],
        'lookback' => ['Retention Agent', 'Total Commission', 'Location', 'Company'],
        'nsf' => ['NSF Agent', 'Assignments', 'Actions', 'Ratio', 'Actions Tier', 'Cleared Tier', 'Rate', 'Clears', 'Commission', 'Location', 'Company'],
    ];

    public function amendSources(array $sources, array $assignments, string $outputDir): array
    {
        $targets = $this->assignments($assignments);
        foreach (PayrollReportArtifactArchive::SOURCE_KEYS as $key) {
            if (!is_array($sources[$key] ?? null) || !is_file($sources[$key]['path'] ?? '') || is_link($sources[$key]['path'])) {
                throw new \RuntimeException('Every original frozen source workbook is required for amendment.');
            }
        }
        $period = $sources['retention_ldr']['context']['commissionResults']['period'] ?? '';
        PayrollCommissionResultSnapshot::merge($sources, $period);
        if (is_link($outputDir) || (!is_dir($outputDir) && !mkdir($outputDir, 0700, true) && !is_dir($outputDir))) {
            throw new \RuntimeException('The isolated amendment output directory is unavailable.');
        }
        // A private attempt directory prevents an output path from overwriting any source.
        $attempt = rtrim($outputDir, '/\\') . '/sources-' . bin2hex(random_bytes(12));
        if (!mkdir($attempt, 0700)) throw new \RuntimeException('Cannot stage source amendments.');
        $result = ['sources' => [], 'membershipAdditions' => [], 'changedKeys' => []];
        foreach (PayrollReportArtifactArchive::SOURCE_KEYS as $key) {
            [$family, $source] = explode('_', $key);
            $kind = $family === 'nsf' ? 'nsf' : 'retention';
            $selected = $targets[$kind . ':' . $source] ?? [];
            $file = ['filename' => $key . ' - Amended.xlsx', 'path' => $attempt . '/' . $key . '.xlsx'];
            $context = $sources[$key]['context'];
            $amounts = array_column($context['commissionResults']['rows'], 'amount', 'agentKey');
            $nonzero = array_filter($selected, fn ($assignment) => $this->cents($amounts[$assignment['agentKey']] ?? 0) !== 0);
            if ($nonzero === []) {
                if (!copy($sources[$key]['path'], $file['path'])) throw new \RuntimeException('Cannot preserve original source workbook.');
            } else {
                $book = IOFactory::load($sources[$key]['path']);
                try {
                    $sheet = $book->getSheetByName($family === 'retention' ? 'Commission Summary' : 'Agent Summary');
                    if (!$sheet) throw new \RuntimeException('The original paid summary is missing.');
                    foreach (self::HEADERS[$family] as $column => $header) {
                        if (trim((string) $sheet->getCell($this->cell($column + 1, 1))->getValue()) !== $header) throw new \RuntimeException('The original paid summary headers do not match the frozen report.');
                    }
                    [$paid, $unassigned] = $this->summaryRows($sheet, $family);
                    $changed = false;
                    foreach ($nonzero as $assignment) {
                        $agentKey = $assignment['agentKey'];
                        $amount = (float) $amounts[$agentKey];
                        $nsfStats = $family === 'nsf' ? $this->nsfStats($book->getSheetByName('NSF Data'), $agentKey) : null;
                        if ($family !== 'nsf') $this->assertRawDetail($book, $family, $assignment, $amount);
                        if (isset($paid[$agentKey])) {
                            $this->assertAmount($paid[$agentKey], $family, $amount);
                            if ($family === 'nsf') $this->assertPaidNsfSummary($paid[$agentKey], $nsfStats, $amount);
                        } else {
                            $row = $unassigned[$agentKey] ?? null;
                            if ($row !== null) $this->assertAmount($row, $family, $amount);
                            if ($family === 'nsf') {
                                $row = $this->nsfSummary($sheet, $assignment, $amount, $nsfStats);
                            } elseif ($row === null) {
                                throw new \RuntimeException('A nonzero frozen Retention or Lookback component has no original summary detail. Preserve it for reconciliation.');
                            }
                            $paid[$agentKey] = $row;
                            unset($unassigned[$agentKey]);
                            $changed = true;
                        }
                        $result['membershipAdditions'][] = ['reportKey' => $key, 'agentKey' => $agentKey,
                            'employeePk' => $assignment['employeePk'], 'amount' => round($amount, 2)];
                    }
                    if ($changed) {
                        $this->writeSummary($sheet, $family, $paid, $unassigned);
                        $writer = new Xlsx($book);
                        $writer->setPreCalculateFormulas(false);
                        $writer->save($file['path']);
                        $this->normalizeZipTimes($file['path']);
                        $result['changedKeys'][] = $key;
                    } elseif (!copy($sources[$key]['path'], $file['path'])) throw new \RuntimeException('Cannot preserve original source workbook.');
                } finally { $book->disconnectWorksheets(); }
            }
            $result['sources'][$key] = ['file' => $file, 'context' => $context];
        }
        return $result;
    }

    private function assignments(array $assignments): array
    {
        if (!array_is_list($assignments)) throw new \InvalidArgumentException('Amendment assignments must be a canonical list.');
        $result = $employeeScopes = [];
        $keys = ['reportKind', 'source', 'agentKey', 'employeePk', 'employeeName', 'location', 'company'];
        foreach ($assignments as $assignment) {
            if (!is_array($assignment) || array_diff(array_keys($assignment), $keys) || array_diff($keys, array_keys($assignment))) throw new \InvalidArgumentException('Amendment assignments require verified identity metadata only.');
            foreach (array_diff($keys, ['employeePk']) as $key) if (!is_string($assignment[$key])) throw new \InvalidArgumentException('Amendment identity metadata is invalid.');
            if (!in_array($assignment['reportKind'], ['nsf', 'retention'], true) || !in_array($assignment['source'], ['ldr', 'plaw'], true)
                || !is_int($assignment['employeePk']) || $assignment['employeePk'] < 1 || $assignment['agentKey'] === ''
                || $this->agentKey($assignment['agentKey']) !== $assignment['agentKey']
                || $this->agentKey($assignment['employeeName']) !== $assignment['agentKey']
                || trim($assignment['location']) === '' || trim($assignment['company']) === '') {
                throw new \InvalidArgumentException('Amendment identity must exactly match its verified employee and source.');
            }
            $scope = $assignment['reportKind'] . ':' . $assignment['source'];
            $employeeScope = $scope . ':' . $assignment['employeePk'];
            if (isset($result[$scope][$assignment['agentKey']]) || isset($employeeScopes[$employeeScope])) throw new \InvalidArgumentException('Amendment identities must map one-to-one within each report and source.');
            $result[$scope][$assignment['agentKey']] = $assignment;
            $employeeScopes[$employeeScope] = true;
        }
        foreach ($result as &$scope) ksort($scope, SORT_STRING);
        return $result;
    }

    private function summaryRows(Worksheet $sheet, string $family): array
    {
        $paid = $unassigned = $seen = [];
        $inUnassigned = false;
        for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
            $name = trim((string) $sheet->getCell('A' . $r)->getValue());
            if ($name === '') continue;
            if (strcasecmp($name, 'Unassigned Agents') === 0) {
                if ($inUnassigned) throw new \RuntimeException('The frozen summary has duplicate unassigned sections.');
                $inUnassigned = true;
                continue;
            }
            $key = $this->agentKey($name);
            if (isset($seen[$key])) throw new \RuntimeException('The frozen summary contains an ambiguous employee identity.');
            $seen[$key] = true;
            $row = [];
            foreach (self::HEADERS[$family] as $index => $_) {
                $coordinate = $this->cell($index + 1, $r);
                $cell = $sheet->getCell($coordinate);
                $row[] = ['value' => $cell->getValue(), 'type' => $cell->getDataType(),
                    'style' => clone $sheet->getParent()->getCellXfByIndex($cell->getXfIndex()),
                    'comment' => isset($sheet->getComments()[$coordinate]) ? clone $sheet->getComments()[$coordinate] : null];
            }
            if ($inUnassigned) $unassigned[$key] = $row; else $paid[$key] = $row;
        }
        return [$paid, $unassigned];
    }

    private function assertAmount(array $row, string $family, float $amount): void
    {
        $column = ['retention' => 5, 'lookback' => 1, 'nsf' => 8][$family];
        if ($this->cents($row[$column]['value']) !== $this->cents($amount)) throw new \RuntimeException('The frozen summary amount differs from its archived computed component.');
    }

    private function assertRawDetail(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $family, array $assignment, float $amount): void
    {
        $data = $book->getSheetByName('Retention Data')
            ?? ($family === 'retention' ? $book->getSheetByName('Retention Commission Report') : null);
        if (!$data) throw new \RuntimeException('A nonzero frozen component requires its original raw Retention Data sheet.');
        $columns = [];
        $required = $family === 'lookback' ? ['ID', 'RETENTION AGENT', 'RETENTION COMMISSION'] : ['ID', 'RETENTION AGENT'];
        $lastColumn = Coordinate::columnIndexFromString($data->getHighestDataColumn());
        for ($c = 1; $c <= $lastColumn; $c++) {
            $header = strtoupper(trim((string) $data->getCell($this->cell($c, 1))->getValue()));
            if (!in_array($header, $required, true)) continue;
            if (isset($columns[$header])) throw new \RuntimeException('Frozen raw detail has ambiguous identity or priced amount columns.');
            $columns[$header] = $c;
        }
        if (array_diff($required, array_keys($columns))) throw new \RuntimeException('Frozen raw detail lacks its identity or already priced line amount.');
        $matches = 0;
        $lineCents = 0;
        for ($r = 2; $r <= $data->getHighestDataRow(); $r++) {
            $name = (string) $data->getCell($this->cell($columns['RETENTION AGENT'], $r))->getValue();
            if ($this->agentKey($name) !== $assignment['agentKey']) continue;
            if (trim((string) $data->getCell($this->cell($columns['ID'], $r))->getValue()) === '') throw new \RuntimeException('Frozen raw detail has no client identity.');
            $matches++;
            if ($family === 'lookback') {
                // Retention Commission is the completed pricing output. Rates,
                // violations and Agent Deduction must never be applied again.
                $lineCents += $this->cents($data->getCell($this->cell($columns['RETENTION COMMISSION'], $r))->getValue());
                if (!is_int($lineCents)) throw new \RuntimeException('Frozen Lookback line amounts exceed the supported monetary range.');
            }
        }
        if ($matches === 0) throw new \RuntimeException('A nonzero frozen component has no matching employee in its original raw detail.');
        if ($family === 'lookback' && $lineCents !== $this->cents($amount)) throw new \RuntimeException('Frozen Lookback priced line amounts differ from the archived computed component.');
    }

    private function nsfStats(?Worksheet $data, string $agentKey): array
    {
        if (!$data) throw new \RuntimeException('Frozen NSF detail is missing.');
        $headers = ['ID', 'Agent', 'NSF Returned Date', 'NSF Action', 'NSF Recoup Date', 'Cleared Date', 'Valid Commission'];
        foreach ($headers as $index => $header) if (trim((string) $data->getCell($this->cell($index + 1, 1))->getValue()) !== $header) throw new \RuntimeException('Frozen NSF detail headers are incomplete.');
        $assignments = $actions = $clears = 0;
        $ids = [];
        for ($r = 2; $r <= $data->getHighestDataRow(); $r++) {
            if ($this->agentKey((string) $data->getCell('B' . $r)->getValue()) !== $agentKey) continue;
            $id = trim((string) $data->getCell('A' . $r)->getValue());
            if ($id === '' || isset($ids[$id])) throw new \RuntimeException('Frozen NSF detail requires unique client identities.');
            $ids[$id] = true;
            $assignments++;
            if (trim((string) $data->getCell('D' . $r)->getValue()) !== '') $actions++;
            if (self::frozenValidity($data->getCell('G' . $r)->getValue())) $clears++;
        }
        if ($assignments === 0 || $clears === 0) throw new \RuntimeException('A nonzero NSF amount cannot be reconciled to frozen valid clears.');
        return ['assignments' => $assignments, 'actions' => $actions, 'clears' => $clears, 'ratio' => round($actions / $assignments, 4)];
    }

    private function assertPaidNsfSummary(array $row, array $stats, float $amount): void
    {
        foreach ([1 => 'assignments', 2 => 'actions', 7 => 'clears'] as $column => $key) {
            $value = $row[$column]['value'];
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value !== (float) $stats[$key]) {
                throw new \RuntimeException('The frozen paid NSF summary counts differ from its original raw detail.');
            }
        }
        $ratio = $row[3]['value'];
        if (!is_numeric($ratio) || !is_finite((float) $ratio) || round((float) $ratio, 4) !== $stats['ratio']) {
            throw new \RuntimeException('The frozen paid NSF summary ratio differs from its original raw detail.');
        }
        $rate = $row[6]['value'];
        if (!is_numeric($rate) || !is_finite((float) $rate) || $this->cents((float) $rate * $stats['clears']) !== $this->cents($amount)) {
            throw new \RuntimeException('The frozen paid NSF rate and valid clears do not reconcile to its archived component.');
        }
    }

    private function nsfSummary(Worksheet $summary, array $assignment, float $amount, array $stats): array
    {
        ['assignments' => $assignments, 'actions' => $actions, 'clears' => $clears] = $stats;
        $rate = round($amount / $clears, 2);
        if (!is_finite($rate) || $this->cents($rate * $clears) !== $this->cents($amount)) throw new \RuntimeException('The frozen NSF amount does not reconcile to a monetary rate per clear.');
        $values = [$assignment['employeeName'], $assignments, $actions, $stats['ratio'], null, null, $rate, $clears, $amount, $assignment['location'], $assignment['company']];
        $row = [];
        foreach ($values as $index => $value) {
            $template = $summary->getCell($this->cell($index + 1, 2));
            $row[] = ['value' => $value, 'type' => $value === null ? DataType::TYPE_NULL : (is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING),
                'style' => clone $summary->getParent()->getCellXfByIndex($template->getXfIndex()), 'comment' => null];
        }
        foreach ([4, 5] as $index) {
            $comment = new \PhpOffice\PhpSpreadsheet\Comment;
            $comment->getText()->createTextRun('Historical tier was not retained in the priced archive. No repricing was performed.');
            $row[$index]['comment'] = $comment;
        }
        $comment = new \PhpOffice\PhpSpreadsheet\Comment;
        $comment->getText()->createTextRun('Rate reconciled from the original frozen commission amount and valid clears.');
        $row[6]['comment'] = $comment;
        // Employer company is display evidence, not commission scope. A verified
        // both-source assignment keeps that company and displays the variance.
        foreach ([9, 10] as $index) {
            $row[$index]['style']->getFill()->setFillType(Fill::FILL_NONE);
            $row[$index]['style']->getFont()->getColor()->setARGB('FF000000');
            $row[$index]['style']->getFont()->setBold(false);
        }
        if (CommissionCompanyMatch::mismatches($assignment['source'], $assignment['company'])) {
            $row[10]['style']->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF0000');
            $row[10]['style']->getFont()->getColor()->setARGB('FFFFFFFF');
            $row[10]['style']->getFont()->setBold(true);
        }
        return $row;
    }

    public static function frozenValidity(mixed $value): bool
    {
        if (in_array($value, [true, 1, 1.0, '1', 'TRUE', 'true'], true)) return true;
        if (in_array($value, [false, 0, 0.0, '0', 'FALSE', 'false'], true)) return false;
        if (is_string($value) && preg_match('/^[01]\.0+$/D', $value)) return (float) $value === 1.0;
        throw new \RuntimeException('Frozen NSF Valid Commission evidence is missing or invalid.');
    }

    private function writeSummary(Worksheet $sheet, string $family, array $paid, array $unassigned): void
    {
        $columns = count(self::HEADERS[$family]);
        $oldLast = $sheet->getHighestDataRow();
        foreach ($sheet->getMergeCells() as $range) {
            [$first, $last] = Coordinate::rangeBoundaries($range);
            if ($first[1] >= 2 && $last[0] <= $columns) $sheet->unmergeCells($range);
        }
        $comments = $sheet->getComments();
        for ($r = 2; $r <= max($oldLast, count($paid) + count($unassigned) + 3); $r++) for ($c = 1; $c <= $columns; $c++) {
            $coordinate = $this->cell($c, $r);
            $sheet->setCellValue($coordinate, null);
            unset($comments[$coordinate]);
        }
        $sheet->setComments($comments);
        $r = 2;
        foreach ($paid as $row) $this->writeRow($sheet, $r++, $row);
        if ($unassigned !== []) {
            $r++;
            $sheet->setCellValue('A' . $r, 'Unassigned Agents');
            $sheet->mergeCells('A' . $r . ':' . Coordinate::stringFromColumnIndex($columns) . $r);
            $sheet->getStyle('A' . $r)->getFont()->setBold(true);
            $r++;
            foreach ($unassigned as $row) $this->writeRow($sheet, $r++, $row);
        }
    }

    private function writeRow(Worksheet $sheet, int $r, array $row): void
    {
        foreach ($row as $index => $cell) {
            $coordinate = $this->cell($index + 1, $r);
            $sheet->setCellValueExplicit($coordinate, $cell['value'], $cell['type']);
            $sheet->duplicateStyle($cell['style'], $coordinate);
            if ($cell['comment'] !== null) {
                $comments = $sheet->getComments();
                $comments[$coordinate] = clone $cell['comment'];
                $sheet->setComments($comments);
            }
        }
    }

    private function normalizeZipTimes(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) throw new \RuntimeException('The staged amendment workbook is unreadable.');
        try {
            // ZIP entry timestamps are transport metadata, not new source evidence.
            for ($i = 0; $i < $zip->numFiles; $i++) if (!$zip->setMtimeIndex($i, 315532800)) throw new \RuntimeException('Cannot stabilize amendment workbook evidence.');
        } finally { $zip->close(); }
    }

    private function cents(mixed $amount): int
    {
        if (!is_numeric($amount) || !is_finite((float) $amount)) throw new \RuntimeException('Frozen commission requires a finite literal amount.');
        $scaled = (float) $amount * 100;
        if (!is_finite($scaled) || abs($scaled) >= PHP_INT_MAX) throw new \RuntimeException('Frozen commission exceeds the supported monetary range.');
        return (int) round($scaled);
    }

    private function agentKey(string $name): string { return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '', 'UTF-8'); }
    private function cell(int $column, int $row): string { return Coordinate::stringFromColumnIndex($column) . $row; }
}
