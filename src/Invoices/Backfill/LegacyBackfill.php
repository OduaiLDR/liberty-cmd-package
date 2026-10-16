<?php

namespace Cmd\Reports\Invoices\Backfill;

use Cmd\Reports\Invoices\InvoiceObjectStore;
use Cmd\Reports\Invoices\InvoiceRecordWriter;
use Cmd\Reports\Invoices\InvoiceRegistryException;

/**
 * Turns scanner candidates into legacy records in the invoice bucket: {company}/{type}/{YYYY}/{MM}/{old number}/.
 * plan() writes nothing. apply() only ever creates (If-None-Match), so a second run imports nothing twice.
 */
final class LegacyBackfill
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array{number: string, folder: string, invoiceDate: string, amount: float|null, files: int, company: string, type: string}>
     */
    public function plan(array $candidates): array
    {
        return array_map(fn (array $c) => [
            'number' => $c['number'], 'company' => $c['company'], 'type' => $c['type'], 'invoiceDate' => $c['invoiceDate'],
            'amount' => $c['amount'], 'files' => count($c['files']), 'folder' => $this->folder($c),
        ], $candidates);
    }

    /**
     * Decides which candidates may be imported, BEFORE plan()/apply() (so a dry run and a real run agree).
     * A number is taken when it is already in the invoice bucket under a different folder (any company, type or month, found by
     * listing the bucket's record.json keys once) or already used by an earlier candidate in this scan (any source). Taken numbers
     * are skipped and reported, never imported twice and never renamed. A candidate whose own folder already holds a record is
     * not a collision: it passes through and apply() reports it as already imported.
     * Comparison is exact-case first; numbers that differ only by letter case also count as taken (the page opens numbers
     * case-exactly, but its search is case-insensitive, so two such numbers would be indistinguishable to a person).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{candidates: list<array<string, mixed>>, skipped: list<string>}
     *
     * @throws InvoiceRegistryException when the bucket cannot be listed: the caller must not import without the check
     */
    public function resolve(array $candidates, ?InvoiceObjectStore $store): array
    {
        $bucket = [];
        foreach ($store?->listKeys() ?? [] as $key) {
            $parts = explode('/', $key);
            if (count($parts) !== 6 || $parts[5] !== 'record.json' || str_starts_with($key, '_')) {
                continue;
            }
            $bucket[strtolower($parts[4])][] = ['number' => $parts[4], 'folder' => implode('/', array_slice($parts, 0, 5)) . '/'];
        }

        $kept = [];
        $skipped = [];
        $scan = [];
        foreach ($candidates as $c) {
            $number = (string) $c['number'];
            $folder = $this->folder($c);
            $lower = strtolower($number);
            if (isset($scan[$lower])) {
                $first = $scan[$lower];
                $skipped[] = $first['number'] === $number
                    ? "{$number}: already used by {$first['company']} in this scan; {$c['company']} copy not imported (numbers are never renamed). Needs a decision."
                    : "{$number}: differs only by letter case from {$first['number']} ({$first['company']}) in this scan; not imported (numbers are never renamed). Needs a decision.";
                continue;
            }
            $own = false;
            $other = null;
            foreach ($bucket[$lower] ?? [] as $hit) {
                if ($hit['folder'] === $folder) {
                    $own = true;
                } else {
                    $other ??= $hit;
                }
            }
            if (! $own && $other !== null) {
                $skipped[] = $other['number'] === $number
                    ? "{$number}: already in the invoice bucket at {$other['folder']}; not imported again (numbers are never renamed). Needs a decision."
                    : "{$number}: differs only by letter case from {$other['number']}, already in the invoice bucket at {$other['folder']}; not imported (numbers are never renamed). Needs a decision.";
                continue;
            }
            $scan[$lower] = ['number' => $number, 'company' => (string) $c['company']];
            $kept[] = $c;
        }

        return ['candidates' => $kept, 'skipped' => $skipped];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array{written: list<string>, existing: list<string>, failed: array<string, string>}
     */
    public function apply(InvoiceObjectStore $store, array $candidates): array
    {
        $writer = new InvoiceRecordWriter($store);
        $result = ['written' => [], 'existing' => [], 'failed' => []];
        foreach ($candidates as $c) {
            try {
                $writer->writeAt($this->folder($c), $c['company'], $c['type'], $c['number'], [
                    'amount' => $c['amount'],
                    'invoiceDate' => $c['invoiceDate'],
                    'status' => 'legacy',
                    'generator' => 'legacy-backfill',
                    'source' => 'legacy-import',
                    'period' => $c['period'],
                    'importedFrom' => $c['source'],
                ] + $c['fields'], $c['files']);
                $result['written'][] = $c['number'];
            } catch (InvoiceRegistryException $e) {
                str_contains($e->getMessage(), 'already has a record.json')
                    ? $result['existing'][] = $c['number']
                    : $result['failed'][$c['number']] = $e->getMessage();
            } catch (\Throwable $e) {
                $result['failed'][$c['number']] = $e->getMessage();
            }
        }

        return $result;
    }

    private function folder(array $c): string
    {
        [$year, $month] = explode('-', $c['invoiceDate']);

        return "{$c['company']}/{$c['type']}/{$year}/{$month}/{$c['number']}/";
    }
}
