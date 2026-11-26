<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Brick\Math\BigDecimal;
use Closure;
use Generator;
use RuntimeException;

final class ProspectExportInspector
{
    public const ROW_LIMIT = 2_000;
    public const PREVIEW_ROW_LIMIT = 250;

    /**
     * @param resource $stream
     * @param list<string> $columns
     * @param array{operator: string, value?: string, minimum?: string|null, maximum?: string|null} $filter
     */
    public function inspect($stream, array $columns, int $position, string $type, array $filter, int $fileRows): array
    {
        return $this->inspectFiles([$stream], $columns, $position, $type, $filter, $fileRows);
    }

    /** @param iterable<resource> $streams */
    public function inspectFiles(iterable $streams, array $columns, int $position, string $type, array $filter, int $fileRows): array
    {
        $headers = [];
        $rows = $this->csvRows($streams, count($columns), min(self::ROW_LIMIT, $fileRows), $headers);
        $rows->rewind();

        return $this->summarize($rows, $columns, $headers, $position, $type, $filter, $fileRows);
    }

    public function preview(iterable $prospects, array $columns, int $position, array $filter, int $plannedRows): array
    {
        $definitions = ProspectListFields::columns();
        $headers = array_map(ProspectListFields::csvHeader(...), $columns);
        $rows = (static function () use ($prospects, $columns, $definitions): Generator {
            foreach ($prospects as $prospect) {
                yield array_map(static fn (string $field): string => (string) ProspectListFields::csvCell($prospect, $field, $definitions[$field][3]), $columns);
            }
        })();

        return $this->summarize($rows, $columns, $headers, $position, $definitions[$columns[$position]][3], $filter, $plannedRows);
    }

    /** @param iterable<resource> $streams */
    private function csvRows(iterable $streams, int $columnCount, int $limit, array &$headers): Generator
    {
        $deadline = hrtime(true) + 5_000_000_000;
        $scanned = 0;
        foreach ($streams as $stream) {
            $partHeaders = fgetcsv($stream, escape: '');
            if ($partHeaders === false || count($partHeaders) !== $columnCount || ($headers !== [] && $headers !== $partHeaders)) {
                throw new RuntimeException('The saved CSV does not match its column list.');
            }
            $headers = $partHeaders;
            while ($scanned < $limit && hrtime(true) < $deadline && ($row = fgetcsv($stream, escape: '')) !== false) {
                if (count($row) !== $columnCount) {
                    throw new RuntimeException('The saved CSV has a row with an unexpected number of columns.');
                }
                $scanned++;
                yield $row;
            }
            if ($scanned >= $limit || hrtime(true) >= $deadline) {
                return;
            }
        }
    }

    private function summarize(iterable $rows, array $columns, array $headers, int $position, string $type, array $filter, int $fileRows): array
    {
        $matches = $this->matcher($type, $filter);
        $scanned = 0;
        $matched = 0;
        $blank = 0;
        $frequencies = [];
        $examples = [];
        foreach ($rows as $row) {
            $scanned++;
            $value = trim((string) $row[$position]);
            if (! $matches($value)) {
                continue;
            }

            $matched++;
            if ($value === '') {
                $blank++;
            } else {
                $key = mb_strtolower($value);
                $frequencies[$key] ??= ['value' => $value, 'count' => 0];
                $frequencies[$key]['count']++;
            }
            if (count($examples) < 5) {
                $examples[] = ['number' => $scanned, 'values' => array_map(static fn (?string $cell): string => $cell ?? '', $row)];
            }
        }

        usort($frequencies, static fn (array $first, array $second): int => $second['count'] <=> $first['count']);

        return [
            'columns' => array_map(static fn (string $id, string $label): array => ['id' => $id, 'label' => $label], $columns, $headers),
            'file_rows' => $fileRows,
            'scanned_rows' => $scanned,
            'matching_rows' => $matched,
            'blank_rows' => $blank,
            'top_values' => array_slice($frequencies, 0, 8),
            'examples' => $examples,
        ];
    }

    /** @param array{operator: string, value?: string, minimum?: string|null, maximum?: string|null} $filter */
    private function matcher(string $type, array $filter): Closure
    {
        return match ($filter['operator']) {
            'all' => static fn (string $value): bool => true,
            'present' => static fn (string $value): bool => $value !== '',
            'missing' => static fn (string $value): bool => $value === '',
            'equals' => static fn (string $value): bool => mb_strtolower($value) === mb_strtolower($filter['value']),
            'contains' => static fn (string $value): bool => mb_stripos($value, $filter['value']) !== false,
            'range' => $this->rangeMatcher($type, $filter['minimum'] ?? null, $filter['maximum'] ?? null),
        };
    }

    private function rangeMatcher(string $type, ?string $minimum, ?string $maximum): Closure
    {
        if ($type === 'date') {
            return static function (string $value) use ($minimum, $maximum): bool {
                $date = substr($value, 0, 10);

                return preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1
                    && ($minimum === null || $date >= $minimum) && ($maximum === null || $date <= $maximum);
            };
        }

        $lower = $minimum === null ? null : BigDecimal::of($minimum);
        $upper = $maximum === null ? null : BigDecimal::of($maximum);

        return static function (string $value) use ($lower, $upper): bool {
            if (! is_numeric($value)) {
                return false;
            }
            $number = BigDecimal::of($value);

            return ($lower === null || $number->compareTo($lower) >= 0)
                && ($upper === null || $number->compareTo($upper) <= 0);
        };
    }
}
