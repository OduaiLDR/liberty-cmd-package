<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/**
 * Single pipeline for retention commission / manager "All Data" rows (Jacob: snapshot from retention reports).
 */
class RetentionCommissionReportBuilder
{
    public const SOURCE_CONFIG = [
        'ldr' => [
            'display'               => 'LDR',
            'custom_agent'          => 742096,
            'custom_date'           => 742101,
            'custom_results'        => 742105,
            'recon_status_id'       => 377650,
            'cancel_request_custom' => 742098,
            'has_t4'                => true,
            'agents' => [
                'Alice Kennedy', 'Andrea Mendoza', 'Gracia Rivera', 'Javier Deras',
                'John Pozuelos', 'Jose Melgar', 'Ken Smith', 'Marco Gonzalez',
                'Mike Wexford', 'Rick Mills',
            ],
            'excluded_agents' => ['WENDY KAZEM'],
        ],
        'plaw' => [
            'display'               => 'Progress Law',
            'custom_agent'          => 742097,
            'custom_date'           => 742102,
            'custom_results'        => 742106,
            'recon_status_id'       => 377687,
            'cancel_request_custom' => 742100,
            'has_t4'                => true,
            'agents' => [
                'Alexander Malone', 'Andrea Galvez', 'Edgar Gonzalez', 'Maria Lezana',
                'Melody Martinez', 'Nick Jones', 'Theo Clayton', 'Tony Walker',
                'Vicente Gonzalez', 'Alfred Brown',
            ],
            'excluded_agents' => [],
        ],
    ];

    private const TIERS = [
        ['max' => 15000,       't1' => 2,  't2' => 5,  't3' => 10, 't4' => 40],
        ['max' => 30000,       't1' => 5,  't2' => 10, 't3' => 20, 't4' => 60],
        ['max' => 60000,       't1' => 15, 't2' => 30, 't3' => 40, 't4' => 80],
        ['max' => 100000,      't1' => 20, 't2' => 40, 't3' => 60, 't4' => 100],
        ['max' => PHP_INT_MAX, 't1' => 20, 't2' => 40, 't3' => 60, 't4' => 150],
    ];

    /** @param callable(string):void|null $log */
    public function buildSourceRows(string $source, ?callable $log = null): array
    {
        $cfg = self::SOURCE_CONFIG[$source] ?? null;
        if ($cfg === null) {
            throw new \InvalidArgumentException("Unknown retention source: {$source}");
        }

        $display = (string) $cfg['display'];
        $log ??= static function (): void {};
        $log("[INFO] Retention pipeline – {$display}");

        $sf = DBConnector::fromEnvironment($source);
        $rows = $this->fetchBase($sf, $cfg);

        foreach ($rows as &$row) {
            $agent = strtoupper((string) $this->col($row, 'RETENTION_AGENT', ''));
            if ($agent === 'ANDREA MENDOZE') {
                $row['RETENTION_AGENT'] = 'ANDREA MENDOZA';
            }
            $row['SOURCE'] = $display;
        }
        unset($row);

        if ($rows === []) {
            $log("[INFO] {$display} base rows: 0");
            return [];
        }

        $ids = array_filter(array_map(fn (array $r): int => (int) $this->col($r, 'ID', 0), $rows));
        $idList = empty($ids) ? '0' : implode(',', $ids);

        $reconMap = $this->fetchReconsiderationDates($sf, (int) $cfg['recon_status_id'], $idList);
        foreach ($rows as &$row) {
            $id = (string) $this->col($row, 'ID', '');
            if (!empty($reconMap[$id])) {
                $row['RECONSIDERATION_DATE'] = $reconMap[$id];
            } else {
                $dates = array_filter([
                    $this->toDate($this->col($row, 'RETENTION_DATE')),
                    $this->toDate($this->col($row, 'DROPPED_DATE')),
                ]);
                $row['RECONSIDERATION_DATE'] = $dates ? min($dates) : null;
            }
        }
        unset($row);

        $allTxMap = $this->fetchFirstClearedPerContact($sf, $idList);
        foreach ($rows as &$row) {
            $id = (string) $this->col($row, 'ID', '');
            $recon = $this->toDate($row['RECONSIDERATION_DATE'] ?? null);
            $count = 0;
            if ($recon && !empty($allTxMap[$id])) {
                foreach ($allTxMap[$id] as $d) {
                    if ($d < $recon) {
                        $count++;
                    }
                }
            }
            $row['CLEARED_PAYMENTS'] = $count;
        }
        unset($row);

        $retainedMap = $this->fetchRetainedDates($sf, $idList);
        foreach ($rows as &$row) {
            $recon = $this->toDate($row['RECONSIDERATION_DATE'] ?? null);
            $row['RETAINED_DATE'] = null;
            $id = (string) $this->col($row, 'ID', '');
            if ($recon && !empty($retainedMap[$id])) {
                foreach ($retainedMap[$id] as $rd) {
                    if ($rd >= $recon) {
                        $row['RETAINED_DATE'] = $rd;
                        break;
                    }
                }
            }
        }
        unset($row);

        foreach ($rows as &$row) {
            $row['RETENTION_PAYMENT_DATE'] = null;
            $row['T1'] = null;
            $row['T2'] = null;
            $row['T3'] = null;
            $row['T4'] = null;

            $recon = $this->toDate($row['RECONSIDERATION_DATE'] ?? null);
            $retained = $this->toDate($row['RETAINED_DATE'] ?? null);
            if ($recon === null || $retained === null) {
                continue;
            }

            $id = (string) $this->col($row, 'ID', '');
            $firstTx = null;
            foreach ($allTxMap[$id] ?? [] as $txDate) {
                if ($txDate >= $recon) {
                    $firstTx = $txDate;
                    break;
                }
            }
            if ($firstTx === null) {
                continue;
            }

            $dropped = $this->toDate($this->col($row, 'DROPPED_DATE'));
            if ($dropped !== null && $firstTx >= $dropped) {
                continue;
            }

            $row['RETENTION_PAYMENT_DATE'] = $firstTx;
            $debt = (float) $this->col($row, 'ENROLLED_DEBT', 0);
            $agent = strtoupper((string) $this->col($row, 'RETENTION_AGENT', ''));
            $multi = ($agent === 'SYDNEY LEYVA') ? 2 : 1;
            $tier = $this->tierAmounts($debt);
            $row['T1'] = $tier['t1'] * $multi;
            $row['T2'] = $tier['t2'] * $multi;
            $row['T3'] = $tier['t3'] * $multi;
            $row['T4'] = $tier['t4'] * $multi;
        }
        unset($row);

        $log('[INFO] ' . $display . ' rows after pipeline: ' . count($rows));

        return $rows;
    }

