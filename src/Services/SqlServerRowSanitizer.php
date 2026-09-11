<?php

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pre-flight sanitiser for rows destined for a SQL Server table.
 *
 * Both SyncSettlementData and SyncEPFData follow a destructive pattern: DELETE every row
 * for a source, then INSERT the fresh set. If an INSERT fails partway through, the table
 * is left half-written with nothing signalling it (2026-09-10: one Forth record with
 * placeholder money values overflowed a DECIMAL(10,2) column and stranded
 * TblSettlementsNGF at 67,000 of 71,252 PLAW rows for two days).
 *
 * This class closes that gap by checking values against the table's ACTUAL column
 * metadata before anything destructive happens. Two levels of response:
 *
 *   - Per-row violations are repaired in place (strings truncated, out-of-range numbers
 *     nulled) and logged with the offending row's identifier. One bad record costs one
 *     field, not the remaining batches.
 *
 *   - If the proportion of affected rows exceeds a tolerance, assertWithinTolerance()
 *     throws BEFORE the DELETE. That volume means something structural changed — schema
 *     drift, a source format change — and quietly rewriting thousands of values would be
 *     worse than not running at all. The existing data survives untouched.
 *
 * Column widths are read from INFORMATION_SCHEMA at runtime rather than hardcoded, so a
 * future ALTER cannot silently desynchronise the validation from the table.
 */
class SqlServerRowSanitizer
{
    /**
     * Below this many affected rows we never abort, regardless of ratio. Stops a tiny
     * result set (where a single bad row is already >1%) from halting a healthy sync.
     */
    private const MIN_ROWS_BEFORE_ABORT = 10;

    /** @var array<string, array<string, mixed>> Upper-cased column name => metadata */
    private array $columns = [];

    private int $violations = 0;

    /** @var array<string, true> Row identifiers touched, for a distinct count. */
    private array $affectedRows = [];

    public function __construct(
        private readonly string $table,
        array $columns,
        private readonly string $label
    ) {
        foreach ($columns as $column) {
            $name = strtoupper((string) ($column['COLUMN_NAME'] ?? ''));
            if ($name !== '') {
                $this->columns[$name] = $column;
            }
        }
    }

    /**
     * Build a sanitiser by reading the live column metadata for $table.
     *
     * $label is only used to namespace log entries (e.g. 'SyncSettlementData').
     */
    public static function forTable(DBConnector $connector, string $table, string $label): self
    {
        $sql = "
            SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = '" . str_replace("'", "''", $table) . "'
        ";

        $result = $connector->querySqlServer($sql);

        if (!is_array($result) || ($result['success'] ?? null) !== true) {
            $error = is_array($result) ? ($result['error'] ?? 'unknown') : 'non-array response';
            throw new RuntimeException("Could not read column metadata for {$table}: {$error}");
        }

        $columns = $result['data'] ?? [];

        if (empty($columns)) {
            throw new RuntimeException("No column metadata found for {$table}.");
        }

        return new self($table, $columns, $label);
    }

    /**
     * Return $value coerced to fit $column, recording and logging any repair.
     *
     * $rowId is whatever identifies the row in logs (an LLG_ID here). Unknown columns are
     * passed through untouched — the caller decides which fields to check.
     *
     * @return mixed
     */
    public function clean(string $column, $value, string $rowId)
    {
        $meta = $this->columns[strtoupper($column)] ?? null;

        if ($meta === null || $value === null) {
            return $value;
        }

        $type = strtolower((string) ($meta['DATA_TYPE'] ?? ''));

        if (in_array($type, ['char', 'varchar', 'nchar', 'nvarchar', 'text', 'ntext'], true)) {
            return $this->cleanString($column, $value, $rowId, $meta);
        }

        if (in_array($type, ['decimal', 'numeric', 'money', 'smallmoney'], true)) {
            return $this->cleanDecimal($column, $value, $rowId, $meta);
        }

        return $value;
    }

    /**
     * Throw if too large a share of $totalRows needed repair.
     *
     * Call this AFTER cleaning every row and BEFORE any destructive statement.
     */
    public function assertWithinTolerance(int $totalRows, float $maxRatio = 0.01): void
    {
        $affected = count($this->affectedRows);

        if ($totalRows <= 0 || $affected < self::MIN_ROWS_BEFORE_ABORT) {
            return;
        }

        $ratio = $affected / $totalRows;

        if ($ratio <= $maxRatio) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s: %d of %d rows (%.2f%%) needed sanitising for %s, above the %.2f%% tolerance. '
                . 'Aborting before any delete — this usually means a schema or source-format change '
                . 'rather than bad individual records. Existing data left untouched.',
            $this->label,
            $affected,
            $totalRows,
            $ratio * 100,
            $this->table,
            $maxRatio * 100
        ));
    }

    public function violations(): int
    {
        return $this->violations;
    }

    public function affectedRowCount(): int
    {
        return count($this->affectedRows);
    }

    /** One-line summary suitable for console output. */
    public function summary(): string
    {
        return sprintf(
            '%d value(s) sanitised across %d row(s) for %s',
            $this->violations,
            count($this->affectedRows),
            $this->table
        );
    }

    // -------------------------------------------------------------------------

    private function cleanString(string $column, $value, string $rowId, array $meta): string
    {
        $string = (string) $value;
        $max = $meta['CHARACTER_MAXIMUM_LENGTH'] ?? null;

        // -1 means MAX (varchar(max) etc.), i.e. effectively unbounded.
        if ($max === null || (int) $max < 0) {
            return $string;
        }

        $max = (int) $max;

        if ($max === 0 || mb_strlen($string) <= $max) {
            return $string;
        }

        $this->record($rowId);

        Log::warning($this->label . ': value too long for column; truncating.', [
            'table'  => $this->table,
            'column' => $column,
            'row'    => $rowId,
            'length' => mb_strlen($string),
            'max'    => $max,
            'value'  => mb_substr($string, 0, 120),
        ]);

        return mb_substr($string, 0, $max);
    }

    /**
     * @return float|null
     */
    private function cleanDecimal(string $column, $value, string $rowId, array $meta)
    {
        if ($value === '') {
            return null;
        }

        $number = (float) $value;

        if (is_nan($number) || is_infinite($number)) {
            $this->record($rowId);

            Log::warning($this->label . ': non-finite value for numeric column; storing NULL.', [
                'table'  => $this->table,
                'column' => $column,
                'row'    => $rowId,
                'value'  => (string) $value,
            ]);

            return null;
        }

        $precision = (int) ($meta['NUMERIC_PRECISION'] ?? 0);
        $scale = (int) ($meta['NUMERIC_SCALE'] ?? 0);

        if ($precision <= 0) {
            return $number;
        }

        // DECIMAL(p,s) holds up to (p - s) integer digits, so the exclusive ceiling
        // is 10^(p-s). A value at or above that cannot be stored at any scale.
        $ceiling = 10 ** max(0, $precision - $scale);

        if (abs($number) < $ceiling) {
            return $number;
        }

        $this->record($rowId);

        Log::warning($this->label . ': value exceeds column capacity; storing NULL.', [
            'table'   => $this->table,
            'column'  => $column,
            'row'     => $rowId,
            'value'   => sprintf('%.2f', $number),
            'ceiling' => sprintf('%.2f', $ceiling),
        ]);

        return null;
    }

    private function record(string $rowId): void
    {
        $this->violations++;
        $this->affectedRows[$rowId] = true;
    }
}
