<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Shared database leases; no dependence on a host-local cache or file lock. */
final class DatabaseApprovalLedger implements ApprovalLedger
{
    public const TABLE = 'settlement_approval_email_jobs';
    private array $tokens = [];

    public function __construct(private ConnectionInterface $db) {}

    public function claim(ApprovalEmail $email): ?array
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table(self::TABLE)->insertOrIgnore([
            'job_key' => $email->key(), 'fingerprint' => $email->fingerprint(),
            'company' => $email->company, 'contact_id' => $email->contactId,
            'settlement_id' => $email->settlementId, 'stage' => 'ready',
            'message_key' => hash('sha256', $email->messageId),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->db->table(self::TABLE)->where('job_key', $email->key())->first();
        if (!$row || $row->fingerprint !== $email->fingerprint()) {
            throw new RuntimeException('Conflicting approval details for this settlement; manual review required.');
        }
        $token = bin2hex(random_bytes(24));
        $updated = $this->db->table(self::TABLE)->where('job_key', $email->key())
            ->where(function ($q) use ($now): void {
                $q->whereNull('lease_until')->orWhere('lease_until', '<=', $now);
            })->update(['lease_token' => $token, 'lease_until' => gmdate('Y-m-d H:i:s', time() + 300), 'updated_at' => $now]);
        if ($updated !== 1) return null;
        $this->tokens[$email->key()] = $token;
        return (array) $this->db->table(self::TABLE)->where('job_key', $email->key())->first();
    }

    public function save(ApprovalEmail $email, array $changes): void
    {
        if (array_diff(array_keys($changes), ['stage', 'result', 'target_status', 'error_code'])) {
            throw new RuntimeException('Invalid ledger update.');
        }
        $updated = $this->db->table(self::TABLE)->where('job_key', $email->key())
            ->where('lease_token', $this->tokens[$email->key()] ?? '')
            ->where('lease_until', '>', gmdate('Y-m-d H:i:s'))
            ->update($changes + ['updated_at' => gmdate('Y-m-d H:i:s'), 'lease_until' => gmdate('Y-m-d H:i:s', time() + 300)]);
        if ($updated !== 1) throw new RuntimeException('Approval processing lease lost; no further writes are allowed.');
    }

    public function release(ApprovalEmail $email): void
    {
        $this->db->table(self::TABLE)->where('job_key', $email->key())
            ->where('lease_token', $this->tokens[$email->key()] ?? '')
            ->update(['lease_token' => null, 'lease_until' => null]);
        unset($this->tokens[$email->key()]);
    }
}
