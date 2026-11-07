<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Read-only eligibility facts. Historical paid commission is supplied by Payroll Review, never calculated here. */
final class SalesFinalRecaptureEligibility
{
    public const MAX_EXPLICIT_CLIENTS = 200;
    public const MAX_COHORT_CLIENTS = 2000;
    private ?DBConnector $sql = null;
    private array $portals = [];

    public function __construct(private ?\Closure $sqlRead = null, private ?\Closure $portalRead = null, private ?\Closure $clock = null) {}
    public static function supportsReadOnlyEligibility(): bool { return true; }

    public function inspect(string $period, ?array $clientIds = null): array
    {
        $month = self::date($period);
        if ($month === null || $month !== $period || substr($period, 8) !== '01') throw new \InvalidArgumentException('period must be an exact reporting month (YYYY-MM-01).');
        $historyStart = (new \DateTimeImmutable($period))->modify('-3 months')->format('Y-m-d');
        $historyEndExclusive = (new \DateTimeImmutable($period))->modify('+1 month')->format('Y-m-d');
        $explicit = $clientIds !== null;
        if ($explicit && (!array_is_list($clientIds) || count($clientIds) < 1 || count($clientIds) > self::MAX_EXPLICIT_CLIENTS)) throw new \InvalidArgumentException('clientIds must contain between 1 and 200 unique canonical LLG IDs.');
        $ids = [];
        foreach ($clientIds ?? [] as $id) {
            if (!is_string($id) || !preg_match('/\ALLG-[1-9]\d{0,17}\z/', $id) || isset($ids[$id])) throw new \InvalidArgumentException('clientIds must contain unique canonical LLG IDs.');
            $ids[$id] = true;
        }
        $enrollments = [];
        if ($explicit) {
            foreach (array_chunk(array_keys($ids), 100) as $chunk) {
                $rows = $this->readSql('SELECT LLG_ID, Agent, First_Payment_Cleared_Date, Cancel_Date FROM dbo.TblEnrollment WHERE LLG_ID IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
                foreach ($rows as $row) $this->appendEnrollment($row, $enrollments, $ids, true);
            }
        } else {
            $start = $historyStart;
            $end = (new \DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
            $rows = $this->readSql('SELECT TOP (2001) LLG_ID, Agent, First_Payment_Cleared_Date, Cancel_Date FROM dbo.TblEnrollment WHERE First_Payment_Cleared_Date >= ? AND First_Payment_Cleared_Date < ? ORDER BY LLG_ID', [$start, $end]);
            if (count($rows) > self::MAX_COHORT_CLIENTS) throw new \RuntimeException('The final-recapture cohort exceeds 2000 source rows. Split the review; no clients were silently truncated.');
            foreach ($rows as $row) $this->appendEnrollment($row, $enrollments, $ids, false);
        }
        ksort($ids, SORT_STRING);
        $employees = [];
        $names = [];
        foreach ($enrollments as $matches) foreach ($matches as $row) if (trim((string) self::value($row, 'Agent')) !== '') $names[trim((string) self::value($row, 'Agent'))] = true;
        foreach (array_chunk(array_keys($names), 100) as $chunk) {
            // Current names are review context only. Attribution belongs to the original finalized statement.
            try {
                foreach ($this->readSql('SELECT PK, Employee_Name FROM dbo.TblEmployees WHERE Employee_Name IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $row) $employees[self::nameKey(self::value($row, 'Employee_Name'))][] = $row;
            } catch (\Throwable) {
                // An unavailable current directory cannot invalidate verified client payment/cancellation facts.
            }
        }
        $facts = ['ldr' => [], 'plaw' => []];
        foreach (['ldr', 'plaw'] as $source) {
            foreach (array_chunk(array_keys($ids), 500) as $chunk) {
                $numeric = implode(',', array_map(static fn ($id) => substr($id, 4), $chunk));
                try {
                    $contacts = $this->readPortal($source, "SELECT ID, TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', DROPPED_DATE) AS DATE), 'YYYY-MM-DD') AS DROPPED_DATE FROM CONTACTS WHERE ID IN ($numeric) AND (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL) ORDER BY ID");
                    $payments = $this->readPortal($source, "SELECT CONTACT_ID, TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', CLEARED_DATE) AS DATE), 'YYYY-MM-DD') AS CLEARED_DATE, TRANS_TYPE, RETURNED_DATE, RETURN_CODE, _FIVETRAN_DELETED FROM TRANSACTIONS WHERE TRANS_TYPE = 'D' AND CLEARED_DATE IS NOT NULL AND RETURNED_DATE IS NULL AND (RETURN_CODE IS NULL OR RETURN_CODE = '') AND (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL) AND CONTACT_ID IN ($numeric) AND CAST(CONVERT_TIMEZONE('America/Los_Angeles', CLEARED_DATE) AS DATE) >= '$historyStart' AND CAST(CONVERT_TIMEZONE('America/Los_Angeles', CLEARED_DATE) AS DATE) < '$historyEndExclusive' ORDER BY CONTACT_ID, CLEARED_DATE");
                    foreach ($chunk as $id) $facts[$source][$id] = ['queryState' => 'verified', 'contacts' => [], 'clearedDates' => []];
                    foreach ($contacts as $row) {
                        $id = 'LLG-' . (string) self::value($row, 'ID');
                        if (!in_array($id, $chunk, true)) throw new \RuntimeException('Unexpected contact identity.');
                        $facts[$source][$id]['contacts'][] = ['droppedDate' => self::value($row, 'DROPPED_DATE')];
                    }
                    foreach ($payments as $row) {
                        $id = 'LLG-' . (string) self::value($row, 'CONTACT_ID');
                        if (!in_array($id, $chunk, true)) throw new \RuntimeException('Unexpected payment identity.');
                        if (self::value($row, 'TRANS_TYPE') !== 'D' || self::nonblank(self::value($row, 'RETURNED_DATE')) || self::nonblank(self::value($row, 'RETURN_CODE')) || in_array(strtolower((string) self::value($row, '_FIVETRAN_DELETED')), ['1', 'true'], true)) continue;
                        $date = self::date(self::value($row, 'CLEARED_DATE'));
                        if ($date === null) $facts[$source][$id]['invalidPaymentDate'] = true;
                        else $facts[$source][$id]['clearedDates'][] = $date;
                    }
                    foreach ($chunk as $id) sort($facts[$source][$id]['clearedDates'], SORT_STRING);
                } catch (\Throwable) {
                    foreach ($chunk as $id) $facts[$source][$id] = ['queryState' => 'unavailable'];
                }
            }
        }
        $rows = [];
        foreach (array_keys($ids) as $id) $rows[] = $this->row($id, $period, $enrollments[$id] ?? [], $employees, ['ldr' => $facts['ldr'][$id] ?? [], 'plaw' => $facts['plaw'][$id] ?? []]);
        $now = $this->clock ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $capturedAt = ($now instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable((string) $now))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        return ['ok' => true, 'protocolVersion' => 1, 'readOnly' => true, 'period' => $period, 'scope' => $explicit ? 'explicit_clients' : 'reporting_month_cohort', 'capturedAt' => $capturedAt,
            'rowCount' => count($rows), 'rows' => $rows, 'sourceSha256' => self::digest([$period, $capturedAt, $rows])];
    }

    private function row(string $id, string $period, array $enrollment, array $employees, array $portals): array
    {
        $reasons = [];
        $first = count($enrollment) === 1 ? self::date(self::value($enrollment[0], 'First_Payment_Cleared_Date')) : null;
        $agent = count($enrollment) === 1 ? trim((string) self::value($enrollment[0], 'Agent')) : '';
        $matches = $employees[self::nameKey($agent)] ?? [];
        $pk = count($matches) === 1 ? (string) self::value($matches[0], 'PK') : '';
        $employeePk = preg_match('/\A[1-9]\d*\z/', $pk) && (float) $pk <= 2147483647 ? (int) $pk : null;
        if (count($enrollment) !== 1) $reasons[] = count($enrollment) ? 'ambiguous_enrollment' : 'missing_enrollment';
        if ($first === null) $reasons[] = 'missing_or_invalid_first_cleared_date';
        $currentIdentityUnverified = $agent === '' || $employeePk === null;
        $cutoff = $first === null ? null : self::plusThreeMonths($first);
        $due = $cutoff !== null && substr($cutoff, 0, 7) === substr($period, 0, 7);
        $cancellations = [];
        if (count($enrollment) === 1) {
            $raw = self::value($enrollment[0], 'Cancel_Date');
            if (self::nonblank($raw)) {
                $date = self::date($raw);
                if ($date === null) $reasons[] = 'invalid_cancellation_date';
                else $cancellations['azure'] = $date;
            }
        }
        $counts = []; $hasContact = false;
        foreach ($portals as $source => $facts) {
            if (($facts['queryState'] ?? null) !== 'verified') { $reasons[] = $source . '_history_unavailable'; continue; }
            if (count($facts['contacts']) > 1) $reasons[] = $source . '_ambiguous_contact';
            $hasContact = $hasContact || count($facts['contacts']) === 1;
            if (count($facts['contacts']) === 1) {
                $raw = $facts['contacts'][0]['droppedDate'];
                if (self::nonblank($raw)) {
                    $date = self::date($raw);
                    if ($date === null) $reasons[] = 'invalid_cancellation_date';
                    else $cancellations[$source] = $date;
                }
            }
            if ($facts['invalidPaymentDate'] ?? false) $reasons[] = $source . '_invalid_cleared_payment_date';
            $counts[$source] = $cutoff === null ? null : count(array_filter($facts['clearedDates'], static fn ($date) => $date <= $cutoff));
        }
        if (!$hasContact) $reasons[] = 'missing_portal_contact';
        if ($cutoff !== null && count(array_unique(array_map(static fn ($date) => (int) ($date <= $cutoff), array_values($cancellations)))) > 1) $reasons[] = 'conflicting_cancellation_eligibility';
        $reasonCodes = array_values(array_unique($reasons));
        $paymentCount = $reasons === [] && $due ? max($counts['ldr'], $counts['plaw']) : null;
        $cancelDate = $cancellations ? min($cancellations) : null;
        $eligible = null;
        if ($reasons === []) {
            if (!$due) { $eligible = false; $reasonCodes[] = 'not_due'; }
            else {
                if ($paymentCount < 3) $reasonCodes[] = 'under_three_cleared_payments';
                if ($cancelDate !== null && $cancelDate <= $cutoff) $reasonCodes[] = 'cancelled_on_or_before_cutoff';
                $eligible = $paymentCount < 3 || ($cancelDate !== null && $cancelDate <= $cutoff);
            }
        }
        $row = ['clientId' => $id, 'status' => $reasons === [] ? 'verified' : 'pending', 'agent' => $agent, 'employeePk' => $employeePk,
            'currentIdentityUnverified' => $currentIdentityUnverified, 'informationalReasonCodes' => $currentIdentityUnverified ? ['unverified_current_employee_identity'] : [],
            'firstPaymentClearedDate' => $first, 'cutoffDate' => $cutoff, 'cancellationDate' => $cancelDate, 'clearedPaymentCount' => $paymentCount,
            'eligibleForTakeback' => $eligible, 'reasonCodes' => $reasonCodes, 'sourceProof' => ['basis' => 'dated_cleared_deposits_by_cutoff_max_mirrors_v1',
                'enrollmentRows' => $enrollment, 'cancellationDates' => $cancellations, 'portalPaymentCounts' => $counts, 'portals' => $portals]];
        return $row + ['sha256' => self::digest($row)];
    }

    private function appendEnrollment(array $row, array &$enrollments, array &$ids, bool $explicit): void
    {
        $id = trim((string) self::value($row, 'LLG_ID'));
        if (!preg_match('/\ALLG-[1-9]\d{0,17}\z/', $id) || $explicit && !isset($ids[$id])) throw new \RuntimeException('Enrollment source returned an unexpected client identity.');
        $ids[$id] = true; $enrollments[$id][] = $row;
    }
    private function readSql(string $query, array $bindings): array
    {
        if ($this->sqlRead) $result = ($this->sqlRead)($query, $bindings);
        else { if ($this->sql === null) { $this->sql = DBConnector::fromEnvironment('ldr'); $this->sql->initializeSqlServer(); } $result = $this->sql->querySqlServer($query, $bindings); }
        return self::rows($result);
    }
    private function readPortal(string $source, string $query): array
    {
        if ($this->portalRead) $result = ($this->portalRead)($source, $query);
        else { $this->portals[$source] ??= DBConnector::fromEnvironment($source); $result = $this->portals[$source]->query($query); }
        return self::rows($result);
    }
    private static function rows(mixed $response): array
    {
        if (!is_array($response) || ($response['success'] ?? null) === false || !is_array($response['data'] ?? null) || !array_is_list($response['data'])) throw new \RuntimeException('Eligibility source query could not be verified.');
        foreach ($response['data'] as $row) if (!is_array($row)) throw new \RuntimeException('Eligibility source rows are malformed.');
        return $response['data'];
    }
    private static function value(array $row, string $name): mixed { foreach ($row as $key => $value) if (strcasecmp((string) $key, $name) === 0) return $value; return null; }
    private static function nonblank(mixed $value): bool { return $value !== null && ($value instanceof \DateTimeInterface || trim((string) $value) !== ''); }
    private static function nameKey(mixed $value): string { return strtolower(trim((string) $value)); }
    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?)?\z/', $value)) return null;
        $short = substr($value, 0, 10); $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $short);
        return $date !== false && $date->format('Y-m-d') === $short ? $short : null;
    }
    private static function plusThreeMonths(string $date): string
    {
        $target = (new \DateTimeImmutable(substr($date, 0, 7) . '-01'))->modify('+3 months');
        return $target->format('Y-m-') . str_pad((string) min((int) substr($date, 8), (int) $target->format('t')), 2, '0', STR_PAD_LEFT);
    }
    private static function digest(mixed $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
}
