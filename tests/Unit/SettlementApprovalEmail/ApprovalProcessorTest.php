<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\SettlementApprovalEmail;

use Cmd\Reports\SettlementApprovalEmail\{ApprovalEmail, ApprovalGateway, ApprovalLedger, ApprovalNotifier, ApprovalProcessor};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovalProcessorTest extends TestCase
{
    private function email(string $company = 'LDR', string $message = 'mail-1'): ApprovalEmail
    {
        return new ApprovalEmail($company, '123', '456', 'Example Bank', '0012', $message);
    }

    private function setupFlow(): array
    {
        $crm = new FakeApprovalCrm();
        $ledger = new FakeApprovalLedger();
        $mail = new FakeApprovalMail();
        return [new ApprovalProcessor($crm, $ledger, $mail), $crm, $ledger, $mail];
    }

    public function test_vba_payment_type_rules_for_both_companies_and_exact_note(): void
    {
        foreach (['LDR', 'PLAW'] as $company) {
            foreach ([15, 16, 132, 6, 20, 21, 999] as $type) {
                [$flow, $crm, $ledger, $mail] = $this->setupFlow();
                $crm->record['json'] = json_encode(['pay_type' => (string) $type]);
                $email = $this->email($company);
                self::assertSame('completed', $flow->process($email, 'original body'));
                self::assertSame(in_array($type, [15, 16, 132], true) ? 'Accepted' : 'Client complete/ P-Creditor', $crm->label);
                self::assertSame(['status', 'note'], $crm->writes);
                self::assertSame($company === 'LDR'
                    ? '#0012 - CLIENT AUTHORIZATION OBTAINED- Client approved the settlement'
                    : '#0012 - CLIENT AUTHORIZATION OBTAINED - Client approved the settlement', $crm->note);
                self::assertSame([['Client Approved Settlement', 'original body']], $mail->sent);
                self::assertSame('completed', $ledger->rows[$email->key()]['stage']);
                self::assertSame(1, $ledger->releases);
            }
        }
    }

    public function test_preview_has_no_processing_or_external_side_effects(): void
    {
        [$flow, $crm, $ledger, $mail] = $this->setupFlow();
        self::assertSame(['outcome' => 'would_approve', 'target' => 'Accepted'], $flow->preview($this->email()));
        self::assertSame([], $ledger->rows);
        self::assertSame([], $crm->writes);
        self::assertSame([], $mail->sent);
        $crm->record = null;
        self::assertSame(['outcome' => 'retry_offer_missing'], $flow->preview($this->email()));
    }

    public function test_missing_offer_is_retryable_without_note_or_notification(): void
    {
        [$flow, $crm, $ledger, $mail] = $this->setupFlow();
        $crm->record = null;
        self::assertSame('retry_offer_missing', $flow->process($this->email(), 'body'));
        self::assertSame([], $crm->writes);
        self::assertSame([], $mail->sent);
        self::assertSame('ready', $ledger->rows[$this->email()->key()]['stage']);
    }

    public function test_ineligible_status_sends_skipped_result_and_is_not_approved(): void
    {
        [$flow, $crm, $ledger, $mail] = $this->setupFlow();
        $crm->record['offer_status'] = '2590';
        self::assertSame('skipped', $flow->process($this->email(), 'body'));
        self::assertSame([], $crm->writes);
        self::assertSame('Client Approved Settlement - Skipped', $mail->sent[0][0]);
        self::assertSame('skipped', $flow->process($this->email(message: 'duplicate'), 'body'));
        self::assertCount(1, $mail->sent);
    }

    public function test_duplicate_email_for_same_settlement_cannot_repeat_any_effect(): void
    {
        [$flow, $crm, $ledger, $mail] = $this->setupFlow();
        self::assertSame('completed', $flow->process($this->email(), 'body'));
        self::assertSame('completed', $flow->process($this->email(message: 'second-email'), 'body'));
        self::assertSame(['status', 'note'], $crm->writes);
        self::assertCount(1, $mail->sent);
        self::assertCount(1, $ledger->rows);
    }

    public function test_customer_mismatch_or_bad_payment_data_never_mutates_crm(): void
    {
        foreach ([['contact_id' => '999'], ['json' => '{}'], ['json' => 'broken json']] as $patch) {
            [$flow, $crm, $ledger, $mail] = $this->setupFlow();
            $crm->record = array_replace($crm->record, $patch);
            try { $flow->process($this->email(), 'body'); self::fail('Expected validation failure.'); }
            catch (\RuntimeException|\JsonException $e) { self::assertNotSame('', $e->getMessage()); }
            self::assertSame([], $crm->writes);
            self::assertSame([], $mail->sent);
            self::assertSame(1, $ledger->releases);
        }
    }

    public function test_successful_status_put_followed_by_timeout_is_reconciled_without_second_put(): void
    {
        [$flow, $crm, $ledger, $mail] = $this->setupFlow();
        $crm->fail = 'after_status';
        try { $flow->process($this->email(), 'body'); self::fail('Expected timeout.'); } catch (RuntimeException) {}
        self::assertSame('status_attempting', $ledger->rows[$this->email()->key()]['stage']);
        $crm->fail = '';
        self::assertSame('completed', $flow->process($this->email(), 'body'));
        self::assertSame(['status', 'note'], $crm->writes);
        self::assertCount(1, $mail->sent);
    }

    public function test_unverified_status_attempt_is_reviewed_instead_of_retried(): void
    {
        [$flow, $crm, $ledger, $mail] = $this->setupFlow();
        $crm->fail = 'before_status';
        try { $flow->process($this->email(), 'body'); self::fail('Expected timeout.'); } catch (RuntimeException) {}
        $crm->fail = '';
        self::assertSame('needs_review', $flow->process($this->email(), 'body'));
        self::assertSame([], $crm->writes);
        self::assertSame([], $mail->sent);
    }

    public function test_uncertain_note_or_notification_is_never_automatically_repeated(): void
    {
        foreach (['note', 'mail'] as $fault) {
            [$flow, $crm, $ledger, $mail] = $this->setupFlow();
            if ($fault === 'note') $crm->fail = 'after_note';
            else $mail->fail = true;
            try { $flow->process($this->email(), 'body'); self::fail('Expected uncertain operation.'); } catch (RuntimeException) {}
            $crm->fail = ''; $mail->fail = false;
            self::assertSame('needs_review', $flow->process($this->email(), 'body'));
            self::assertSame(['status', 'note'], $crm->writes);
            self::assertCount($fault === 'mail' ? 1 : 0, $mail->sent);
            self::assertSame('needs_review', $flow->process($this->email(), 'body'));
        }
    }

    public function test_missing_recipients_blocks_the_status_change_and_can_be_fixed(): void
    {
        [$flow, $crm, $ledger, $mail] = $this->setupFlow();
        $mail->ready = false;
        try { $flow->process($this->email(), 'body'); self::fail('Expected preflight failure.'); } catch (RuntimeException) {}
        self::assertSame([], $crm->writes);
        self::assertSame('ready', $ledger->rows[$this->email()->key()]['stage']);
        $mail->ready = true;
        self::assertSame('completed', $flow->process($this->email(), 'body'));
    }

    public function test_a_competing_worker_does_not_touch_the_crm(): void
    {
        [$flow, $crm, $ledger, $mail] = $this->setupFlow();
        $ledger->busy = true;
        self::assertSame('busy', $flow->process($this->email(), 'body'));
        self::assertSame([], $crm->writes);
    }

    public function test_a_later_status_change_blocks_a_resumed_authorization_note(): void
    {
        [$flow, $crm, $ledger, $mail] = $this->setupFlow();
        $email = $this->email();
        $ledger->rows[$email->key()] = ['stage' => 'status_updated', 'target_status' => '7', 'result' => 'completed'];
        $crm->record['offer_status'] = 99;
        self::assertSame('needs_review', $flow->process($email, 'body'));
        self::assertSame([], $crm->writes);
        self::assertSame([], $mail->sent);
    }
}

