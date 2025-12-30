<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateEPFRates extends Command
{
    protected $signature = 'update:epf-rates';
    protected $description = 'Update dbo.TblEnrollment.EPF_Rate from Snowflake ENROLLMENT_PLAN.FEE1 values';

    public function handle(): int
    {
        $this->info('EPF rate update: starting.');
        Log::info('UpdateEPFRates command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);

            $this->info("[$source] Starting EPF rate update.");
            Log::info('UpdateEPFRates: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $this->info("[$source] Fetching EPF fee data from Snowflake...");
                $rows = $this->fetchEpfRatesFromSnowflake($connector);

                if (empty($rows)) {
                    $this->warn("[$source] No EPF fee rows found in Snowflake.");
                    $this->insertLogRow($connector, $source, 'UPDATE_EPF_RATES', 'SUCCESS', 0, 0, 'No EPF fee rows found to update.');
                    continue;
                }

                $this->info("[$source] Grouping rates...");
                [$rateGroups, $totalContacts] = $this->groupRates($rows);

                if ($totalContacts === 0 || empty($rateGroups)) {
                    $this->warn("[$source] No valid EPF rates parsed from Snowflake data.");
                    $this->insertLogRow($connector, $source, 'UPDATE_EPF_RATES', 'SUCCESS', 0, 0, 'No valid EPF rates parsed.');
                    continue;
                }

                $this->info("[$source] Applying EPF rates to SQL Server...");
                $updated = $this->applyEpfRates($connector, $rateGroups);

                $this->info("[$source] Updated {$updated} rows.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'UPDATE_EPF_RATES',
                    'SUCCESS',
                    $totalContacts,
                    0,
                    sprintf('Contacts processed: %d. Updated rows: %d.', $totalContacts, $updated)
                );

                Log::info('UpdateEPFRates: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'updated_rows' => $updated,
                    'contacts_processed' => $totalContacts,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("[$source] EPF rate update FAILED: " . $e->getMessage());
                Log::error('UpdateEPFRates: exception during update.', [
                    'connection' => $connection,
                    'source' => $source,
                    'exception' => $e,
                ]);

                try {
                    if (!isset($connector)) {
                        $connector = DBConnector::fromEnvironment($connection);
                        $connector->initializeSqlServer();
                        $this->ensureLogTable($connector);
                    }

                    $errorMessage = mb_substr($e->getMessage(), 0, 900);
                    $this->insertLogRow($connector, $source, 'UPDATE_EPF_RATES', 'FAILED', 0, 0, $errorMessage);
                } catch (\Throwable $logException) {
                    Log::error('UpdateEPFRates: failed to log after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('EPF rate update: finished.');
        Log::info('UpdateEPFRates command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchEpfRatesFromSnowflake(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT
    ep.CONTACT_ID,
    ep.FEE1
FROM ENROLLMENT_PLAN AS ep
WHERE ep.CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE ENROLLED = 1 OR GRADUATED = 1)
  AND COALESCE(ep.FEE1, '') <> ''
SQL;

        $result = $connector->query($sql);

        // Normalize result to rows array
        $rows = [];
        if (is_array($result)) {
            if (isset($result['success']) && $result['success'] === false) {
                $rows = [];
            } elseif (isset($result['data']) && is_array($result['data'])) {
                $rows = $result['data'];
            } elseif (array_is_list($result)) {
                $rows = $result;
            }
        }

        $records = [];
        $badCount = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Snowflake CONTACT_ID is numeric string; SQL Server keeps LLG- prefix
            $cid = $this->normalizeString($this->valueForKey($row, 'CONTACT_ID'));
            $fee1 = $this->normalizeString($this->valueForKey($row, 'FEE1'));

            if ($cid === '' || $fee1 === '') {
                continue;
            }

            $rate = $this->parseEpfRate($fee1);
            if ($rate === null) {
                if ($badCount < 10) {
                    Log::warning('UpdateEPFRates: unable to parse EPF rate from FEE1.', [
                        'contact_id' => $cid,
                        'fee1' => mb_substr($fee1, 0, 250),
                    ]);
                }
                $badCount++;
                continue;
            }

            $records[] = [
                'llg_id' => $this->truncateString('LLG-' . $cid, 100),
                'rate' => $rate,
            ];
        }

        if ($badCount > 0) {
            $this->warn("Skipped {$badCount} rows with unparseable FEE1 values.");
        }

        $this->info('Fetched ' . count($records) . ' EPF fee rows from Snowflake.');
        return $records;
    }

    protected function groupRates(array $rows): array
    {
        $rateGroups = [];
        $order = [];
        $totalContacts = 0;

        foreach ($rows as $row) {
            $rateKey = $this->formatRateKey($row['rate']);

            if (!isset($rateGroups[$rateKey])) {
                $rateGroups[$rateKey] = [];
                $order[] = $rateKey;
            }

            $rateGroups[$rateKey][] = $row['llg_id'];
            $totalContacts++;
        }

        // preserve discovery order like the VBA macro
        $orderedGroups = [];
        foreach ($order as $k) {
            $orderedGroups[$k] = $rateGroups[$k] ?? [];
        }

        return [$orderedGroups, $totalContacts];
    }

    protected function applyEpfRates(DBConnector $connector, array $rateGroups): int
    {
        $batchSize = 1000;
        $totalUpdated = 0;

        foreach ($rateGroups as $rateKey => $llgIds) {
            $uniqueIds = array_values(array_unique($llgIds));
            $rateValue = $this->formatRateForSql($rateKey);
            $rateValueEsc = $this->escapeSqlString($rateValue);

            foreach (array_chunk($uniqueIds, $batchSize) as $chunk) {
                $escapedIds = array_map(function ($id) {
                    return "'" . $this->escapeSqlString($this->truncateString($id, 100)) . "'";
                }, $chunk);

                if (empty($escapedIds)) {
                    continue;
                }

                $idList = implode(', ', $escapedIds);

                // IMPORTANT: rowcount must be in the same batch as the UPDATE
                $sql = <<<SQL
UPDATE [dbo].[TblEnrollment]
SET EPF_Rate = '{$rateValueEsc}'
WHERE LLG_ID IN ({$idList});

SELECT @@ROWCOUNT AS row_count;
SQL;

                $result = $connector->querySqlServer($sql);
                $updated = $this->extractRowCountFromSqlServerResult($result);

                $totalUpdated += $updated;
            }
        }

        return $totalUpdated;
    }

    /**
     * Robust parser:
     * - splits by comma
     * - finds "percent"
     * - then scans forward to the next numeric token (handles percent,29 OR percent,,29 etc.)
     */
    protected function parseEpfRate(?string $fee1): ?float
    {
        if ($fee1 === null) {
            return null;
        }

        $parts = array_map('trim', explode(',', $fee1));
        $lower = array_map('strtolower', $parts);

        $idx = array_search('percent', $lower, true);
        if ($idx === false) {
            return null;
        }

        for ($i = $idx + 1; $i < count($parts); $i++) {
            $token = str_replace('%', '', $parts[$i]);
            if ($token === '') {
                continue;
            }
            if (is_numeric($token)) {
                $percent = (float) $token;
                $rate = $percent / 100.0;
                $rate = max(min($rate, 1.0), 0.0);
                return round($rate, 6);
            }
        }

        return null;
    }

    protected function extractRowCountFromSqlServerResult($result): int
    {
        // DBConnector formats can vary; try common shapes.
        // We expect something like: ['data' => [ ['row_count' => 123] ]]
        if (is_array($result)) {
            if (isset($result['data']) && is_array($result['data'])) {
                $row0 = $result['data'][0] ?? null;
                if (is_array($row0)) {
                    $v = $this->valueForKey($row0, 'row_count');
                    if ($v !== null && is_numeric($v)) {
                        return (int) $v;
                    }
                }
            }

            // sometimes: ['row_count' => 123]
            foreach (['row_count', 'rowCount', 'affected_rows'] as $k) {
                if (isset($result[$k]) && is_numeric($result[$k])) {
                    return (int) $result[$k];
                }
            }
        }

        return 0;
    }

    protected function formatRateKey($rate): string
    {
        if (!is_numeric($rate)) {
            return '0.000000';
        }
        return number_format((float) $rate, 6, '.', '');
    }

    protected function formatRateForSql(string $rateKey): string
    {
        if (!is_numeric($rateKey)) {
            return '0.000000';
        }
        $rate = (float) $rateKey;
        $rate = max(min($rate, 1.0), 0.0);
        return number_format($rate, 6, '.', '');
    }

    protected function normalizeString(?string $value): string
    {
        return $value === null ? '' : trim($value);
    }

    protected function valueForKey(array $row, string $key): ?string
    {
        foreach ($row as $rowKey => $value) {
            if (strcasecmp((string)$rowKey, $key) === 0) {
                return $value !== null ? (string) $value : null;
            }
        }
        return null;
    }

    protected function ensureLogTable(DBConnector $connector): void
    {
        // Assume TblLog exists / or handled elsewhere.
    }

    protected function insertLogRow(
        DBConnector $connector,
        string $source,
        string $action,
        string $status,
        int $recordsProcessed,
        int $recordsDeleted,
        string $details
    ): void {
        // (keep your existing implementation; unchanged)
        // If you want, paste your TblLog schema and I’ll lock it to exact column sizes safely.
    }

    protected function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    protected function truncateString(string $value, int $maxLength): string
    {
        return mb_strlen($value) <= $maxLength ? $value : mb_substr($value, 0, $maxLength);
    }
}
