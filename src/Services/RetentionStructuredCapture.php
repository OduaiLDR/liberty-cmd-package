<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/** Private comparison evidence for the Regular Retention manager cohort. */
final class RetentionStructuredCapture
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    private const SOURCES = ['ldr' => 'LDR', 'plaw' => 'Progress Law'];
    private const ROW_FIELDS = ['ID', 'RETENTION_AGENT', 'IMMEDIATE_RESULTS', 'ENROLLED_DEBT',
        'CANCEL_REQUEST_DATE', 'RETENTION_PAYMENT_DATE'];
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
    ): array {
        $context = [
            'queryOnly' => true,
            'retentionStructuredCapture' => [
                'version' => 1,
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

    /** Decode both trusted archive contexts without opening their source workbooks. */
    public static function inputs(string $period, string $runId, array $artifacts): array
    {
        $periodEnd = self::periodEnd($period);
        self::requireFields($artifacts, ['retention_ldr', 'retention_plaw']);
        $rows = [];
        $captureId = null;
        foreach (self::SOURCES as $source => $display) {
            $key = 'retention_' . $source;
            $artifact = $artifacts[$key];
            if (!is_array($artifact) || ($artifact['key'] ?? null) !== $key || ($artifact['period'] ?? null) !== $period
                || ($artifact['identity'] ?? null) !== $runId || !self::hexId($artifact['sha256'] ?? null, 64)
                || !self::hexId($artifact['contextSha256'] ?? null, 64)
                || !is_array($artifact['context'] ?? null)) {
                self::invalid();
            }

            $context = $artifact['context'];
            if (!hash_equals($artifact['contextSha256'], hash('sha256', json_encode($context, self::JSON_FLAGS)))) {
                self::invalid();
            }
            $sourceRows = self::sourceRows($period, $periodEnd, $runId, $source, $context);
            $sourceCaptureId = $context['retentionStructuredCapture']['captureId'];
            if ($captureId !== null && $captureId !== $sourceCaptureId) {
                self::invalid();
            }
            $captureId = $sourceCaptureId;
            foreach ($sourceRows as $row) {
                $rows[] = [...$row, 'SOURCE' => $display];
            }
        }

        return ['periodEnd' => $periodEnd, 'rows' => $rows];
    }

    private static function sourceRows(string $period, string $periodEnd, string $runId, string $source, array $context): array
    {
        self::requireFields($context, ['queryOnly', 'retentionStructuredCapture', 'trancheContextReady', 'trancheByClient']);
        $capture = $context['retentionStructuredCapture'];
        $frozen = $context['trancheByClient'];
        if ($context['queryOnly'] !== true || $context['trancheContextReady'] !== true || !is_array($capture)
            || !is_array($frozen) || !isset(self::SOURCES[$source]) || !self::hexId($runId, 32)) {
            self::invalid();
        }

        self::requireFields($capture, ['version', 'source', 'period', 'runId', 'captureId', 'rows']);
        if ($capture['version'] !== 1 || $capture['source'] !== $source || $capture['period'] !== $period
            || $capture['runId'] !== $runId || $capture['captureId'] === $runId || !self::hexId($capture['captureId'], 32)
            || !is_array($capture['rows']) || !array_is_list($capture['rows']) || count($capture['rows']) > 99999
            || count($capture['rows']) !== count($frozen)) {
            self::invalid();
        }

        $rows = [];
        $seen = [];
        foreach ($capture['rows'] as $row) {
            if (!is_array($row)) {
                self::invalid();
            }
            self::requireFields($row, self::ROW_FIELDS);
            $id = $row['ID'];
            $agent = $row['RETENTION_AGENT'];
            $outcome = $row['IMMEDIATE_RESULTS'];
            $debt = $row['ENROLLED_DEBT'];
            $cancel = $row['CANCEL_REQUEST_DATE'];
            if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id) || $id !== (string) (int) $id || isset($seen[$id])
                || !self::sourceText($agent) || trim($agent) === '' || ($outcome !== null && !self::sourceText($outcome))
                || (!is_int($debt) && !is_float($debt)) || !is_finite((float) $debt) || $debt < 0 || $debt > 1e12
                || !self::date($cancel) || $cancel < $period || $cancel > $periodEnd
                || ($row['RETENTION_PAYMENT_DATE'] !== null && !self::date($row['RETENTION_PAYMENT_DATE']))) {
                self::invalid();
            }
            $seen[$id] = true;
            $tranche = $frozen['LLG-' . $id] ?? null;
            self::validateTranche($tranche, $outcome);
            $rows[] = [...$row, ...$tranche];
        }

        return $rows;
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
