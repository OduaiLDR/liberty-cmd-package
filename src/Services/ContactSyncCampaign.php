<?php

namespace Cmd\Reports\Services;

/** Campaign proof is separate from proof that two CRM contacts are the same person. */
final class ContactSyncCampaign
{
    public const RECIPIENT_UNVERIFIED = 'Exact mailer key did not verify the recipient and campaign.';
    public const MULTIPLE_CAMPAIGNS = 'More than one campaign belongs to the verified recipient and mailer key.';

    public static function resolve(array $contact, array $candidates, bool $authoritativeOffer = false): array
    {
        $contact = array_change_key_case($contact, CASE_LOWER);
        $key = trim((string) ($contact['external_id'] ?? ''));
        $proof = ['status' => 'none', 'campaign' => '', 'external_id' => $key, 'candidate_campaigns' => [],
            'reason' => 'No exact mailer candidate.'];
        if ($key === '') {
            return $proof;
        }
        $exact = [];
        foreach ($candidates as $candidate) {
            $candidate = array_change_key_case($candidate, CASE_LOWER);
            if (trim((string) ($candidate['external_id'] ?? '')) === $key) {
                $exact[] = $candidate;
            }
        }
        if ($exact === []) {
            return $proof;
        }
        $proof['candidate_campaigns'] = array_values(array_unique(array_filter(array_map(
            fn ($row) => trim((string) ($row['drop_name'] ?? '')), $exact), fn ($name) => $name !== '')));
        // LT's recorded offer determines campaign credit even when the respondent
        // differs from the mailed recipient. This does not prove contact identity.
        // Reused keys still need recipient disambiguation; incomplete campaigns
        // cannot establish an authoritative one-campaign association.
        if ($authoritativeOffer && !in_array($key, ['0', '1234567840', 'UNKNOWN'], true)
            && count($proof['candidate_campaigns']) === 1
            && !array_filter($exact, fn ($row) => trim((string) ($row['drop_name'] ?? '')) === '')) {
            return array_replace($proof, ['status' => 'verified', 'campaign' => $proof['candidate_campaigns'][0],
                'reason' => 'Authoritative LT offer ID identifies one campaign.']);
        }
        $campaigns = [];
        $recipients = [];
        $usedOmission = $blankCampaign = false;
        foreach ($exact as $candidate) {
            $campaign = trim((string) ($candidate['drop_name'] ?? ''));
            if (self::sameRecipient($contact, $candidate, true, $omittedDirection)) {
                $recipients[] = $candidate;
                $usedOmission = $usedOmission || $omittedDirection;
                $blankCampaign = $blankCampaign || $campaign === '';
                if ($campaign !== '') $campaigns[$campaign] = true;
            }
        }
        // A missing direction cannot choose between plausible recipients, even in
        // one campaign. Check every pair so a missing middle name cannot bridge two people.
        if ($usedOmission) {
            foreach ($recipients as $index => $recipient) {
                foreach (array_slice($recipients, $index + 1) as $other) {
                    if (!self::sameRecipient($recipient, $other)) {
                        return array_replace($proof, ['status' => 'unresolved', 'reason' => self::RECIPIENT_UNVERIFIED]);
                    }
                }
            }
            if ($blankCampaign) return array_replace($proof, ['status' => 'unresolved', 'reason' => self::RECIPIENT_UNVERIFIED]);
        }
        if (count($campaigns) !== 1) {
            return array_replace($proof, ['status' => 'unresolved', 'reason' => $campaigns === []
                ? self::RECIPIENT_UNVERIFIED
                : self::MULTIPLE_CAMPAIGNS]);
        }
        return array_replace($proof, ['status' => 'verified', 'campaign' => (string) array_key_first($campaigns),
            'reason' => $usedOmission ? 'Exact mailer key, recipient and geography verified with one omitted address direction.'
                : 'Exact mailer key and recipient name/address verified.']);
    }