    /**
     * @param callable(string):void|null $log
     * @return array<int,array<string,mixed>>
     */
    public function buildAllDataForPeriod(string $startDate, string $endDate, ?string $snapshotDir = null, ?callable $log = null): array
    {
        $log ??= static function (): void {};

        $all = [];
        if ($snapshotDir !== null && $snapshotDir !== '') {
            $log('[INFO] Loading retention snapshot: ' . $snapshotDir);
            foreach (['ldr', 'plaw'] as $source) {
                $path = rtrim($snapshotDir, '/\\') . DIRECTORY_SEPARATOR . $source . '_rows.json';
                if (!is_file($path)) {
                    throw new \RuntimeException("Snapshot missing: {$path}. Run reports:retention-commission with --save-snapshot first.");
                }
                $decoded = json_decode((string) file_get_contents($path), true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException("Invalid snapshot JSON: {$path}");
                }
                array_push($all, ...$decoded);
            }
        } else {
            foreach (array_keys(self::SOURCE_CONFIG) as $source) {
                array_push($all, ...$this->buildSourceRows($source, $log));
            }
        }

        $all = array_values(array_filter($all, function (array $row) use ($startDate, $endDate): bool {
            $cancelRequestDate = $this->toDate($this->col($row, 'CANCEL_REQUEST_DATE'));
            return $cancelRequestDate !== null && $cancelRequestDate >= $startDate && $cancelRequestDate <= $endDate;
        }));

        $all = $this->dedupeRetentionRowsByContactId($all);

        usort($all, function (array $a, array $b): int {
            $sourceOrder = ['LDR' => 0, 'PROGRESS LAW' => 1];
            return [
                $sourceOrder[strtoupper((string) $this->col($a, 'SOURCE', ''))] ?? 9,
                strtoupper((string) $this->col($a, 'RETENTION_AGENT', '')),
                (string) $this->col($a, 'ID', ''),
            ] <=> [
                $sourceOrder[strtoupper((string) $this->col($b, 'SOURCE', ''))] ?? 9,
                strtoupper((string) $this->col($b, 'RETENTION_AGENT', '')),
                (string) $this->col($b, 'ID', ''),
            ];
        });

        return $all;
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function saveSnapshot(string $dir, string $source, array $rows): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $source . '_rows.json';
        file_put_contents($path, json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @param array<string,mixed> $cfg */
    private function fetchBase(DBConnector $sf, array $cfg): array
    {
        $ca = (int) $cfg['custom_agent'];
        $cd = (int) $cfg['custom_date'];
        $cr = (int) $cfg['custom_results'];
        $cc = (int) $cfg['cancel_request_custom'];

        $excludeSql = '';
        if (!empty($cfg['excluded_agents'])) {
            $excludedListUpper = implode(',', array_map(
                fn ($a) => "'" . str_replace("'", "''", strtoupper((string) $a)) . "'",
                $cfg['excluded_agents']
            ));
            $excludeSql = "AND UPPER(cu1.F_STRING) NOT IN ($excludedListUpper)";
        }

        $sql = "
            SELECT
                c.ID,
                CONCAT(c.FIRSTNAME,' ',c.LASTNAME) AS CLIENT,
                cu1.F_STRING AS RETENTION_AGENT,
                LEFT(cu2.F_DATE, 10) AS RETENTION_DATE,
                cu3.F_STRING AS IMMEDIATE_RESULTS,
                d.ENROLLED_DEBT,
                LEFT(c.DROPPED_DATE, 10) AS DROPPED_DATE,
                TO_VARCHAR(TO_DATE(cu4.F_DATETIME), 'YYYY-MM-DD') AS CANCEL_REQUEST_DATE
            FROM CONTACTS c
            LEFT JOIN CONTACTS_USERFIELDS cu1 ON cu1.CONTACT_ID = c.ID AND cu1.CUSTOM_ID = {$ca}
            LEFT JOIN (SELECT CONTACT_ID, F_DATE FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = {$cd}) cu2 ON c.ID = cu2.CONTACT_ID
            LEFT JOIN CONTACTS_USERFIELDS cu3 ON cu3.CONTACT_ID = c.ID AND cu3.CUSTOM_ID = {$cr}
            LEFT JOIN CONTACTS_USERFIELDS cu4 ON cu4.CONTACT_ID = c.ID AND cu4.CUSTOM_ID = {$cc}
            LEFT JOIN (
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM DEBTS
                WHERE ENROLLED=1 AND _FIVETRAN_DELETED=FALSE
                GROUP BY CONTACT_ID
            ) d ON c.ID = d.CONTACT_ID
            WHERE cu1.CONTACT_ID IS NOT NULL
              AND cu3.CONTACT_ID IS NOT NULL
              AND cu4.CONTACT_ID IS NOT NULL
              {$excludeSql}
            ORDER BY cu1.F_STRING ASC
        ";

        return $sf->query($sql)['data'] ?? [];
    }

    private function fetchReconsiderationDates(DBConnector $sf, int $statusId, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP,10) AS RECON_DATE
            FROM CONTACTS_STATUS cs
            WHERE cs.STATUS_ID = {$statusId}
              AND cs.CONTACT_ID IN ({$idList})
            ORDER BY cs.CONTACT_ID ASC, cs.STAMP ASC
        ";
        $map = [];
        foreach ($sf->query($sql)['data'] ?? [] as $r) {
            $id = (string) $r['CONTACT_ID'];
            $map[$id] ??= $r['RECON_DATE'];
        }
        return $map;
    }

    private function fetchRetainedDates(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP,10) AS RETAINED_DATE
            FROM CONTACTS_STATUS cs
            LEFT JOIN CONTACTS_LEAD_STATUS cls ON cs.STATUS_ID = cls.ID
            WHERE UPPER(cls.TITLE) LIKE '%ENROLLED%'
              AND UPPER(cls.TITLE) NOT LIKE '%RECONSIDERATION%'
              AND cs.CONTACT_ID IN ({$idList})
            ORDER BY cs.CONTACT_ID ASC, cs.STAMP ASC
        ";
        $map = [];
        foreach ($sf->query($sql)['data'] ?? [] as $r) {
            $map[(string) $r['CONTACT_ID']][] = substr((string) $r['RETAINED_DATE'], 0, 10);
        }
        return $map;
    }

