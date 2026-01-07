<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeadSummaryReportRepository extends SqlSrvRepository
{
    /**
     * Hour bucket labels for the chart (PST time).
     */
    protected array $hourBuckets = [
        '12AM-7AM PST',
        '7AM PST',
        '8AM PST',
        '9AM PST',
        '10AM PST',
        '11AM PST',
        '12PM PST',
        '1PM PST',
        '2PM PST',
        '3PM PST',
        '4PM-8PM PST',
        '8PM-12AM PST',
    ];

    /**
     * Data source categories.
     */
    protected array $sourceCategories = [
        'Call Center' => ['call-center', 'call_center', 'callcenter', 'LLG-call-center'],
        'Apply Online' => ['apply-online', 'apply_online', 'applyonline', 'LLG-apply-online'],
        'Manual Entry' => ['manual', 'manual-entry', 'manual_entry', 'manualentry'],
    ];

    /**
     * Get hourly aggregated lead counts by data source.
     *
     * @param array<string, mixed> $filters
     */
    public function getHourlyData(?string $from = null, ?string $to = null, array $filters = []): array
    {
        $query = $this->connection()->table('TblContacts')
            ->selectRaw("
                DATEPART(hour, Created_Date) as hour,
                Data_Source,
                COUNT(*) as lead_count
            ")
            ->whereNotNull('Created_Date')
            ->groupBy(DB::raw('DATEPART(hour, Created_Date)'), 'Data_Source');

        if ($from) {
            $query->whereDate('Created_Date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('Created_Date', '<=', $to);
        }

        if (!empty($filters['debt_tier'])) {
            $tier = (int) $filters['debt_tier'];
            $ranges = $this->getDebtTierRange($tier);
            if ($ranges) {
                $query->whereBetween('Debt_Amount', [$ranges['min'], $ranges['max']]);
            }
        }

        $results = $query->get();

        return $this->aggregateByHourBucket($results);
    }

    /**
     * Get summary totals by data source.
     *
     * @param array<string, mixed> $filters
     */
    public function getSummaryBySource(?string $from = null, ?string $to = null, array $filters = []): array
    {
        $query = $this->connection()->table('TblContacts')
            ->selectRaw('Data_Source, COUNT(*) as total_leads')
            ->whereNotNull('Created_Date')
            ->groupBy('Data_Source');

        if ($from) {
            $query->whereDate('Created_Date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('Created_Date', '<=', $to);
        }

        if (!empty($filters['debt_tier'])) {
            $tier = (int) $filters['debt_tier'];
            $ranges = $this->getDebtTierRange($tier);
            if ($ranges) {
                $query->whereBetween('Debt_Amount', [$ranges['min'], $ranges['max']]);
            }
        }

        $results = $query->get();

        $summary = [
            'Call Center' => 0,
            'Apply Online' => 0,
            'Manual Entry' => 0,
        ];

        foreach ($results as $row) {
            $source = $this->categorizeSource($row->Data_Source);
            $summary[$source] += (int) $row->total_leads;
        }

        $summary['Total'] = array_sum($summary);

        return $summary;
    }

    /**
     * Get chart data formatted for Chart.js bar chart.
     */
    public function getChartData(array $hourlyData): array
    {
        $callCenter = [];
        $applyOnline = [];
        $manualEntry = [];

        foreach ($this->hourBuckets as $bucket) {
            $callCenter[] = $hourlyData[$bucket]['Call Center'] ?? 0;
            $applyOnline[] = $hourlyData[$bucket]['Apply Online'] ?? 0;
            $manualEntry[] = $hourlyData[$bucket]['Manual Entry'] ?? 0;
        }

        return [
            'labels' => $this->hourBuckets,
            'datasets' => [
                [
                    'label' => 'Call Center',
                    'data' => $callCenter,
                    'backgroundColor' => 'rgba(54, 162, 235, 0.9)',
                    'borderColor' => 'rgba(54, 162, 235, 1)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Apply Online',
                    'data' => $applyOnline,
                    'backgroundColor' => 'rgba(75, 192, 92, 0.9)',
                    'borderColor' => 'rgba(75, 192, 92, 1)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Manual Entry',
                    'data' => $manualEntry,
                    'backgroundColor' => 'rgba(255, 206, 86, 0.9)',
                    'borderColor' => 'rgba(255, 206, 86, 1)',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    /**
     * Convert hourly results into bucket aggregates.
     */
    protected function aggregateByHourBucket(Collection $results): array
    {
        $buckets = [];
        foreach ($this->hourBuckets as $bucket) {
            $buckets[$bucket] = [
                'Call Center' => 0,
                'Apply Online' => 0,
                'Manual Entry' => 0,
            ];
        }

        foreach ($results as $row) {
            $hour = (int) $row->hour;
            $bucket = $this->hourToBucket($hour);
            $source = $this->categorizeSource($row->Data_Source);
            $buckets[$bucket][$source] += (int) $row->lead_count;
        }

        return $buckets;
    }

    /**
     * Map hour (0-23) to bucket label.
     */
    protected function hourToBucket(int $hour): string
    {
        if ($hour >= 0 && $hour < 7) {
            return '12AM-7AM PST';
        }
        if ($hour >= 20 && $hour <= 23) {
            return '8PM-12AM PST';
        }
        if ($hour >= 16 && $hour < 20) {
            return '4PM-8PM PST';
        }

        $map = [
            7 => '7AM PST',
            8 => '8AM PST',
            9 => '9AM PST',
            10 => '10AM PST',
            11 => '11AM PST',
            12 => '12PM PST',
            13 => '1PM PST',
            14 => '2PM PST',
            15 => '3PM PST',
        ];

        return $map[$hour] ?? '12AM-7AM PST';
    }

    /**
     * Categorize data source into one of three groups.
     */
    protected function categorizeSource(?string $source): string
    {
        if ($source === null || $source === '') {
            return 'Manual Entry';
        }

        $sourceLower = strtolower($source);

        foreach ($this->sourceCategories as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($sourceLower, strtolower($pattern))) {
                    return $category;
                }
            }
        }

        return 'Manual Entry';
    }

    /**
     * Get debt tier range (min/max).
     */
    protected function getDebtTierRange(int $tier): ?array
    {
        $tiers = [
            1 => ['min' => 0, 'max' => 12000],
            2 => ['min' => 12000, 'max' => 15001],
            3 => ['min' => 15001, 'max' => 19000],
            4 => ['min' => 19000, 'max' => 26000],
            5 => ['min' => 26000, 'max' => 35000],
            6 => ['min' => 35000, 'max' => 50000],
            7 => ['min' => 50000, 'max' => 65000],
            8 => ['min' => 65000, 'max' => 80000],
            9 => ['min' => 80000, 'max' => 999999999],
        ];

        return $tiers[$tier] ?? null;
    }
}
