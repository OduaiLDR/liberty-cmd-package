<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

/**
 * Finds the ONE debt on a contact that a Remove Creditor request refers to.
 *
 * Both Remove Creditor actions used to carry a private findDebt() that matched on
 * creditor name alone and returned the first hit. Two problems with that, and the
 * second is the dangerous one:
 *
 *  1. It compared the payload's creditor_id against the DEBT's id. Different id
 *     spaces - measured 2026-09-02, debt 148442930 belongs to creditor 2435 - so
 *     that branch could only ever match by coincidence.
 *  2. A client can hold two accounts with the same creditor. Matching on name and
 *     taking the first hit deletes an arbitrary one of them.
 *
 * The legacy VBA was stricter: it matched creditor name AND balance (or account
 * number) AND the "included" flag before ticking a row for removal
 * (working reference §4.4). This restores that, and fails closed - an ambiguous
 * match returns null so the action captures for manual review, because deleting
 * the wrong creditor's debt is far worse than an email to a human.
 *
 * Forth's debt shape, read off production 2026-09-02:
 *   id, original_debt_amount, current_debt_amount, og_account_num,
 *   creditor_account_num, enrolled, and creditor => {id, company_name, ...}
 */
final class PmodDebtMatcher
{
    /**
     * @param list<array<string, mixed>> $debts
     * @param array<string, mixed> $creditorChange
     * @return array{debt: array<string, mixed>|null, reason: string, candidates: int}
     */
    public static function match(array $debts, array $creditorChange): array
    {
        $name = self::text($creditorChange['creditor_name'] ?? null);
        $creditorId = self::text($creditorChange['creditor_id'] ?? null);
        $accountNumber = self::text($creditorChange['account_number'] ?? null);
        $balance = self::money($creditorChange['balance'] ?? null);

        if ($debts === []) {
            return ['debt' => null, 'reason' => 'no_debts_on_contact', 'candidates' => 0];
        }

        if ($name === null && $creditorId === null) {
            return ['debt' => null, 'reason' => 'no_creditor_identifier', 'candidates' => 0];
        }

        // A claimed creditor id is matched against the debt's OWN creditor id.
        $candidates = array_values(array_filter($debts, static function (array $debt) use ($name, $creditorId): bool {
            if ($creditorId !== null && self::text($debt['creditor']['id'] ?? null) === $creditorId) {
                return true;
            }

            if ($name === null) {
                return false;
            }

            return self::normalizeName((string) ($debt['creditor']['company_name'] ?? $debt['creditor_name'] ?? ''))
                === self::normalizeName($name);
        }));

        if ($candidates === []) {
            return ['debt' => null, 'reason' => 'creditor_not_on_contact', 'candidates' => 0];
        }

        $total = count($candidates);

        // Each narrowing is applied only when it leaves something behind, so a
        // payload carrying a stale balance cannot wipe out a correct name match.
        $candidates = self::narrow($candidates, static fn (array $debt): bool =>
            $accountNumber !== null && (
                self::text($debt['og_account_num'] ?? null) === $accountNumber
                || self::text($debt['creditor_account_num'] ?? null) === $accountNumber
            ));

        $candidates = self::narrow($candidates, static fn (array $debt): bool =>
            $balance !== null && (
                self::money($debt['original_debt_amount'] ?? null) === $balance
                || self::money($debt['current_debt_amount'] ?? null) === $balance
            ));

        // The VBA only removed rows flagged as included in the program, which is
        // what `enrolled` mirrors. Preferred rather than required: a contact whose
        // debts are all enrolled=0 should still be actionable when the match is
        // otherwise unambiguous.
        $candidates = self::narrow($candidates, static fn (array $debt): bool =>
            (string) ($debt['enrolled'] ?? '') === '1');

        if (count($candidates) === 1) {
            return ['debt' => $candidates[0], 'reason' => 'matched', 'candidates' => $total];
        }

        return ['debt' => null, 'reason' => 'ambiguous_creditor_match', 'candidates' => count($candidates)];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param callable(array<string, mixed>): bool $predicate
     * @return list<array<string, mixed>>
     */
    private static function narrow(array $candidates, callable $predicate): array
    {
        if (count($candidates) < 2) {
            return $candidates;
        }

        $narrowed = array_values(array_filter($candidates, $predicate));

        return $narrowed === [] ? $candidates : $narrowed;
    }

    private static function normalizeName(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9 ]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function text(mixed $value): ?string
    {
        if (is_array($value) || $value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Compare money as a 2dp string so "1993", "1993.00" and "$1,993.00" agree. */
    private static function money(mixed $value): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        $clean = str_replace(['$', ',', ' '], '', $value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $clean) !== 1) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }
}