    private function fetchFirstClearedPerContact(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT CONTACT_ID, LEFT(CLEARED_DATE,10) AS CLEARED_DATE
            FROM TRANSACTIONS
            WHERE TRANS_TYPE = 'D'
              AND CLEARED_DATE IS NOT NULL
              AND RETURNED_DATE IS NULL
              AND CONTACT_ID IN ({$idList})
            ORDER BY CONTACT_ID ASC, CLEARED_DATE ASC
        ";
        $map = [];
        foreach ($sf->query($sql)['data'] ?? [] as $r) {
            $map[(string) $r['CONTACT_ID']][] = substr((string) $r['CLEARED_DATE'], 0, 10);
        }
        return $map;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function dedupeRetentionRowsByContactId(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $id = (string) $this->col($row, 'ID', '');
            if ($id === '') {
                continue;
            }
            if (!isset($byId[$id])) {
                $byId[$id] = $row;
                continue;
            }
            $keep = $byId[$id];
            $keepCancel = $this->toDate($this->col($keep, 'CANCEL_REQUEST_DATE'));
            $newCancel = $this->toDate($this->col($row, 'CANCEL_REQUEST_DATE'));
            if ($newCancel !== null && ($keepCancel === null || $newCancel < $keepCancel)) {
                $byId[$id] = $row;
            }
        }

        return array_values($byId);
    }

    /** @return array{t1:int,t2:int,t3:int,t4:int} */
    private function tierAmounts(float $debt): array
    {
        foreach (self::TIERS as $bracket) {
            if ($debt <= $bracket['max']) {
                return ['t1' => $bracket['t1'], 't2' => $bracket['t2'], 't3' => $bracket['t3'], 't4' => $bracket['t4']];
            }
        }
        return ['t1' => 20, 't2' => 40, 't3' => 60, 't4' => 150];
    }

    public function col(array $row, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
        $lower = strtolower($key);
        foreach ($row as $k => $v) {
            if (strtolower((string) $k) === $lower) {
                return $v;
            }
        }
        return $default;
    }

    public function toDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $ts = (int) $value;
            return $ts > 0 ? date('Y-m-d', $ts) : null;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }
}