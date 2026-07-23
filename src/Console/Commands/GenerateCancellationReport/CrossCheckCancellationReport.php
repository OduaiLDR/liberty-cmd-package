<?php

namespace Cmd\Reports\Console\Commands\GenerateCancellationReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;

/**
 * Debug tool: cross-checks every Data 1/Data 2 row and every cohort number both real Cancellation
 * Report workbooks ship (LDR, sourced only from the 'ldr' Snowflake account; Progress Law, sourced only
 * from 'plaw' — no cross-source merging, matching GenerateCancellationReport exactly), using two
 * independent code paths per category:
 *
 * 1. Row-level sanity checks (bad dates, duplicate IDs, unknown company buckets, Data 2 not a
 *    subset of Data 1, negative/non-numeric PAYMENTS).
 * 2. A brute-force reimplementation of the cohort math (deliberately NOT sharing code with
 *    CohortReportBuilder) run against every row, diffed cell-by-cell against
 *    CohortReportBuilder's actual output for "{Category} - All Contacts", "{Category} - With
 *    Settlements", and all 3 Cancellation Report company blocks.
 * 3. A bucket-sum check: for LDR, LDR-bucket + PLAW-bucket gross enrollments must equal
 *    "LDR - All Contacts"'s unfiltered gross (both buckets can appear within the 'ldr' account);
 *    for Progress Law, every row is the Progress Law bucket, so bucket gross must equal the unfiltered gross.
 *
 * Read-only — does not build or send any workbook.
 */
class CrossCheckCancellationReport extends Command
{
    protected $signature = 'Debug:cancellation-report-crosscheck';

    protected $description = 'Cross-checks Cancellation Report data and cohort math (per category, single-source, matching production) using an independent brute-force recomputation.';

    private const CATEGORY_ENVS = ['LDR' => 'ldr', 'Progress Law' => 'plaw'];
    private const CATEGORY_BUCKETS = ['LDR' => ['LDR', 'PLAW'], 'Progress Law' => ['Progress Law']];

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');

        $reportCommand = new GenerateCancellationReport();
        $builder = new CohortReportBuilder();
        $issues = [];

        foreach (self::CATEGORY_ENVS as $category => $env) {
            $this->info("[{$category}] connecting to '{$env}' Snowflake...");

            try {
                $source = DBConnector::fromEnvironment($env);
            } catch (\Throwable $e) {
                $issues[] = "[{$category}] Could not connect to {$env} Snowflake: " . $e->getMessage();
                continue;
            }

            $data1 = $this->callPrivate($reportCommand, 'fetchData1', [$source, $env]);
            $data2 = $this->callPrivate($reportCommand, 'fetchData2', [$source]);

            $this->info("[{$category}] Data 1 rows: " . count($data1));
            $this->info("[{$category}] Data 2 rows: " . count($data2));

            $this->checkRows($data1, "[{$category}] Data 1", true, $issues);
            $this->checkRows($data2, "[{$category}] Data 2", false, $issues);
            $this->checkData2IsSubsetOfData1($data1, $data2, "[{$category}]", $issues);

            $allContactsBlocks = $builder->buildReportBlocks($data1);
            $bruteAll = $this->bruteForceCohort($data1, null, null);
            $this->crossCheckCanceledBlock("[{$category}] All Contacts", $allContactsBlocks, $bruteAll, $issues);
            $this->crossCheckAllBlocks("[{$category}] All Contacts", $data1, $allContactsBlocks, $issues);

            $withSettlementsBlocks = $builder->buildReportBlocks($data2);
            $bruteSettlements = $this->bruteForceCohort($data2, null, null);
            $this->crossCheckCanceledBlock("[{$category}] With Settlements", $withSettlementsBlocks, $bruteSettlements, $issues);
            $this->crossCheckAllBlocks("[{$category}] With Settlements", $data2, $withSettlementsBlocks, $issues);

            $cancellationReportBlocks = $builder->buildCancellationReportBlocks($data1, self::CATEGORY_BUCKETS[$category]);
            $companyMap = array_filter([
                'LDR Cancellation' => 'LDR',
                'Progress Law Cancellations' => 'Progress Law',
                'Paramount Law Cancellation' => 'PLAW',
            ], fn (string $company) => in_array($company, self::CATEGORY_BUCKETS[$category], true));
            foreach ($companyMap as $title => $company) {
                $block = null;
                foreach ($cancellationReportBlocks as $b) {
                    if ($b['title'] === $title) {
                        $block = $b;
                        break;
                    }
                }
                if ($block === null) {
                    $issues[] = "[{$category}] Cancellation Report: missing block '{$title}'";
                    continue;
                }
                $brute = $this->bruteForceCohort($data1, $company, null);
                $this->diffBlockAgainstBrute("[{$category}] Cancellation Report [{$title}]", $block, $brute, $issues);
            }

            // Bucket-sum cross-check: this category's own bucket(s) must sum to its unfiltered gross.
            $bucketBrutes = array_map(fn (string $bucket) => $this->bruteForceCohort($data1, $bucket, null), self::CATEGORY_BUCKETS[$category]);
            for ($i = 0; $i < 12; $i++) {
                $sum = array_sum(array_map(fn (array $b) => $b['gross'][$i], $bucketBrutes));
                $all = $bruteAll['gross'][$i];
                if ($sum !== $all) {
                    $buckets = implode('+', self::CATEGORY_BUCKETS[$category]);
                    $issues[] = "[{$category}] Bucket sum mismatch month {$bruteAll['months'][$i]}: {$buckets}={$sum} vs All Contacts gross={$all}";
                }
            }
        }