    /** Return the existing attribution unless a blank value has independent campaign proof. */
    public static function plan(array $row, ?array $before, ?array $proof, array $linked = [], bool $preserveUnverified = false): array
    {
        $candidate = trim((string) ($row['campaign'] ?? ''));
        $existing = trim((string) ($before['campaign'] ?? ''));
        $value = $before === null ? '' : $before['campaign'];
        $reject = static fn (string $reason) => ['value' => $value, 'reason' => $reason];
        $preserve = static fn (string $reason) => $before === null ? $reject($reason)
            : ['value' => $value, 'reason' => null, 'preserve_attribution' => true, 'review_reason' => $reason];
        // Target identity/ownership is checked by the caller. Retaining attribution
        // does not require proving the historical recipient again or create new proof.
        if (($proof['status'] ?? null) === 'unresolved'
            && in_array($proof['reason'] ?? '', [self::RECIPIENT_UNVERIFIED, self::MULTIPLE_CAMPAIGNS], true)) {
            return $preserve($proof['reason']);
        }
        // A fresh disagreement about the backend's LT identity is not mailing ambiguity.
        if (($proof['status'] ?? null) === 'unresolved' && !($preserveUnverified && $existing !== ''
            && ($proof['candidate_campaigns'] ?? []) === [$existing])) {
            return $reject((string) ($proof['reason'] ?? 'Campaign attribution is unresolved.'));
        }
        if ($candidate !== '' && $existing !== '' && $candidate !== $existing) {
            return $preserve('Existing campaign differs from the candidate; a reviewed attribution repair is required.');
        }
        $effective = $existing !== '' ? $existing : $candidate;
        foreach ($linked as $other) {
            $otherCampaign = trim((string) ($other['campaign'] ?? ''));
            if ($effective !== '' && $otherCampaign !== '' && $effective !== $otherCampaign) {
                return $preserve('Linked contact campaigns disagree; existing attribution was preserved for review.');
            }
        }
        if ($candidate !== '' && $existing === '') {
            if (($proof['status'] ?? '') !== 'verified' || ($proof['campaign'] ?? '') !== $candidate
                || trim((string) ($proof['external_id'] ?? '')) === '') {
                return $preserve('Assigning a campaign requires verified attribution for the exact mailer key.');
            }
            $value = $candidate;
        }
        return ['value' => $value, 'reason' => null, 'preserve_attribution' => false];
    }

    private static function sameRecipient(array $contact, array $mailer, bool $allowOmission = false, ?bool &$omission = null): bool
    {
        $omission = false;
        $name = self::normalize($contact['client'] ?? $contact['fullname'] ?? '');
        $street = (string) ($contact['address_1'] ?? $contact['address1'] ?? $contact['address'] ?? '');
        $mailerStreet = (string) ($mailer['address'] ?? '');
        $address = self::normalize($street);
        if ($name === '' || $address === '' || !self::sameName($contact['client'] ?? $contact['fullname'] ?? '', $mailer['client'] ?? '')) {
            return false;
        }
        if ($address !== self::normalize($mailerStreet) && !ContactSyncAddress::matches($street, $mailerStreet)) {
            if (!$allowOmission || !ContactSyncAddress::matchesWithOmittedDirection($street, $mailerStreet)) return false;
            if (count(self::nameParts($contact['client'] ?? $contact['fullname'] ?? '')) < 2
                || count(self::nameParts($mailer['client'] ?? '')) < 2) return false;
            $omission = true;
        }
        $same = [];
        foreach (['city', 'state', 'zip'] as $field) {
            $left = self::normalize($contact[$field] ?? '');
            $right = self::normalize($mailer[$field] ?? '');
            if ($field === 'zip') {
                $left = preg_match('/^[0-9]{5}(?:[0-9]{4})?$/D', $left) ? substr($left, 0, 5) : '';
                $right = preg_match('/^[0-9]{5}(?:[0-9]{4})?$/D', $right) ? substr($right, 0, 5) : '';
                if ($omission && ($left === '00000' || $right === '00000')) return false;
            }
            if ($left !== '' && $right !== '' && $left !== $right) {
                return false;
            }
            $same[$field] = $left !== '' && $left === $right;
        }
        return $omission ? $same['zip'] && $same['state'] : $same['zip'] || ($same['city'] && $same['state']);
    }

    private static function sameName($left, $right): bool
    {
        if (self::normalize($left) === self::normalize($right)) return true;
        $left = self::nameParts($left);
        $right = self::nameParts($right);
        if (count($left) < 2 || count($right) < 2 || $left[0] !== $right[0]
            || end($left) !== end($right)) return false;
        $left = array_slice($left, 1, -1);
        $right = array_slice($right, 1, -1);
        if ($left === [] || $right === []) return true;
        if (count($left) !== count($right)) return false;
        foreach ($left as $index => $part) {
            $other = $right[$index];
            if ($part !== $other && !(mb_substr($part, 0, 1) === mb_substr($other, 0, 1)
                && (mb_strlen($part) === 1 || mb_strlen($other) === 1))) return false;
        }
        return true;
    }

    private static function nameParts($name): array
    {
        return array_values(array_filter(array_map([self::class, 'normalize'],
            preg_split('/\s+/u', trim((string) $name)) ?: []), fn ($part) => $part !== ''));
    }

    private static function normalize($value): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtoupper(trim((string) $value), 'UTF-8')) ?? '';
    }
}
