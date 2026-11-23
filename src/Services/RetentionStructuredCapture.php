<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/** Frozen Regular Retention source rows. Version 1 is the query-only leadership comparison. */
final class RetentionStructuredCapture
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    private const SOURCES = ['ldr' => 'LDR', 'plaw' => 'Progress Law'];
    private const ROW_FIELDS = ['ID', 'RETENTION_AGENT', 'IMMEDIATE_RESULTS', 'ENROLLED_DEBT',
        'CANCEL_REQUEST_DATE', 'RETENTION_PAYMENT_DATE'];
    private const WORKBOOK_FIELDS = ['CLIENT', 'RETENTION_DATE', 'RECONSIDERATION_DATE', 'DROPPED_DATE',
        'CLEARED_PAYMENTS', 'T1', 'T2', 'T3', 'T4'];
    private const TRANCHE_FIELDS = ['TRANCHE', 'CUT_OFF', 'MADE_CUT_OFF', 'BONUS_FLAG'];

    public static function archive(): PayrollReportArtifactArchive
    {
        return new PayrollReportArtifactArchive(storage_path('app/payroll-retention-shadow'));
    }

    public static function periodEnd(string $period): string
    {
        if (!self::date($period) || !str_ends_with($period, '-01')) {
            throw new InvalidArgumentException('The Regular Retention shadow requires an exact month start (YYYY-MM-01).');
        }

        return (new DateTimeImmutable($period))->format('Y-m-t');
    }

    public static function context(
        string $source,
        string $period,
        string $runId,
        string $captureId,
        array $rows,
        array $trancheContext,
        bool $queryOnly = true,
    ): array {
        $context = ($queryOnly ? ['queryOnly' => true] : []) + [
            'retentionStructuredCapture' => [
                'version' => $queryOnly ? 1 : 2,
                'source' => $source,
                'period' => $period,
                'runId' => $runId,
                'captureId' => $captureId,
                'rows' => $rows,
            ],
            ...$trancheContext,
        ];
        self::sourceRows($period, self::periodEnd($period), $runId, $source, $context);

        return $context;
    }

    public static function payrollInputs(string $period, array $artifacts): ?array
    {
        $pair = array_intersect_key($artifacts, array_flip(['retention_ldr', 'retention_plaw']));
        if (count($pair) === 2 && !isset($pair['retention_ldr']['identity']) && !isset($pair['retention_plaw']['identity'])) {
            return null; // Prepared amendment files carry only paths and original context.
        }
        foreach ($pair as $artifact) {
            $context = $artifact['context'] ?? [];
            if (array_key_exists('retentionStructuredCapture', $context)
                && (($context['retentionStructuredCapture']['version'] ?? null) !== 1 || ($context['queryOnly'] ?? false) !== true)) {
                // Amendments render the original records through their own saved workbook.
                if (is_string($artifact['identity'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $artifact['identity'])
                    && ($pair['retention_ldr']['identity'] ?? null) === $artifact['identity']
                    && ($pair['retention_plaw']['identity'] ?? null) === $artifact['identity']) {
                    return null;
                }
                $inputs = self::inputs($period, $artifact['identity'] ?? '', $pair);
                // The current source labels these columns T1 Rate through T4 Rate.
                // Leadership's existing workbook loader leaves those detail cells blank.
                $inputs['rows'] = array_map(static fn (array $row): array =>
                    array_diff_key($row, ['T1' => true, 'T2' => true, 'T3' => true, 'T4' => true]), $inputs['rows']);
                return $inputs;
            }
        }
        return null;
    }

    /** Decode both trusted archive contexts without opening their source workbooks. */
    public static function inputs(string $period, string $runId, array $artifacts): array
    {
        $periodEnd = self::periodEnd($period);
        self::requireFields($artifacts, ['retention_ldr', 'retention_plaw']);
        $rows = $rowsBySource = [];
        $captureId = $version = null;
        foreach (self::SOURCES as $source => $display) {
            $key = 'retention_' . $source;
            $artifact = $artifacts[$key];
            if (!is_array($artifact)) {
                self::invalid();
            }

            $sourceRows = self::verifiedRows($artifact, $period, $runId, $source);
            $rowsBySource[$source] = $sourceRows;
            $context = $artifact['context'];
            $sourceCaptureId = $context['retentionStructuredCapture']['captureId'];
            $sourceVersion = $context['retentionStructuredCapture']['version'];
            if ($captureId !== null && ($captureId !== $sourceCaptureId || $version !== $sourceVersion)) {
                self::invalid();
            }
            $captureId = $sourceCaptureId;
            $version = $sourceVersion;
            foreach ($sourceRows as $row) {
                $rows[] = [...$row, 'SOURCE' => $display];
            }
        }

        return ['periodEnd' => $periodEnd, 'rows' => $rows, 'rowsBySource' => $rowsBySource];
    }

    private static function verifiedRows(array $artifact, string $period, string $runId, string $source): array
    {
        $context = $artifact['context'] ?? null;
        if (($artifact['key'] ?? null) !== 'retention_' . $source || ($artifact['period'] ?? null) !== $period
            || ($artifact['identity'] ?? null) !== $runId || !self::hexId($artifact['sha256'] ?? null, 64)
            || !self::hexId($artifact['contextSha256'] ?? null, 64) || !is_array($context)
            || !hash_equals($artifact['contextSha256'], hash('sha256', json_encode($context, self::JSON_FLAGS)))) {
            self::invalid();
        }
        return self::sourceRows($period, self::periodEnd($period), $runId, $source, $context);
    }

    private static function sourceRows(string $period, string $periodEnd, string $runId, string $source, array $context): array
    {
        $capture = $context['retentionStructuredCapture'] ?? null;
        $frozen = $context['trancheByClient'] ?? null;
        if (($context['trancheContextReady'] ?? null) !== true || !is_array($capture)
            || !is_array($frozen) || !isset(self::SOURCES[$source]) || !self::hexId($runId, 32)) {
            self::invalid();
        }

        self::requireFields($capture, ['version', 'source', 'period', 'runId', 'captureId', 'rows']);
        $payroll = $capture['version'] === 2;
        if (!$payroll) {
            self::requireFields($context, ['queryOnly', 'retentionStructuredCapture', 'trancheContextReady', 'trancheByClient']);
        }
        if ((!$payroll && $capture['version'] !== 1) || ($context['queryOnly'] ?? false) !== !$payroll
            || $capture['source'] !== $source || $capture['period'] !== $period
            || $capture['runId'] !== $runId || $capture['captureId'] === $runId || !self::hexId($capture['captureId'], 32)
            || !is_array($capture['rows']) || !array_is_list($capture['rows']) || count($capture['rows']) > 99999
            || count($capture['rows']) !== count($frozen)) {
            self::invalid();
        }

        $rows = [];
        $seen = [];
        $rowFields = $payroll ? [...self::ROW_FIELDS, ...self::WORKBOOK_FIELDS] : self::ROW_FIELDS;
        foreach ($capture['rows'] as $row) {
            if (!is_array($row)) {
                self::invalid();
            }
            self::requireFields($row, $rowFields);
            $id = $row['ID'];
            $agent = $row['RETENTION_AGENT'];
            $outcome = $row['IMMEDIATE_RESULTS'];
            $debt = $row['ENROLLED_DEBT'];
            $cancel = $row['CANCEL_REQUEST_DATE'];
            if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id) || $id !== (string) (int) $id || isset($seen[$id])
                || !self::sourceText($agent) || (!$payroll && trim($agent) === '') || ($outcome !== null && !self::sourceText($outcome))
                || (!is_int($debt) && !is_float($debt)) || !is_finite((float) $debt) || $debt < 0 || $debt > 1e12
                || (!$payroll && (!self::date($cancel) || $cancel < $period || $cancel > $periodEnd))
                || ($payroll && $cancel !== null && !self::date($cancel))
                || ($row['RETENTION_PAYMENT_DATE'] !== null && !self::date($row['RETENTION_PAYMENT_DATE']))) {
                self::invalid();
            }
            if ($payroll) {
                self::validateWorkbookFields($row);
            }
            $seen[$id] = true;
            $tranche = $frozen['LLG-' . $id] ?? null;
            self::validateTranche($tranche, $outcome);
            $rows[] = [...$row, ...$tranche];
        }

        return $rows;
    }

    private static function validateWorkbookFields(array $row): void
    {
        if (($row['CLIENT'] !== null && !is_string($row['CLIENT']))
            || !is_int($row['CLEARED_PAYMENTS']) || $row['CLEARED_PAYMENTS'] < 0) {
            self::invalid();
        }
        foreach (['RETENTION_DATE', 'RECONSIDERATION_DATE', 'DROPPED_DATE'] as $field) {
            if ($row[$field] !== null && !self::date($row[$field])) self::invalid();
        }
        foreach (['T1', 'T2', 'T3', 'T4'] as $field) {
            $value = $row[$field];
            if ($value !== null && ((!is_int($value) && !is_float($value)) || !is_finite((float) $value))) self::invalid();
        }
    }

    private static function validateTranche(mixed $tranche, ?string $outcome): void
    {
        if (!is_array($tranche)) {
            self::invalid();
        }
        self::requireFields($tranche, self::TRANCHE_FIELDS);
        if (!is_string($tranche['TRANCHE']) || !is_bool($tranche['MADE_CUT_OFF'])
            || ($tranche['CUT_OFF'] !== null && !self::date($tranche['CUT_OFF']))
            || ($tranche['MADE_CUT_OFF'] && $tranche['CUT_OFF'] === null)) {
            self::invalid();
        }
        $bonus = $tranche['MADE_CUT_OFF'] ? (strtoupper((string) $outcome) === 'RETAINED' ? 'Bonus' : 'No Bonus') : '';
        if ($tranche['BONUS_FLAG'] !== $bonus) {
            self::invalid();
        }
    }

    private static function sourceText(mixed $value): bool
    {
        return is_string($value) && !in_array(DefaultValueBinder::dataTypeForValue($value),
            [DataType::TYPE_FORMULA, DataType::TYPE_NUMERIC, DataType::TYPE_ERROR], true);
    }

    private static function date(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function hexId(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && preg_match('/^[a-f0-9]+$/D', $value);
    }

    private static function requireFields(array $value, array $fields): void
    {
        if (count($value) !== count($fields) || array_diff($fields, array_keys($value))) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('The Regular Retention structured shadow is incomplete, invalid or outside its capture scope.');
    }
}
