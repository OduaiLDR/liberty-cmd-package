<?php

namespace Cmd\Reports\Console\Commands\GenerateLegalReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateLegalReport extends Command
{
    protected $signature = 'Generate:legal-report';

    protected $description = 'Generate Legal report (Snowflake) and email it.';

    public function handle(): int
    {
        $this->info('[INFO] Legal report: starting.');

        try {
            $snowflakeLdr = DBConnector::fromEnvironment('ldr');
            $snowflakePlaw = DBConnector::fromEnvironment('plaw');
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize connectors: ' . $e->getMessage());
            Log::error('GenerateLegalReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $formatter = new Formatter();

        // Generate LDR Legal Report
        try {
            $this->info('[INFO] Generating LDR Legal Report...');
            $ldrPayload = $this->buildReport($snowflakeLdr);
            $ldrResult = $formatter->buildWorkbook($ldrPayload, 'LDR');
            $formatter->sendReport($sqlConnector, $ldrResult['path'], $ldrResult['filename'], 'LDR', $this);
            $this->info("[INFO] LDR Legal Report written to {$ldrResult['path']}");
        } catch (\Throwable $e) {
            $this->error('LDR Legal Report failed: ' . $e->getMessage());
            Log::error('GenerateLegalReport: LDR report failed', ['exception' => $e]);
        }

        // Generate Progress Law Legal Report
        try {
            $this->info('[INFO] Generating Progress Law Legal Report...');
            $plawPayload = $this->buildReport($snowflakePlaw);
            $plawResult = $formatter->buildWorkbook($plawPayload, 'Progress Law');
            $formatter->sendReport($sqlConnector, $plawResult['path'], $plawResult['filename'], 'PLAW', $this);
            $this->info("[INFO] Progress Law Legal Report written to {$plawResult['path']}");
        } catch (\Throwable $e) {
            $this->error('Progress Law Legal Report failed: ' . $e->getMessage());
            Log::error('GenerateLegalReport: Progress Law report failed', ['exception' => $e]);
        }

        return Command::SUCCESS;
    }

    private function buildReport(DBConnector $snowflake): array
    {
        $payload = [];
        $sheets = [
            0 => 'Legal Report - Not Settled',
            1 => 'Legal Report - Settled',
        ];

        foreach ($sheets as $settled => $title) {
            $rows = $this->fetchLegalRows($snowflake, $settled, 1);
            $priorRows = $this->fetchLegalRows($snowflake, $settled, 2);
            $payload[] = [
                'title' => $title,
                'rows' => $rows,
                'prior_map' => $this->indexPriorNotes($priorRows),
            ];
        }

        return $payload;
    }

    private function fetchLegalRows(DBConnector $snowflake, int $settled, int $noteRank): array
    {
        $settled = $settled ? 1 : 0;

        $sql = "
            SELECT
                c.ID,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CLIENT,
                TO_VARCHAR(c.DOB::date, 'YYYY-MM-DD') AS DOB,
                c.STATE,
                ed.TITLE AS PLAN_NAME,
                TO_VARCHAR(d.SUMMONS_DATE::date, 'YYYY-MM-DD') AS SUMMONS_DATE,
                TO_VARCHAR(d.ANSWER_DATE::date, 'YYYY-MM-DD') AS ANSWER_DATE,
                cr1.COMPANY AS ORIGINAL_CREDITOR,
                cr2.COMPANY AS DEBT_BUYER,
                d.ACCOUNT_NUM,
                d.VERIFIED_AMOUNT,
                b.SPA_BALANCE,
                TO_VARCHAR(d.POA_SENT_DATE::date, 'YYYY-MM-DD') AS POA_SENT_DATE,
                cd1.LEGAL_NEGOTIATOR,
                cd2.ATTORNEY_ASSIGNMENT,
                cd3.LEGAL_CLAIM_ID,
                TO_VARCHAR(d.SETTLEMENT_DATE::date, 'YYYY-MM-DD') AS SETTLEMENT_DATE,
                n.NOTES AS NEGOTIATOR_LAST_NOTE,
                n.NOTE_DATE AS LATEST_NOTE_DATE,
                b.N1,
                n.N2
            FROM CONTACTS AS c
            LEFT JOIN DEBTS AS d ON c.ID = d.CONTACT_ID
            LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
            LEFT JOIN CREDITORS AS cr1 ON d.CREDITOR_ID = cr1.ID
            LEFT JOIN CREDITORS AS cr2 ON d.DEBT_BUYER = cr2.ID
            LEFT JOIN (
                SELECT CONTACT_ID,
                       \"CURRENT\" AS SPA_BALANCE,
                       ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY STAMP DESC) AS N1
                FROM CONTACT_BALANCES
                WHERE \"CURRENT\" IS NOT NULL
            ) AS b ON c.ID = b.CONTACT_ID
            LEFT JOIN (
                SELECT DEBT_ID,
                       NOTES,
                       TO_VARCHAR(CREATED_AT::date, 'YYYY-MM-DD') AS NOTE_DATE,
                       ROW_NUMBER() OVER (PARTITION BY DEBT_ID ORDER BY CREATED_AT DESC) AS N2
                FROM DEBT_NOTES
            ) AS n ON d.ID = n.DEBT_ID
            LEFT JOIN (
                SELECT OBJ_ID, F_STRING AS LEGAL_NEGOTIATOR
                FROM CUSTOMFIELD_DATA
                WHERE CUSTOM_ID = 8357
            ) AS cd1 ON d.ID = cd1.OBJ_ID
            LEFT JOIN (
                SELECT OBJ_ID, F_STRING AS ATTORNEY_ASSIGNMENT
                FROM CUSTOMFIELD_DATA
                WHERE CUSTOM_ID = 8358
            ) AS cd2 ON d.ID = cd2.OBJ_ID
            LEFT JOIN (
                SELECT OBJ_ID, F_STRING AS LEGAL_CLAIM_ID
                FROM CUSTOMFIELD_DATA
                WHERE CUSTOM_ID = 8359
            ) AS cd3 ON d.ID = cd3.OBJ_ID
            WHERE d.ENROLLED = 1
              AND d.HAS_SUMMONS = 1
              AND c.ENROLLED = 1
              AND d.SETTLED = {$settled}
            QUALIFY N1 = 1 AND N2 = {$noteRank}
            ORDER BY CLIENT ASC, COALESCE(DEBT_BUYER, ORIGINAL_CREDITOR) ASC
        ";

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    private function indexPriorNotes(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $id = (string) ($row['ID'] ?? '');
            if ($id === '' || isset($map[$id])) {
                continue;
            }
            $map[$id] = $row['LATEST_NOTE_DATE'] ?? null;
        }
        return $map;
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
