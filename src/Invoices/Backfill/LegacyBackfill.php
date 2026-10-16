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
