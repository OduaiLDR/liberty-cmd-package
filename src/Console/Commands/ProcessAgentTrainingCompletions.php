<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessAgentTrainingCompletions extends Command
{
    protected $signature = 'Process:agent-training-completions';

    protected $description = 'Assign newly graduated training agents to sales managers (Jordan: load-balanced, Guatemala: fixed). Matches VBA ProcessAgentTrainingCompletions (no email).';

    private const JORDAN_MANAGER_IDS = [258, 223, 237];
    private const GUATEMALA_MANAGER_ID = 454;
    private const GUATEMALA_MANAGER_NAME = 'Olmar De Los Rios';

    public function handle(): int
    {
        $this->info('[INFO] ProcessAgentTrainingCompletions: starting.');
        Log::info('ProcessAgentTrainingCompletions command started.');

        try {
            $this->info('[DEBUG] Initializing LDR SQL Server connection...');
            $sqlServer = $this->initializeSqlServerConnector();
            $this->info('[DEBUG] LDR SQL Server OK.');
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('ProcessAgentTrainingCompletions: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $agents = $this->fetchEligibleAgents($sqlServer);
            $this->info('[INFO] Eligible agents: ' . count($agents));

            if (empty($agents)) {
                $this->info('[INFO] No eligible agents to process.');
                return Command::SUCCESS;
            }

            $assigned = 0;
            $skipped = 0;

            foreach ($agents as $agent) {
                $agentId = (int) ($agent['pk'] ?? 0);
                $name = (string) ($agent['employee_name'] ?? '');
                $location = trim((string) ($agent['location'] ?? ''));

                if ($agentId <= 0) {
                    $this->warn('[WARN] Skipping row with invalid PK.');
                    $skipped++;
                    continue;
                }

                if ($location === 'Jordan') {
                    $manager = $this->pickJordanManager($sqlServer);
                    if ($manager === null) {
                        $this->warn("[WARN] Agent {$agentId} ({$name}) is Jordan but no eligible manager found. Skipped.");
                        Log::warning('ProcessAgentTrainingCompletions: no Jordan manager available.', [
                            'agent_id' => $agentId,
                            'name' => $name,
                        ]);
                        $skipped++;
                        continue;
                    }
                    $managerId = (int) ($manager['sales_manager_id'] ?? 0);
                    $managerName = (string) ($manager['employee_name'] ?? '');
                } elseif ($location === 'Guatemala') {
                    $managerId = self::GUATEMALA_MANAGER_ID;
                    $managerName = self::GUATEMALA_MANAGER_NAME;
                } else {
                    $this->warn("[WARN] Agent {$agentId} ({$name}) has unsupported Location '{$location}' — skipped.");
                    Log::warning('ProcessAgentTrainingCompletions: unsupported location.', [
                        'agent_id' => $agentId,
                        'name' => $name,
                        'location' => $location,
                    ]);
                    $skipped++;
                    continue;
                }

                if ($managerId <= 0) {
                    $this->warn("[WARN] Agent {$agentId} ({$name}) resolved to invalid manager ID. Skipped.");
                    $skipped++;
                    continue;
                }

                $this->assignAgent($sqlServer, $agentId, $managerId);
                $this->info("[INFO] Assigned agent {$agentId} ({$name}) [{$location}] to manager {$managerId} ({$managerName}).");
                $assigned++;
            }

            $this->info("[INFO] Done. Assigned: {$assigned}, Skipped: {$skipped}.");
            Log::info('ProcessAgentTrainingCompletions command finished.', [
                'eligible' => count($agents),
                'assigned' => $assigned,
                'skipped' => $skipped,
            ]);
        } catch (\Throwable $e) {
            $this->error('ProcessAgentTrainingCompletions failed: ' . $e->getMessage());
            Log::error('ProcessAgentTrainingCompletions: exception', ['exception' => $e]);
            return Command::FAILURE;
        }

        $this->info('[SUCCESS] ProcessAgentTrainingCompletions completed successfully!');
        return Command::SUCCESS;
    }

    private function fetchEligibleAgents(DBConnector $connector): array
    {
        $today = date('Y-m-d');
        $sql = "
            SELECT
                PK AS pk,
                Employee_Name AS employee_name,
                Email AS email,
                Location AS location
            FROM TblEmployees
            WHERE Access_Level = 'Agent'
              AND Term_Date IS NULL
              AND Graduation_Date IS NOT NULL
              AND Graduation_Date <= '{$today}'
              AND PK NOT IN (SELECT Agent_ID FROM TblEmployeeSalesManagers)
        ";
        $result = $connector->querySqlServer($sql);
        return $result['data'] ?? [];
    }

    private function pickJordanManager(DBConnector $connector): ?array
    {
        $idsList = implode(', ', array_map('intval', self::JORDAN_MANAGER_IDS));
        $sql = "
            SELECT TOP(1)
                s.Sales_Manager_ID AS sales_manager_id,
                e.Employee_Name AS employee_name,
                e.Email AS email
            FROM TblEmployeeSalesManagers s
            INNER JOIN TblEmployees e ON s.Sales_Manager_ID = e.PK
            WHERE s.Agent_ID IN (
                SELECT PK FROM TblEmployees
                WHERE Access_Level = 'Agent' AND Term_Date IS NULL
            )
              AND s.End_Date IS NULL
              AND s.Sales_Manager_ID IN ({$idsList})
            GROUP BY s.Sales_Manager_ID, e.Employee_Name, e.Email
            ORDER BY COUNT(*) ASC, NEWID() ASC
        ";
        $result = $connector->querySqlServer($sql);
        $rows = $result['data'] ?? [];
        return $rows[0] ?? null;
    }

    private function assignAgent(DBConnector $connector, int $agentId, int $managerId): void
    {
        $today = date('Y-m-d');
        $sql = "
            INSERT INTO TblEmployeeSalesManagers (Agent_ID, Sales_Manager_ID, Start_Date)
            VALUES ({$agentId}, {$managerId}, '{$today}')
        ";
        $connector->querySqlServer($sql);
    }

    protected function initializeSqlServerConnector(): DBConnector
    {
        $candidates = ['ldr', 'plaw', 'production', 'sandbox'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                $connector = DBConnector::fromEnvironment($env);
                $connector->initializeSqlServer();
                return $connector;
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize SQL Server connector. Tried: ' . implode('; ', $errors));
    }
}