final class FakeApprovalCrm implements ApprovalGateway
{
    public ?array $record = ['id' => '456', 'contact_id' => '123', 'offer_status' => '1194', 'json' => ['pay_type' => 15]];
    public array $writes = [];
    public string $fail = '';
    public string $label = '';
    public string $note = '';
    public function offer(ApprovalEmail $email): ?array { return $this->record; }
    public function statusId(ApprovalEmail $email, string $label): string { $this->label = $label; return $label === 'Accepted' ? '7' : '99'; }
    public function assertReady(ApprovalEmail $email): void {}
    public function updateStatus(ApprovalEmail $email, string $statusId): void
    {
        if ($this->fail === 'before_status') throw new RuntimeException('timeout');
        $this->writes[] = 'status'; $this->record['offer_status'] = $statusId;
        if ($this->fail === 'after_status') throw new RuntimeException('timeout');
    }
    public function addNote(ApprovalEmail $email): void
    {
        $this->writes[] = 'note'; $this->note = $email->note();
        if ($this->fail === 'after_note') throw new RuntimeException('timeout');
    }
}

final class FakeApprovalLedger implements ApprovalLedger
{
    public array $rows = [];
    public int $releases = 0;
    public bool $busy = false;
    public function claim(ApprovalEmail $email): ?array
    {
        if ($this->busy) return null;
        return $this->rows[$email->key()] ??= ['stage' => 'ready'];
    }
    public function save(ApprovalEmail $email, array $changes): void { $this->rows[$email->key()] = array_replace($this->rows[$email->key()], $changes); }
    public function release(ApprovalEmail $email): void { $this->releases++; }
}

final class FakeApprovalMail implements ApprovalNotifier
{
    public array $sent = [];
    public bool $fail = false;
    public bool $ready = true;
    public function assertReady(): void { if (!$this->ready) throw new RuntimeException('recipients missing'); }
    public function send(string $subject, string $body): void
    {
        $this->sent[] = [$subject, $body];
        if ($this->fail) throw new RuntimeException('timeout');
    }
}
