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
                $data = $gateway->listStagesAndStatuses($tenant);
            } catch (\Throwable $e) {
                $this->error("[{$tenant}] Failed: " . $e->getMessage());
                continue;
            }

            $path = storage_path("app/forth-stages-statuses-{$tenant}.json");
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("[INFO] [{$tenant}] Raw response saved to {$path}");

            $this->renderTable($tenant, $data);
        }

        return self::SUCCESS;
    }

    /**
     * Render a flat table of stage_id, stage_title, status_id, status_title.
     * Shape is guessed from typical Forth payload (`[{ id, title, statuses: [{id, title}] }]`);
     * if the real shape differs, the raw JSON file still has everything.
     *
     * @param list<array<string, mixed>> $data
     */
    private function renderTable(string $tenant, array $data): void
    {
        $rows = [];

        foreach ($data as $stage) {
            $stageId = $stage['id'] ?? $stage['stage_id'] ?? null;
            $stageTitle = $stage['title'] ?? $stage['name'] ?? $stage['stage_title'] ?? null;
            $statuses = $stage['statuses'] ?? $stage['contact_statuses'] ?? [];

            if (!is_array($statuses) || $statuses === []) {
                $rows[] = [$stageId, $stageTitle, '', ''];
                continue;
            }

            foreach ($statuses as $status) {
                if (!is_array($status)) {
                    continue;
                }
                $rows[] = [
                    $stageId,
                    $stageTitle,
                    $status['id'] ?? $status['status_id'] ?? null,
                    $status['title'] ?? $status['name'] ?? $status['status_title'] ?? null,
                ];
            }
        }

        if ($rows === []) {
            $this->warn("[{$tenant}] No rows extracted from response (shape may differ — see JSON file).");
            return;
        }

        $this->table(
            ['Stage ID', 'Stage Title', 'Status ID', 'Status Title'],
            $rows,
        );
    }
}
