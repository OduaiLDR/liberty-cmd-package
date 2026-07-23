<?php

namespace Cmd\Reports\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class EmailSenderService
{
    private Client $client;
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $fromAddress;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 30]);
        $this->tenantId = (string) env('GRAPH_TENANT_ID', '');
        $this->clientId = (string) env('GRAPH_CLIENT_ID', '');
        $this->clientSecret = (string) env('GRAPH_CLIENT_SECRET', '');
        $this->fromAddress = (string) env('GRAPH_FROM_ADDRESS', '');
    }

    public function sendMail(string $subject, string $body, array $to, array $cc = [], array $bcc = [], array $attachments = []): bool
    {
        if (!$this->tenantId || !$this->clientId || !$this->clientSecret || !$this->fromAddress) {
            Log::error('EmailSenderService: missing Graph configuration.');
            return false;
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            Log::error('EmailSenderService: unable to acquire access token.');
            return false;
        }

        $recipients = $this->formatRecipients($to);
        $ccRecipients = $this->formatRecipients($cc);
        $bccRecipients = $this->formatRecipients($bcc);

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => 'Text',
                    'content' => $body,
                ],
                'toRecipients' => $recipients,
                'ccRecipients' => $ccRecipients,
                'bccRecipients' => $bccRecipients,
                'attachments' => $this->formatAttachments($attachments),
            ],
            'saveToSentItems' => false,
        ];

        try {
            $response = $this->client->post(
                "https://graph.microsoft.com/v1.0/users/{$this->fromAddress}/sendMail",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (GuzzleException $e) {
            Log::error('EmailSenderService: sendMail failed.', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendMailHtml(string $subject, string $body, array $to, array $cc = [], array $bcc = [], array $attachments = []): bool
    {
        if (!$this->tenantId || !$this->clientId || !$this->clientSecret || !$this->fromAddress) {
            Log::error('EmailSenderService: missing Graph configuration.');
            return false;
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            Log::error('EmailSenderService: unable to acquire access token.');
            return false;
        }

        Log::info('EmailSenderService: Sending HTML email', [
            'subject' => $subject,
            'to' => $to,
            'from' => $this->fromAddress,
        ]);

        $recipients = $this->formatRecipients($to);
        $ccRecipients = $this->formatRecipients($cc);
        $bccRecipients = $this->formatRecipients($bcc);

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $body,
                ],
                'toRecipients' => $recipients,
                'ccRecipients' => $ccRecipients,
                'bccRecipients' => $bccRecipients,
                'attachments' => $this->formatAttachments($attachments),
            ],
            'saveToSentItems' => false,
        ];

        try {
            $response = $this->client->post(
                "https://graph.microsoft.com/v1.0/users/{$this->fromAddress}/sendMail",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );

            $statusCode = $response->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;
            
            Log::info('EmailSenderService: Email send response', [
                'status_code' => $statusCode,
                'success' => $success,
            ]);

            return $success;
        } catch (GuzzleException $e) {
            Log::error('EmailSenderService: sendMailHtml failed.', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            return false;
        }
    }

    /**
     * Send using recipients pulled from TblReports (by report name) plus optional env extras.
     *
     * @param  bool  $strictCompany  When true and $companies is non-empty: require a company column,
     *                               never drop the company filter, never fall back to all companies.
     */
    public function sendMailUsingTblReports(
        \Cmd\Reports\Services\DBConnector $connector,
        array $reportNames,
        array $companies,
        string $subject,
        string $body,
        array $attachments = [],
        bool $includeEnvExtras = true,
        bool $strictCompany = false
    ): bool {
        $recipients = $this->fetchRecipientGroupsFromTblReports($connector, $reportNames, $companies, $strictCompany);
        if ($includeEnvExtras) {
            $extras = $this->parseRecipientList((string) env('REPORT_EXTRA_RECIPIENTS', ''));
            $recipients['to'] = array_merge($recipients['to'], $extras);
        }
        $recipients = $this->normalizeRecipientGroups($recipients);

        if ($this->recipientGroupsAreEmpty($recipients)) {
            Log::warning('EmailSenderService: no recipients found for report.', ['reports' => $reportNames, 'companies' => $companies, 'strict_company' => $strictCompany]);
            return false;
        }

        $sent = $this->sendMail(
            $subject,
            $body,
            $recipients['to'],
            $recipients['cc'],
            $recipients['bcc'],
            $attachments
        );
        
        // Log to TblLog after successful send
        if ($sent) {
            $this->logToTblLog($connector, $reportNames, $companies, 'SUCCESS');
        }
        
        return $sent;
    }

    public function sendMailUsingTblReportsHtml(
        \Cmd\Reports\Services\DBConnector $connector,
        array $reportNames,
        array $companies,
        string $subject,
        string $body,
        array $attachments = [],
        bool $includeEnvExtras = true,
        bool $strictCompany = false
        ): bool {
        $recipients = $this->fetchRecipientGroupsFromTblReports($connector, $reportNames, $companies, $strictCompany);
        if ($includeEnvExtras) {
            $extras = $this->parseRecipientList((string) env('REPORT_EXTRA_RECIPIENTS', ''));
            $recipients['to'] = array_merge($recipients['to'], $extras);
        }
        $recipients = $this->normalizeRecipientGroups($recipients);

        if ($this->recipientGroupsAreEmpty($recipients)) {
            Log::warning('EmailSenderService: no recipients found for report.', ['reports' => $reportNames, 'companies' => $companies, 'strict_company' => $strictCompany]);
            return false;
        }

        $sent = $this->sendMailHtml(
            $subject,
            $body,
            $recipients['to'],
            $recipients['cc'],
            $recipients['bcc'],
            $attachments
        );
        
        // Log to TblLog after successful send
        if ($sent) {
            $this->logToTblLog($connector, $reportNames, $companies, 'SUCCESS');
        }
        
        return $sent;
    }

    private function getAccessToken(): ?string
    {
        $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
        $form = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ];

        try {
            $response = $this->client->post($url, ['form_params' => $form]);
            $data = json_decode((string) $response->getBody(), true);

            return $data['access_token'] ?? null;
        } catch (GuzzleException $e) {
            Log::error('EmailSenderService: token fetch failed.', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function formatRecipients(array $emails): array
    {
        $recipients = [];
        $invalid = [];

        foreach ($emails as $email) {
            $trimmed = trim((string) $email);
            $lower = strtolower($trimmed);

            // Skip blanks or known placeholders
            if ($trimmed === '' || $lower === 'null' || $lower === 'undefined') {
                continue;
            }

            if (!filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $trimmed;
                continue;
            }

            $recipients[] = ['emailAddress' => ['address' => $trimmed]];
        }

        if (!empty($invalid)) {
            Log::warning('EmailSenderService: skipped invalid recipient emails.', ['invalid' => $invalid]);
        }

        return $recipients;
    }

    /**
     * Parse semicolon/comma/pipe separated list to array.
     */
    private function parseRecipientList(string $value): array
    {
        $normalized = str_replace([';', '|'], ',', $value);
        $parts = array_map('trim', explode(',', $normalized));

        return array_values(array_filter($parts, function ($email) {
            $lower = strtolower($email);
            return $email !== '' && $lower !== 'null' && $lower !== 'undefined';
        }));
    }

    /**
     * Fetch To/CC/BCC recipient groups from TblReports for a given report.
     *
     * @param  bool  $strictCompany  Fail closed when companies requested but company column missing;
     *                               do not re-query without company filter.
     */
    private function fetchRecipientGroupsFromTblReports(
        \Cmd\Reports\Services\DBConnector $connector,
        array $reportNames,
        array $companies,
        bool $strictCompany = false
    ): array {
        if (empty($reportNames)) {
            return $this->emptyRecipientGroups();
        }

        $escapedReports = array_map(function ($name) {
            return "'" . str_replace("'", "''", (string) $name) . "'";
        }, $reportNames);
        $reportIn = implode(',', $escapedReports);

        $companyIn = '';
        if (!empty($companies)) {
            $escapedCompanies = array_map(function ($name) {
                return "'" . str_replace("'", "''", (string) $name) . "'";
            }, $companies);
            $companyIn = ' AND Company IN (' . implode(',', $escapedCompanies) . ')';
        }

        $columns = $this->getTblReportsColumns($connector);
        $reportColumn = $columns['report'] ?? null;
        $sendColumns = $columns['send'] ?? [];
        $companyColumn = $columns['company'] ?? null;

        if ($reportColumn === null || empty($sendColumns)) {
            Log::warning('EmailSenderService: TblReports columns not found.', [
                'report_column' => $reportColumn,
                'send_columns' => $sendColumns,
            ]);
            return $this->emptyRecipientGroups();
        }

        // Strict dual-portal sends must never drop the company filter.
        if ($strictCompany && ! empty($companies) && $companyColumn === null) {
            Log::warning('EmailSenderService: strict company filter requires company column.', [
                'reports' => $reportNames,
                'companies' => $companies,
            ]);
            return $this->emptyRecipientGroups();
        }

        $sendList = implode(', ', $sendColumns);
        $reportExpr = "LTRIM(RTRIM({$reportColumn}))";
        $companyClause = '';
        if ($companyColumn !== null && $companyIn !== '') {
            $companyExpr = "LTRIM(RTRIM({$companyColumn}))";
            $companyClause = str_replace('Company', $companyExpr, $companyIn);
        }

        $sql = "
            SELECT {$sendList}
            FROM dbo.TblReports
            WHERE {$reportExpr} IN ({$reportIn}) {$companyClause}
        ";
        $result = $connector->querySqlServer($sql);
        $rows = $result['data'] ?? [];
        $recipients = $this->collectRecipientGroups($rows, $sendColumns);

        // Legacy: if company filter yields nothing, broaden to all companies for that report.
        // Disabled under $strictCompany so LDR/PLAW isolation cannot leak.
        if ($this->recipientGroupsAreEmpty($recipients) && $companyClause !== '' && ! $strictCompany) {
            $fallbackSql = "
                SELECT {$sendList}
                FROM dbo.TblReports
                WHERE {$reportExpr} IN ({$reportIn})
            ";
            $fallbackResult = $connector->querySqlServer($fallbackSql);
            $fallbackRows = $fallbackResult['data'] ?? [];
            $recipients = $this->collectRecipientGroups($fallbackRows, $sendColumns);
            $rows = $fallbackRows;
        }

        if ($this->recipientGroupsAreEmpty($recipients)) {
            Log::warning('EmailSenderService: no recipients found for report.', [
                'reports' => $reportNames,
                'companies' => $companies,
                'report_column' => $reportColumn,
                'company_column' => $companyColumn,
                'send_columns' => $sendColumns,
                'row_count' => count($rows),
                'strict_company' => $strictCompany,
            ]);
        }

        return $this->normalizeRecipientGroups($recipients);
    }

    private function getTblReportsColumns(\Cmd\Reports\Services\DBConnector $connector): array
    {
        $result = $connector->querySqlServer("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'dbo'
              AND TABLE_NAME = 'TblReports'
        ");
        $rows = $result['data'] ?? [];
        $names = [];

        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_string($value) && $value !== '') {
                    $names[] = $value;
                    break;
                }
            }
        }

        $lookup = array_flip($names);
        $reportColumn = null;
        if (isset($lookup['Report_Name'])) {
            $reportColumn = 'Report_Name';
        } elseif (isset($lookup['ReportName'])) {
            $reportColumn = 'ReportName';
        }

        $sendColumns = [];
        $sendSets = [
            ['Send_To', 'Send_CC', 'Send_BCC'],
            ['SendTo', 'SendCC', 'SendBCC'],
        ];
        foreach ($sendSets as $set) {
            $allFound = true;
            foreach ($set as $col) {
                if (!isset($lookup[$col])) {
                    $allFound = false;
                    break;
                }
            }
            if ($allFound) {
                $sendColumns = $set;
                break;
            }
        }

        return [
            'report' => $reportColumn,
            'send' => $sendColumns,
            'company' => $this->detectCompanyColumn($lookup),
        ];
    }

    private function detectCompanyColumn(array $lookup): ?string
    {
        foreach (['Company', 'Company_Name', 'CompanyName'] as $col) {
            if (isset($lookup[$col])) {
                return $col;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{0:string,1:string,2:string}  $keys
     * @return array{to:list<string>,cc:list<string>,bcc:list<string>}
     */
    private function collectRecipientGroups(array $rows, array $keys): array
    {
        $recipients = $this->emptyRecipientGroups();
        $roles = ['to', 'cc', 'bcc'];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($keys as $index => $key) {
                if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
                    $role = $roles[$index] ?? 'to';
                    $recipients[$role] = array_merge(
                        $recipients[$role],
                        $this->parseRecipientList((string) $row[$key])
                    );
                }
            }
        }

        return $recipients;
    }

    /** @return array{to:list<string>,cc:list<string>,bcc:list<string>} */
    private function emptyRecipientGroups(): array
    {
        return ['to' => [], 'cc' => [], 'bcc' => []];
    }

    /** @param array{to:array,cc:array,bcc:array} $recipients */
    private function recipientGroupsAreEmpty(array $recipients): bool
    {
        return empty($recipients['to'])
            && empty($recipients['cc'])
            && empty($recipients['bcc']);
    }

    /**
     * Deduplicate recipients while preserving To > CC > BCC precedence.
     *
     * @param  array{to:array,cc:array,bcc:array}  $recipients
     * @return array{to:list<string>,cc:list<string>,bcc:list<string>}
     */
    private function normalizeRecipientGroups(array $recipients): array
    {
        $normalized = $this->emptyRecipientGroups();
        $seen = [];

        foreach (['to', 'cc', 'bcc'] as $role) {
            foreach ($recipients[$role] ?? [] as $email) {
                $email = trim((string) $email);
                $key = strtolower($email);
                if ($email === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $normalized[$role][] = $email;
            }
        }

        return $normalized;
    }

    /**
     * Log report execution to TblLog
     */
    private function logToTblLog(
        \Cmd\Reports\Services\DBConnector $connector,
        array $reportNames,
        array $companies,
        string $status
    ): void {
        try {
            $reportName = !empty($reportNames) ? $reportNames[0] : 'Unknown';
            $company = !empty($companies) ? implode(', ', $companies) : 'All';
            
            $tableName = str_replace("'", "''", 'TblReports');
            $macro = str_replace("'", "''", substr($reportName, 0, 50));
            $description = str_replace("'", "''", substr("Generated {$reportName} for {$company}", 0, 255));
            $actionLabel = str_replace("'", "''", substr(strtoupper($reportName), 0, 255));
            $resultSummary = str_replace("'", "''", substr("Status={$status} Company={$company}", 0, 200));
            $timestamp = str_replace("'", "''", now()->format('Y-m-d H:i:s'));

            $sql = <<<SQL
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

IF @hasPK = 1 AND @isIdentity = 0
BEGIN
    DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
    INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES (@nextPK, '{$tableName}', '{$macro}', '{$description}', '{$actionLabel}', '{$resultSummary}', '{$timestamp}');
END
ELSE
BEGIN
    INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES ('{$tableName}', '{$macro}', '{$description}', '{$actionLabel}', '{$resultSummary}', '{$timestamp}');
END;
SQL;

            $connector->querySqlServer($sql);
            Log::info("TblLog entry created for {$reportName}", ['company' => $company, 'status' => $status]);
        } catch (\Throwable $e) {
            Log::error("Failed to write to TblLog", [
                'reports' => $reportNames,
                'companies' => $companies,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatAttachments(array $attachments): array
    {
        $formatted = [];

        foreach ($attachments as $attachment) {
            if (!isset($attachment['name'], $attachment['contentType'], $attachment['contentBytes'])) {
                continue;
            }

            $formatted[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment['name'],
                'contentType' => $attachment['contentType'] ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => $attachment['contentBytes'],
            ];
        }

        return $formatted;
    }
}
