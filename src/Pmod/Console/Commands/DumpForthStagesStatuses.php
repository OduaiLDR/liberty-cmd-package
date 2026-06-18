<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Console\Commands;

use Cmd\Reports\Pmod\Services\ForthPayPmodExecutionGateway;
use Illuminate\Console\Command;

/**
 * One-off discovery utility: pulls every Contact Stage and Status from Forth CRM
 * for one or more tenants and dumps the integer IDs + titles to JSON files in
 * storage/app/ plus a console table.
 *
 * Used to build the static status-name → status-id mapping ResumePayments needs.
 */
final class DumpForthStagesStatuses extends Command
{
    protected $signature = 'forth:dump-stages-statuses
        {--tenant=* : Tenant(s) to dump (LDR, PLAW). Defaults to both.}';

    protected $description = 'Dump all Forth CRM Contact Stages and Statuses with their integer IDs.';

    public function handle(ForthPayPmodExecutionGateway $gateway): int
    {
        $tenants = (array) $this->option('tenant');
        if ($tenants === []) {
            $tenants = ['LDR', 'PLAW'];
        }

        foreach ($tenants as $tenant) {
            $tenant = strtoupper(trim((string) $tenant));
            $this->info("[INFO] Fetching stages/statuses for {$tenant}...");

            try {
                $stages   = $gateway->listContactStages($tenant);
                $statuses = $gateway->listContactStatuses($tenant);
            } catch (\Throwable $e) {
                $this->error("[{$tenant}] Failed: " . $e->getMessage());
                continue;
            }

            $stagesPath   = storage_path("app/forth-stages-{$tenant}.json");
            $statusesPath = storage_path("app/forth-statuses-{$tenant}.json");
            file_put_contents($stagesPath,   json_encode($stages,   JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            file_put_contents($statusesPath, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("[INFO] [{$tenant}] Saved {$stagesPath} + {$statusesPath}");

            $this->renderTable($tenant, $stages, $statuses);
        }

        return self::SUCCESS;
    }

    /**
     * Render the flat status list joined to its stage via cat_id.
     *
     * Stage objects:  { id, title, ... }                 (from /contact-stages)
     * Status objects: { id, title, cat_id, file_type, ... } (from /contact-statuses)
     *
     * @param list<array<string, mixed>> $stages
     * @param list<array<string, mixed>> $statuses
     */
    private function renderTable(string $tenant, array $stages, array $statuses): void
    {
        $stageTitles = [];
        foreach ($stages as $stage) {
            if (isset($stage['id'])) {
                $stageTitles[(string) $stage['id']] = (string) ($stage['title'] ?? '');
            }
        }

        $rows = [];
        foreach ($statuses as $status) {
            $catId = (string) ($status['cat_id'] ?? '');
            $rows[] = [
                $status['id'] ?? '',
                $stageTitles[$catId] ?? $catId,
                $status['title'] ?? '',
            ];
        }

        if ($rows === []) {
            $this->warn("[{$tenant}] No statuses returned (see JSON files).");
            return;
        }

        $this->table(['Status ID', 'Stage', 'Status Title'], $rows);
    }
}
