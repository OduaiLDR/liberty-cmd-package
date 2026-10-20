<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\SettlementApprovalEmails;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Cmd\Reports\Services\GraphMailboxClient;
use Cmd\Reports\SettlementApprovalEmail\ApprovalEmail;
use Cmd\Reports\SettlementApprovalEmail\ApprovalProcessor;
use Cmd\Reports\SettlementApprovalEmail\ApprovalRunLog;
use Cmd\Reports\SettlementApprovalEmail\DatabaseApprovalLedger;
use Cmd\Reports\SettlementApprovalEmail\ForthApprovalGateway;
use Cmd\Reports\SettlementApprovalEmail\ReportApprovalNotifier;
use Cmd\Reports\SettlementApprovalEmail\SnapshotApprovalGateway;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $log = new ApprovalRunLog(
            $live,
            static fn (string $level, string $event, array $context) => Log::log($level, $event, $context),
            fn (string $line) => $this->line($line),
            $this->output->isVerbose(),
        );
        $counts = array_fill_keys([
            'pages', 'scanned', 'selected', 'filtered', 'would_approve', 'would_skip',
            'completed', 'skipped', 'retry_offer_missing', 'busy', 'needs_review',
            'errors', 'archived', 'kept_in_inbox',
        ], 0);
        $exitCode = Command::FAILURE;
        $stopReason = 'exhausted';
        $log->event('run_started');
        $this->line('Run ID: ' . $log->id);
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
            $log->event('run_configured', [
                'company_filter' => $company, 'limit' => $limit,
                'source' => $file === '' ? 'inbox' : 'file',
                'message_filter' => (bool) $this->option('message-id'),
                'keep_in_inbox' => (bool) $this->option('keep-in-inbox'),
            ]);

            if ($file !== '') {
                $stopReason = 'file_preview';
                $log->event('file_read_started');
                $email = ApprovalEmail::parse($this->readFile($file), (string) $this->option('sender'), ApprovalEmail::SUBJECT_PREFIX);
                if ($company !== 'all' && strtolower($email->company) !== $company) throw new RuntimeException('File sender does not match --company.');
                $log->message(company: $email->company, settlementId: $email->settlementId);
                $counts['selected']++;
                $log->event('processor_initializing');
                $processor = $this->processor(false);
                $result = $processor->preview($email, $log);
                $counts[$result['outcome']]++;
                $this->report($email, $result);
                $log->event('message_result', $result);
                return $exitCode = Command::SUCCESS;
            }
            $log->event('mailbox_initializing');
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
                $log->message();
                $log->event('inbox_page_requested', ['page' => $counts['pages'] + 1]);
                $page = $mail->approvalInboxPage(self::MAILBOX, ApprovalEmail::SUBJECT_PREFIX, $next);
                $counts['pages']++;
                $log->event('inbox_page_received', [
                    'page' => $counts['pages'], 'message_count' => count($page['messages']),
                    'has_next_page' => $page['next'] !== null,
                ]);
                foreach ($page['messages'] as $message) {
                    $counts['scanned']++;
                    if (++$scanned > 10000) throw new RuntimeException('Inbox scan bound reached; narrow the company/message selection.');
                    $id = (string) ($message['id'] ?? '');
                    $route = ApprovalEmail::company($this->sender($message), (string) ($message['subject'] ?? ''));
                    $log->message($id, $route);
                    $filterReason = $route === null ? 'unrecognized_sender_or_subject'
                        : (($company !== 'all' && strtolower($route) !== $company) ? 'other_company'
                            : (($this->option('message-id') && $id !== $this->option('message-id')) ? 'other_message' : null));
                    if ($filterReason !== null) {
                        $counts['filtered']++;
                        $log->event('message_filtered', ['reason' => $filterReason]);
                        continue;
                    }
                    if ($id === '') throw new RuntimeException('Graph returned an empty approval message ID.');
                    if (++$count > $limit) {
                        $stopReason = 'limit';
                        $log->event('run_limit_reached', ['limit' => $limit]);
                        $this->warn('Run limit reached; remaining approval emails stay in Inbox for a later run.');
                        break 2;
                    }
                    $counts['selected']++;
                    try {
                        $log->event('message_fetch_started');
                        $full = $mail->approvalMessage(self::MAILBOX, $id);
                        $log->event('message_parse_started');
                        $body = $this->body($full);
                        $email = ApprovalEmail::parse($body, $this->sender($full), (string) ($full['subject'] ?? ''), (string) ($full['internetMessageId'] ?? $id));
                        if ($email->company !== $route) throw new RuntimeException('Message routing changed during processing.');
                        $log->message($id, $email->company, $email->settlementId);
                        $log->event('message_validated');
                        if ($processor === null) {
                            $log->event('processor_initializing');
                            $processor = $this->processor($live);
                        }
                        if (!$live) {
                            $result = $processor->preview($email, $log);
                            $counts[$result['outcome']]++;
                            $counts['kept_in_inbox']++;
                            $this->report($email, $result);
                            $log->event('message_result', $result);
                            $log->event('message_kept_in_inbox', ['reason' => 'preview']);
                            continue;
                        }
                        $outcome = $processor->process($email, $body, $log);
                        $counts[$outcome]++;
                        $this->report($email, ['outcome' => $outcome]);
                        $log->event('message_result', ['outcome' => $outcome]);
                        if (!in_array($outcome, ['completed', 'skipped'], true)) {
                            $failed = true;
                            $counts['kept_in_inbox']++;
                            $log->event('message_kept_in_inbox', ['reason' => $outcome], 'warning');
                            continue;
                        }
                        if (!$this->option('keep-in-inbox')) {
                            $log->event('message_mark_read_started');
                            $mail->markRead(self::MAILBOX, $id);
                            $log->event('message_marked_read');
                            $log->event('message_archive_started');
                            $mail->moveToArchive(self::MAILBOX, $id);
                            $counts['archived']++;
                            $log->event('message_archived');
                        } else {
                            $counts['kept_in_inbox']++;
                            $log->event('message_kept_in_inbox', ['reason' => 'keep_in_inbox_option']);
                        }
                    } catch (Throwable $e) {
                        $failed = true;
                        $counts['errors']++;
                        $counts['kept_in_inbox']++;
                        $this->error('Approval message left in Inbox: ' . $log->failure($e, 'message_failed'));
                    }
                }
                $log->message();
                $log->event('pagination_checked', ['has_next_page' => $page['next'] !== null]);
                $next = $page['next'];
                if ($next !== null) {
                    if (!is_string($next) || isset($seen[$next])) throw new RuntimeException('Graph pagination did not advance.');
                    $seen[$next] = true;
                }
            } while ($next !== null);
            if ($this->option('message-id') && $count === 0) throw new RuntimeException('Selected message was not found in the matching Inbox route.');
            return $exitCode = $failed ? Command::FAILURE : Command::SUCCESS;
        } catch (Throwable $e) {
            $counts['errors']++;
            $stopReason = 'error';
            $this->error($log->failure($e, 'run_failed'));
            return $exitCode;
        } finally {
            $log->message();
            $log->event('run_finished', [
                'exit_code' => $exitCode, 'stop_reason' => $stopReason, 'counts' => $counts,
            ], $exitCode === Command::SUCCESS ? 'info' : 'warning');
            $summary = [];
            foreach ($counts as $name => $value) $summary[] = $name . '=' . $value;
            $this->line('Summary: ' . implode(' ', $summary) . ' stop=' . $stopReason
                . ' exit_code=' . $exitCode . ' elapsed_ms=' . $log->elapsedMs());
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
        $details = isset($result['target']) ? ' -> ' . $result['target'] : '';
        if ($result['outcome'] === 'would_skip') {
            $details .= ' (current_status=' . $result['current_status'] . ' required_status=1194 reason=status_not_1194)';
        } elseif ($result['outcome'] === 'retry_offer_missing') {
            $details .= ' (offer not found; left for a later run)';
        }
        $this->line($email->company . ' settlement ' . $email->settlementId . ': ' . $result['outcome'] . $details);
    }
}
