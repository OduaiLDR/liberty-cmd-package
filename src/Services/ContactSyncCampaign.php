<?php

namespace Cmd\Reports\Services;

/** Campaign proof is separate from proof that two CRM contacts are the same person. */
final class ContactSyncCampaign
{
    public static function resolve(array $contact, array $candidates): array
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
        $campaigns = [];
        foreach ($exact as $candidate) {
            $campaign = trim((string) ($candidate['drop_name'] ?? ''));
            if ($campaign !== '' && self::sameRecipient($contact, $candidate)) {
                $campaigns[$campaign] = true;
            }
        }
        if (count($campaigns) !== 1) {
            return array_replace($proof, ['status' => 'unresolved', 'reason' => $campaigns === []
                ? 'Exact mailer key did not verify the recipient and campaign.'
                : 'More than one campaign belongs to the verified recipient and mailer key.']);
        }
        return array_replace($proof, ['status' => 'verified', 'campaign' => (string) array_key_first($campaigns),
            'reason' => 'Exact mailer key and recipient name/address verified.']);
    }

    /** Return the existing attribution unless a blank value has independent campaign proof. */
    public static function plan(array $row, ?array $before, ?array $proof, array $linked = [], bool $preserveUnverified = false): array
    {
        $candidate = trim((string) ($row['campaign'] ?? ''));
        $existing = trim((string) ($before['campaign'] ?? ''));
        $value = $before === null ? '' : $before['campaign'];
        $reject = static fn (string $reason) => ['value' => $value, 'reason' => $reason];
        if (($proof['status'] ?? null) === 'unresolved' && !($preserveUnverified && $existing !== ''
            && ($proof['candidate_campaigns'] ?? []) === [$existing])) {
            return $reject((string) ($proof['reason'] ?? 'Campaign attribution is unresolved.'));
        }
        if ($candidate !== '' && $existing !== '' && $candidate !== $existing) {
            return $reject('Existing campaign differs from the candidate; a reviewed attribution repair is required.');
        }
        $effective = $existing !== '' ? $existing : $candidate;
        foreach ($linked as $other) {
            $otherCampaign = trim((string) ($other['campaign'] ?? ''));
            if ($effective !== '' && $otherCampaign !== '' && $effective !== $otherCampaign) {
                return $reject('Linked contact campaigns disagree; existing attribution was preserved for review.');
            }
        }
        if ($candidate !== '' && $existing === '') {
            if (($proof['status'] ?? '') !== 'verified' || ($proof['campaign'] ?? '') !== $candidate
                || trim((string) ($proof['external_id'] ?? '')) === '') {
                return $reject('Assigning a campaign requires an exact mailer key and verified recipient.');
            }
            $value = $candidate;
        }
        return ['value' => $value, 'reason' => null];
    }

    private static function sameRecipient(array $contact, array $mailer): bool
    {
        $name = self::normalize($contact['client'] ?? $contact['fullname'] ?? '');
        $address = self::normalize($contact['address_1'] ?? $contact['address1'] ?? $contact['address'] ?? '');
        if ($name === '' || $address === '' || !self::sameName($contact['client'] ?? $contact['fullname'] ?? '', $mailer['client'] ?? '')
            || $address !== self::normalize($mailer['address'] ?? '')) {
            return false;
        }
        $same = [];
        foreach (['city', 'state', 'zip'] as $field) {
            $left = self::normalize($contact[$field] ?? '');
            $right = self::normalize($mailer[$field] ?? '');
            if ($field === 'zip') {
                $left = preg_match('/^[0-9]{5}(?:[0-9]{4})?$/D', $left) ? substr($left, 0, 5) : '';
                $right = preg_match('/^[0-9]{5}(?:[0-9]{4})?$/D', $right) ? substr($right, 0, 5) : '';
            }
            if ($left !== '' && $right !== '' && $left !== $right) {
                return false;
            }
            $same[$field] = $left !== '' && $left === $right;
        }
        return $same['zip'] || ($same['city'] && $same['state']);
    }

    private static function sameName($left, $right): bool
    {
        if (self::normalize($left) === self::normalize($right)) return true;
        $parts = static fn ($name) => array_values(array_filter(array_map([self::class, 'normalize'],
            preg_split('/\s+/u', trim((string) $name)) ?: []), fn ($part) => $part !== ''));
        $left = $parts($left);
        $right = $parts($right);
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

    private static function normalize($value): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtoupper(trim((string) $value), 'UTF-8')) ?? '';
    }
}
