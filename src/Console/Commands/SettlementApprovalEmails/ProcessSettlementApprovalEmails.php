<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\SettlementApprovalEmails;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Cmd\Reports\Services\GraphMailboxClient;
use Cmd\Reports\SettlementApprovalEmail\ApprovalEmail;
use Cmd\Reports\SettlementApprovalEmail\ApprovalProcessor;
use Cmd\Reports\SettlementApprovalEmail\DatabaseApprovalLedger;
use Cmd\Reports\SettlementApprovalEmail\ForthApprovalGateway;
use Cmd\Reports\SettlementApprovalEmail\ReportApprovalNotifier;
use Cmd\Reports\SettlementApprovalEmail\SnapshotApprovalGateway;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessSettlementApprovalEmails extends Command
{
    public const MAILBOX = 'automation@libertydebtrelief.com';

    protected $signature = 'settlement-approval:process
        {--live : Apply changes; also requires SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED=true}
        {--dry-run : Explicit preview (also the default); no ledger, CRM, email or mailbox writes}
        {--company=all : all, ldr or plaw (PLAW is the Progress Law email route)}
        {--limit=100 : Maximum matching messages processed in one run, 1 to 500}
        {--message-id= : Process only this exact Inbox message ID, for controlled cutover tests}
        {--keep-in-inbox : Do not mark or archive completed messages}
        {--file= : Preview a saved UTF-8 email body instead of polling Outlook}
        {--sender= : Original system sender for --file}
        {--offer-file= : Offline preview using a saved offer JSON object with --file}';

    protected $description = 'Process legacy Settlement Approval Data emails using Forth APIs, replacing ProcessSettlementApproval Selenium.';
    private ?DBConnector $sql = null;

    public function handle(): int
    {
        $live = (bool) $this->option('live');
        try {
            if ($live && ($this->option('dry-run') || !filter_var(env('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED', false), FILTER_VALIDATE_BOOLEAN))) {
                throw new RuntimeException('Live mode requires the explicit enable switch and cannot be combined with --dry-run.');
            }
            $company = strtolower(trim((string) $this->option('company')));
            if (!in_array($company, ['all', 'ldr', 'plaw'], true)) throw new RuntimeException('Company must be all, ldr or plaw.');
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
            if ($limit === false) throw new RuntimeException('Limit must be between 1 and 500.');
            $file = trim((string) $this->option('file'));
            if ($live && ($file !== '' || $this->option('offer-file'))) throw new RuntimeException('Saved files can only be used in preview mode.');
            if ($this->option('offer-file') && $file === '') throw new RuntimeException('--offer-file requires --file.');
            $this->info($live ? 'LIVE: CRM, notification and mailbox writes are enabled.' : 'PREVIEW: no database, CRM, outgoing email or mailbox changes.');

            if ($file !== '') {
                $email = ApprovalEmail::parse($this->readFile($file), (string) $this->option('sender'), ApprovalEmail::SUBJECT_PREFIX);
                if ($company !== 'all' && strtolower($email->company) !== $company) throw new RuntimeException('File sender does not match --company.');
                $processor = $this->processor(false);
                $this->report($email, $processor->preview($email));
                return Command::SUCCESS;
            }
            if ($live && !DB::connection()->getSchemaBuilder()->hasTable(DatabaseApprovalLedger::TABLE)) {
                throw new RuntimeException('Publish and apply the settlement-approval-email migration before enabling live processing.');
            }
            $mail = $this->mailbox();
            $processor = null;
            $next = null;
            $seen = [];
            $count = 0;
            $scanned = 0;
            $failed = false;
            do {
                $page = $mail->approvalInboxPage(self::MAILBOX, ApprovalEmail::SUBJECT_PREFIX, $next);
                foreach ($page['messages'] as $message) {
                    if (++$scanned > 10000) throw new RuntimeException('Inbox scan bound reached; narrow the company/message selection.');
                    $id = (string) ($message['id'] ?? '');
                    $route = ApprovalEmail::company($this->sender($message), (string) ($message['subject'] ?? ''));
                    if ($route === null || ($company !== 'all' && strtolower($route) !== $company)) continue;
                    if ($this->option('message-id') && $id !== $this->option('message-id')) continue;
                    if ($id === '') throw new RuntimeException('Graph returned an empty approval message ID.');
                    if (++$count > $limit) {
                        $this->warn('Run limit reached; remaining approval emails stay in Inbox for a later run.');
                        break 2;
                    }
                    try {
                        $full = $mail->approvalMessage(self::MAILBOX, $id);
                        $body = $this->body($full);
                        $email = ApprovalEmail::parse($body, $this->sender($full), (string) ($full['subject'] ?? ''), (string) ($full['internetMessageId'] ?? $id));
                        if ($email->company !== $route) throw new RuntimeException('Message routing changed during processing.');
                        $processor ??= $this->processor($live);
                        if (!$live) {
                            $this->report($email, $processor->preview($email));
                            continue;
                        }
                        $outcome = $processor->process($email, $body);
                        $this->report($email, ['outcome' => $outcome]);
                        if (!in_array($outcome, ['completed', 'skipped'], true)) {
                            $failed = true;
                            continue;
                        }
                        if (!$this->option('keep-in-inbox')) {
                            $mail->markRead(self::MAILBOX, $id);
                            $mail->moveToArchive(self::MAILBOX, $id);
                        }
                    } catch (Throwable $e) {
                        $failed = true;
                        // Never print email bodies, API keys, HTTP request options or client records.
                        $this->error('Approval message left in Inbox: ' . $e->getMessage());
                    }
                }
                $next = $page['next'];
                if ($next !== null) {
                    if (!is_string($next) || isset($seen[$next])) throw new RuntimeException('Graph pagination did not advance.');
                    $seen[$next] = true;
                }
            } while ($next !== null);
            if ($this->option('message-id') && $count === 0) throw new RuntimeException('Selected message was not found in the matching Inbox route.');
            return $failed ? Command::FAILURE : Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function mailbox(): GraphMailboxClient { return GraphMailboxClient::fromEnvironment(); }

    protected function processor(bool $live): ApprovalProcessor
    {
        if ($this->option('offer-file')) {
            $data = json_decode($this->readFile((string) $this->option('offer-file')), true, 512, JSON_THROW_ON_ERROR);
            if ($data !== null && !is_array($data)) throw new RuntimeException('Offer fixture must be an object or null.');
            return new ApprovalProcessor(new SnapshotApprovalGateway($data));
        }
        $settings = [];
        foreach (['LDR', 'PLAW'] as $brand) {
            $visibility = env('SETTLEMENT_APPROVAL_EMAIL_NOTE_PUBLIC_' . $brand);
            $settings[$brand] = [
                'type' => env('SETTLEMENT_APPROVAL_EMAIL_NOTE_TYPE_' . $brand),
                'public' => $visibility === null ? null : filter_var($visibility, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ];
        }
        $crm = new ForthApprovalGateway(new Client(), function (string $brand): string {
            $data = $this->sql()->querySqlServer('SELECT API_Key FROM TblAPIKeys WHERE Category = ?', [$brand]);
            if (!($data['success'] ?? false) || count($data['data'] ?? []) !== 1) throw new RuntimeException('A unique Forth API key is required for ' . $brand . '.');
            return (string) $data['data'][0]['API_Key'];
        }, $settings);
        return $live
            ? new ApprovalProcessor($crm, new DatabaseApprovalLedger(DB::connection()), new ReportApprovalNotifier($this->sql(), new EmailSenderService()))
            : new ApprovalProcessor($crm);
    }

    protected function sql(): DBConnector
    {
        if ($this->sql === null) {
            $this->sql = DBConnector::fromEnvironment('plaw');
            $this->sql->initializeSqlServer();
        }
        return $this->sql;
    }

    private function sender(array $message): string
    {
        return (string) ($message['sender']['emailAddress']['address'] ?? $message['from']['emailAddress']['address'] ?? '');
    }

    private function body(array $message): string
    {
        $body = (string) ($message['body']['content'] ?? '');
        if (strtolower((string) ($message['body']['contentType'] ?? '')) === 'html') {
            $body = preg_replace('/<\s*(?:br\b[^>]*|\/p\s*|\/div\s*)>/i', "\n", $body);
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $body;
    }

    private function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > 2_000_000) throw new RuntimeException('Preview file is missing, unreadable or over 2 MB.');
        $body = file_get_contents($path);
        if ($body === false) throw new RuntimeException('Could not read preview file.');
        return $body;
    }

    private function report(ApprovalEmail $email, array $result): void
    {
        $this->line($email->company . ' settlement ' . $email->settlementId . ': ' . $result['outcome'] . (isset($result['target']) ? ' -> ' . $result['target'] : ''));
    }
}