        if (empty($issues)) {
            $this->info("\nAll cross-checks passed - no discrepancies found.");
            return Command::SUCCESS;
        }

        $this->error("\n" . count($issues) . ' issue(s) found:');
        foreach (array_slice($issues, 0, 200) as $issue) {
            $this->line(" - {$issue}");
        }
        if (count($issues) > 200) {
            $this->line(' ... and ' . (count($issues) - 200) . ' more');
        }

        return Command::FAILURE;
    }

    private function callPrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $issues
     */
    private function checkRows(array $rows, string $label, bool $expectBucket, array &$issues): void
    {
        $seenIds = [];
        foreach ($rows as $i => $row) {
            $id = $row['ID'] ?? null;
            if ($id === null) {
                $issues[] = "{$label} row {$i}: missing ID";
            } elseif (isset($seenIds[$id])) {
                $issues[] = "{$label} row {$i}: duplicate ID {$id}";
            } else {
                $seenIds[$id] = true;
            }

            $enrolled = $row['ENROLLED_DATE'] ?? null;
            $dropped = $row['DROPPED_DATE'] ?? null;

            if ($enrolled === null) {
                $issues[] = "{$label} row {$i} (ID {$id}): missing ENROLLED_DATE";
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $enrolled)) {
                $issues[] = "{$label} row {$i} (ID {$id}): malformed ENROLLED_DATE '{$enrolled}'";
            }

            if ($dropped !== null) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dropped)) {
                    $issues[] = "{$label} row {$i} (ID {$id}): malformed DROPPED_DATE '{$dropped}'";
                } elseif ($enrolled !== null && $dropped < $enrolled) {
                    $issues[] = "{$label} row {$i} (ID {$id}): DROPPED_DATE ({$dropped}) before ENROLLED_DATE ({$enrolled})";
                }
            }

            if ($expectBucket) {
                $bucket = $row['TITLE'] ?? null;
                if (!in_array($bucket, ['LDR', 'PLAW', 'Progress Law'], true)) {
                    $issues[] = "{$label} row {$i} (ID {$id}): unexpected bucket '{$bucket}'";
                }
            }

            if (isset($row['PAYMENTS']) && $row['PAYMENTS'] !== null && (!is_numeric($row['PAYMENTS']) || $row['PAYMENTS'] < 0)) {
                $issues[] = "{$label} row {$i} (ID {$id}): invalid PAYMENTS value '{$row['PAYMENTS']}'";
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $data1
     * @param array<int, array<string, mixed>> $data2
     * @param string[] $issues
     */
    private function checkData2IsSubsetOfData1(array $data1, array $data2, string $labelPrefix, array &$issues): void
    {
        $data1Ids = array_flip(array_filter(array_column($data1, 'ID'), fn ($id) => $id !== null));
        foreach ($data2 as $i => $row) {
            $id = $row['ID'] ?? null;
            if ($id !== null && !isset($data1Ids[$id])) {
                $issues[] = "{$labelPrefix} Data 2 row {$i} (ID {$id}): present in Data 2 but not in Data 1 (should be a subset)";
            }
        }
    }

    /**
     * Independent, deliberately-separate reimplementation of CohortReportBuilder's cohort math,
     * used purely to cross-check the production implementation.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param 'blank'|'nonblank'|null $paymentFilter
     * @return array{months: string[], gross: int[], canceled: array<int, array<int, int|null>>}
     */
    private function bruteForceCohort(array $rows, ?string $company, ?string $paymentFilter): array
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $anchor = new \DateTimeImmutable('first day of this month');
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = $anchor->modify("-{$i} months");
        }

        $gross = [];
        $canceled = [];
        foreach ($months as $monthStart) {
            $monthEnd = $monthStart->modify('last day of this month');
            $monthStartStr = $monthStart->format('Y-m-d');
            $monthEndStr = $monthEnd->format('Y-m-d');

            $g = 0;
            $enrolledInMonth = [];
            foreach ($rows as $row) {
                if ($company !== null && ($row['TITLE'] ?? null) !== $company) {
                    continue;
                }
                $e = $row['ENROLLED_DATE'] ?? null;
                if ($e === null || $e < $monthStartStr || $e > $monthEndStr) {
                    continue;
                }
                $g++;
                $enrolledInMonth[] = $row;
            }
            $gross[] = $g;

            $offsets = [];
            for ($n = 0; $n < 13; $n++) {
                $winStart = $monthStart->modify("+{$n} months");
                $winStartStr = $winStart->format('Y-m-d');
                if ($winStartStr > $today) {
                    $offsets[] = null;
                    continue;
                }
                $winEnd = $winStart->modify('last day of this month');
                $winEndStr = $winEnd->format('Y-m-d');

                $c = 0;
                foreach ($enrolledInMonth as $row) {
                    $d = $row['DROPPED_DATE'] ?? null;
                    if ($d === null || $d < $winStartStr || $d > $winEndStr) {
                        continue;
                    }
                    if ($paymentFilter === 'blank' && !empty($row['PAYMENTS'])) {
                        continue;
                    }
                    if ($paymentFilter === 'nonblank' && empty($row['PAYMENTS'])) {
                        continue;
                    }
                    $c++;
                }
                $offsets[] = $c;
            }
            $canceled[] = $offsets;
        }

        return [
            'months' => array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $months),
            'gross' => $gross,
            'canceled' => $canceled,
        ];
    }

    /**
     * Diffs the production "Canceled" block (raw cancel counts, no payment filter) against the
     * brute-force recompute.
     *
     * @param array<int, array<string, mixed>> $prodBlocks
     * @param array{months: string[], gross: int[], canceled: array<int, array<int, int|null>>} $brute
     * @param string[] $issues
     */
    private function crossCheckCanceledBlock(string $label, array $prodBlocks, array $brute, array &$issues): void
    {
        foreach ($prodBlocks as $block) {
            if ($block['title'] !== 'Canceled') {
                continue;
            }
            $this->diffBlockAgainstBrute($label, $block, $brute, $issues);
        }
    }

    /**
     * @param array{title: string, percent: bool, months: string[], gross: int[], values: array<int, array<int, int|float|null>>} $block
     * @param array{months: string[], gross: int[], canceled: array<int, array<int, int|null>>} $brute
     * @param string[] $issues
     */
    private function diffBlockAgainstBrute(string $label, array $block, array $brute, array &$issues): void
    {
        foreach ($block['months'] as $i => $month) {
            if ($month !== $brute['months'][$i]) {
                $issues[] = "{$label}: month mismatch at index {$i}: {$month} vs {$brute['months'][$i]}";
                continue;
            }
            if ($block['gross'][$i] !== $brute['gross'][$i]) {
                $issues[] = "{$label}: gross mismatch month {$month}: prod={$block['gross'][$i]} brute={$brute['gross'][$i]}";
            }
            foreach ($block['values'][$i] as $n => $v) {
                $bf = $brute['canceled'][$i][$n];
                if ($v !== $bf) {
                    $issues[] = "{$label}: canceled mismatch month {$month} offset {$n}: prod=" . var_export($v, true) . ' brute=' . var_export($bf, true);
                }
            }
        }
    }

    /**
     * Cross-checks the other 4 "Report (All)"/"Report (Settlements)" blocks (Net Enrollments, Net
     * Enrollment Percent, Canceled With No Payments, Canceled With Payments) against brute-force
     * recomputes with the matching payment filter.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $prodBlocks
     * @param string[] $issues
     */
    private function crossCheckAllBlocks(string $label, array $rows, array $prodBlocks, array &$issues): void
    {
        $filters = [
            'Canceled With No Payments' => 'blank',
            'Canceled With Payments' => 'nonblank',
        ];

        foreach ($prodBlocks as $block) {
            if (isset($filters[$block['title']])) {
                $brute = $this->bruteForceCohort($rows, null, $filters[$block['title']]);
                $this->diffBlockAgainstBrute("{$label} [{$block['title']}]", $block, $brute, $issues);
            } elseif ($block['title'] === 'Net Enrollments') {
                $bruteNet = $this->bruteForceCohort($rows, null, null);
                foreach ($block['months'] as $i => $month) {
                    foreach ($block['values'][$i] as $n => $v) {
                        $bf = $bruteNet['canceled'][$i][$n];
                        $expectedNet = $bf === null ? null : $bruteNet['gross'][$i] - $bf;
                        if ($v !== $expectedNet) {
                            $issues[] = "{$label} [Net Enrollments]: mismatch month {$month} offset {$n}: prod=" . var_export($v, true) . ' expected=' . var_export($expectedNet, true);
                        }
                    }
                }
            } elseif ($block['title'] === 'Net Enrollment Percent') {
                $bruteNet = $this->bruteForceCohort($rows, null, null);
                foreach ($block['months'] as $i => $month) {
                    $gross = $bruteNet['gross'][$i];
                    foreach ($block['values'][$i] as $n => $v) {
                        $bf = $bruteNet['canceled'][$i][$n];
                        $expectedNet = $bf === null ? null : $gross - $bf;
                        $expectedPct = ($expectedNet === null || $gross <= 0) ? null : $expectedNet / $gross;
                        if ($v !== $expectedPct) {
                            $issues[] = "{$label} [Net Enrollment Percent]: mismatch month {$month} offset {$n}: prod=" . var_export($v, true) . ' expected=' . var_export($expectedPct, true);
                        }
                    }
                }
            }
        }
    }
}
