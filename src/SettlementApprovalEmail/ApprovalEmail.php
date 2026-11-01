<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

/** The legacy email contract; intentionally independent of the portal's date-based API. */
final readonly class ApprovalEmail
{
    public const SUBJECT_PREFIX = 'Settlement Approval Data:';

    public function __construct(
        public string $company,
        public string $contactId,
        public string $settlementId,
        public string $creditor,
        public string $lastFour,
        public string $messageId = '',
    ) {}

    public static function company(string $sender, string $subject): ?string
    {
        if (stripos($subject, self::SUBJECT_PREFIX) !== 0) return null;
        return match (strtolower(trim($sender))) {
            'no-reply@libertydebtrelief.com', 'no-reply@paramountlaw.com' => 'LDR',
            'no-reply@progresslaw.com' => 'PLAW',
            default => null,
        };
    }

    public static function parse(string $body, string $sender, string $subject, string $messageId = ''): self
    {
        $company = self::company($sender, $subject);
        if ($company === null) throw new ApprovalEmailRejected('unrecognized_email');
        $fields = [];
        $labels = ['Settlement Approval For Customer Id', 'Creditor Name', 'Settlement ID', 'Last 4 Account Number'];
        $names = array_combine($labels, ['customer_id', 'creditor', 'settlement_id', 'last_four']);
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $parts = explode(':', $line, 2);
            $label = trim($parts[0]);
            if (!in_array($label, $labels, true)) continue;
            $value = trim($parts[1] ?? '');
            if (isset($fields[$label]) && $fields[$label] !== $value) {
                throw new ApprovalEmailRejected('conflicting_fields', [$names[$label] => 'conflicting']);
            }
            $fields[$label] = $value;
        }
        $contact = preg_replace('/^LLG-/i', '', $fields[$labels[0]] ?? '');
        $settlement = $fields[$labels[2]] ?? '';
        $creditor = $fields[$labels[1]] ?? '';
        $lastFour = $fields[$labels[3]] ?? '';
        $issues = [];
        foreach (['customer_id' => [$contact, '/^[1-9][0-9]*$/D'], 'settlement_id' => [$settlement, '/^[1-9][0-9]*$/D'],
            'creditor' => [$creditor, null], 'last_four' => [$lastFour, '/^[0-9]{4}$/D']] as $name => [$value, $pattern]) {
            if ($value === '') $issues[$name] = 'missing';
            elseif ($pattern !== null && !preg_match($pattern, $value)) $issues[$name] = 'invalid';
        }
        if ($issues !== []) throw new ApprovalEmailRejected('invalid_fields', $issues);
        return new self($company, $contact, $settlement, $creditor, $lastFour, $messageId);
    }

    public function key(): string
    {
        return hash('sha256', $this->company . ':' . $this->settlementId);
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode(':', [$this->company, $this->contactId, $this->settlementId, strtolower($this->creditor), $this->lastFour]));
    }

    public function note(): string
    {
        return '#' . $this->lastFour . ($this->company === 'PLAW' ? ' - CLIENT AUTHORIZATION OBTAINED - ' : ' - CLIENT AUTHORIZATION OBTAINED- ')
            . 'Client approved the settlement';
    }

    public static function targetLabel(int $paymentType): string
    {
        return in_array($paymentType, [15, 16, 132], true) ? 'Accepted' : 'Client complete/ P-Creditor';
    }
}
