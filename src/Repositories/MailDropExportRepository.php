<?php

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class MailDropExportRepository extends SqlSrvRepository
{
    /**
     * All drops from TblMarketing for the selector table.
     *
     * Sort order per spec:
     *   1. NULL Latest_Export_Date first, sorted Send_Date DESC
     *   2. Non-null Latest_Export_Date, sorted Latest_Export_Date ASC
     *   3. Drop_Name ASC as final tiebreaker
     */
    public function allDrops(): Collection
    {
        $latestExportDateSelect = $this->enrichedLatestExportDateColumnExists()
            ? 'MAX(e.Latest_Export_Date)'
            : 'CAST(NULL AS DATE)';

        $sql = "
            WITH DropStats AS (
                SELECT
                    m.PK,
                    m.Drop_Name,
                    m.Send_Date,
                    SUM(
                        CASE
                            WHEN NULLIF(LTRIM(RTRIM(e.Phone)), '') IS NULL THEN 0
                            ELSE 1
                        END
                    ) AS Amount_Dropped,
                    {$latestExportDateSelect} AS Latest_Export_Date
                FROM TblMarketing m
                INNER JOIN TblMailersUniqueEnriched e
                    ON e.Drop_Name = m.Drop_Name
                GROUP BY
                    m.PK,
                    m.Drop_Name,
                    m.Send_Date
            )
            SELECT
                PK,
                Drop_Name,
                Send_Date,
                Amount_Dropped,
                Latest_Export_Date
            FROM DropStats
            ORDER BY
                CASE WHEN Latest_Export_Date IS NULL THEN 0 ELSE 1 END ASC,
                CASE WHEN Latest_Export_Date IS NULL THEN Send_Date ELSE NULL END DESC,
                Latest_Export_Date ASC,
                Drop_Name ASC
        ";

        return collect($this->connection()->select($sql));
    }

    private function enrichedLatestExportDateColumnExists(): bool
    {
        return (bool) $this->connection()->selectOne(
            "SELECT 1 AS present FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_NAME = 'TblMailersUniqueEnriched' AND COLUMN_NAME = 'Latest_Export_Date'"
        );
    }

    /**
     * @param  array<int>  $pks
     * @return array<string>
     */
    private function dropNamesForPks(array $pks): array
    {
        $pks = array_values(array_unique(array_filter(
            array_map('intval', $pks),
            static fn (int $pk): bool => $pk > 0
        )));

        if (empty($pks)) {
            return [];
        }

        $dropNames = [];

        foreach (array_chunk($pks, 1000) as $chunk) {
            $this->table('TblMarketing')
                ->whereIn('PK', $chunk)
                ->pluck('Drop_Name')
                ->each(function ($dropName) use (&$dropNames): void {
                    if ($dropName !== null && $dropName !== '') {
                        $dropNames[(string) $dropName] = (string) $dropName;
                    }
                });
        }

        return array_values($dropNames);
    }

    /**
     * Count the exact CSV rows that will be emitted: one row per non-empty phone column.
     *
     * @param  array<int>  $pks  TblMarketing PKs
     */
    public function countExportRows(array $pks): int
    {
        $dropNames = $this->dropNamesForPks($pks);

        if (empty($dropNames)) {
            return 0;
        }

        $total = 0;

        foreach (array_chunk($dropNames, 200) as $chunk) {
            $row = $this->connection()->table('TblMailersUniqueEnriched')
                ->whereIn('Drop_Name', $chunk)
                ->selectRaw("
                    SUM(
                        CASE WHEN NULLIF(LTRIM(RTRIM(Phone)), '') IS NULL THEN 0 ELSE 1 END
                    ) AS Export_Row_Count
                ")
                ->first();

            $total += (int) ($row->Export_Row_Count ?? 0);
        }

        return $total;
    }

    /**
     * Export rows from enriched mailer data: one row per non-empty phone.
     *
     * @param  array<int>  $pks  TblMarketing PKs
     */
    public function exportRows(array $pks): \Generator
    {
        if (empty($pks)) {
            return (function () { yield from []; })();
        }

        $marketingDrops = $this->connection()->table('TblMarketing')
            ->whereIn('PK', $pks)
            ->get(['PK as Drop_PK', 'Drop_Name', 'Send_Date']);

        if ($marketingDrops->isEmpty()) {
            return (function () { yield from []; })();
        }

        foreach ($marketingDrops as $drop) {
            $lastId = 0;
            $batchSize = 10000;

            while (true) {
                $records = $this->connection()->table('TblMailersUniqueEnriched')
                    ->select('PK', 'Client', 'External_ID', 'Address', 'City', 'State', 'Zip', 'Phone', 'Email')
                    ->where('Drop_Name', $drop->Drop_Name)
                    ->where('PK', '>', $lastId)
                    ->whereRaw("NULLIF(LTRIM(RTRIM(Phone)), '') IS NOT NULL")
                    ->orderBy('PK')
                    ->limit($batchSize)
                    ->get();
                    
                if ($records->isEmpty()) {
                    break;
                }

                foreach ($records as $row) {
                    $lastId = $row->PK;

                    yield (object) [
                        'Drop_PK' => $drop->Drop_PK,
                        'Drop_Name' => $drop->Drop_Name,
                        'Client' => $row->Client,
                        'External_ID' => $row->External_ID,
                        'Phone' => trim((string) $row->Phone),
                        'Email' => $row->Email,
                        'Address' => $row->Address,
                        'City' => $row->City,
                        'State' => $row->State,
                        'Zip' => $row->Zip,
                        'Send_Date' => $drop->Send_Date,
                    ];
                }
            }
        }
    }

    /**
     * Stamp Latest_Export_Date for exported enriched mailer rows.
     *
     * @param  array<int>  $pks
     */
    public function logExport(array $pks): Carbon
    {
        $exportedAt = Carbon::today();

        if (! $this->enrichedLatestExportDateColumnExists()) {
            return $exportedAt;
        }

        $dropNames = $this->dropNamesForPks($pks);

        foreach (array_chunk($dropNames, 200) as $chunk) {
            $this->table('TblMailersUniqueEnriched')
                ->whereIn('Drop_Name', $chunk)
                ->update(['Latest_Export_Date' => $exportedAt->toDateString()]);
        }

        return $exportedAt;
    }
}
