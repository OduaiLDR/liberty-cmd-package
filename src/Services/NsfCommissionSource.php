<?php

namespace Cmd\Reports\Services;

/** One checked source contract for NSF workbooks, details, and manager inputs. */
final class NsfCommissionSource
{
    public static function fetch(DBConnector $sf, array $cfg, string $start, string $end, ?string $agent = null): array
    {
        if (!self::dateValid($start) || !self::dateValid($end) || $end < $start) {
            throw new \InvalidArgumentException('Invalid NSF source date range.');
        }
        foreach (['custom_agent', 'custom_nsf_return', 'custom_nsf_action', 'custom_nsf_recoup'] as $key) {
            if (!isset($cfg[$key]) || !ctype_digit((string) $cfg[$key]) || (int) $cfg[$key] <= 0) {
                throw new \InvalidArgumentException('Invalid NSF custom-field configuration.');
            }
        }
        [$agentId, $returnId, $actionId, $recoupId] = array_map(
            static fn (string $key): int => (int) $cfg[$key],
            ['custom_agent', 'custom_nsf_return', 'custom_nsf_action', 'custom_nsf_recoup']
        );
        $cutoff = (new \DateTimeImmutable($start))->modify('first day of next month')->format('Y-m-05');
        // Group before joining: deleted Fivetran versions previously multiplied
        // a single client into thousands of rows. MIN only supplies a value when
        // its independent variant count proves that there is no choice to make.
        $query = "
            WITH active_fields AS (
                SELECT CONTACT_ID, CUSTOM_ID, F_SHORTSTRING, F_STRING, F_DATE
                FROM CONTACTS_USERFIELDS
                WHERE (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL)
                  AND CUSTOM_ID IN ($agentId, $returnId, $actionId, $recoupId)
            ), agents AS (
                SELECT CONTACT_ID,
                    MIN(REGEXP_REPLACE(TRIM(COALESCE(F_SHORTSTRING, '')), '[[:space:]]+', ' ')) AS AGENT,
                    COUNT(DISTINCT UPPER(REGEXP_REPLACE(TRIM(COALESCE(F_SHORTSTRING, '')), '[[:space:]]+', ' '))) AS AGENT_VARIANTS
                FROM active_fields WHERE CUSTOM_ID = $agentId GROUP BY CONTACT_ID
            ), returned AS (
                SELECT CONTACT_ID, MIN(F_DATE) AS NSF_RETURNED_DATE,
                    COUNT(DISTINCT F_DATE) + MAX(IFF(F_DATE IS NULL, 1, 0)) AS RETURN_VARIANTS,
                    MAX(IFF(F_DATE >= '$start' AND F_DATE <= '$end', 1, 0)) AS IN_PERIOD
                FROM active_fields WHERE CUSTOM_ID = $returnId GROUP BY CONTACT_ID
            ), actions AS (
                SELECT CONTACT_ID, MIN(TRIM(COALESCE(F_STRING, ''))) AS NSF_ACTION,
                    COUNT(DISTINCT TRIM(COALESCE(F_STRING, ''))) AS ACTION_VARIANTS
                FROM active_fields WHERE CUSTOM_ID = $actionId GROUP BY CONTACT_ID
            ), recoups AS (
                SELECT CONTACT_ID, MIN(F_DATE) AS NSF_RECOUP_DATE,
                    COUNT(DISTINCT F_DATE) + MAX(IFF(F_DATE IS NULL, 1, 0)) AS RECOUP_VARIANTS
                FROM active_fields WHERE CUSTOM_ID = $recoupId GROUP BY CONTACT_ID
            ), candidates AS (
                SELECT r.* FROM returned r
                WHERE r.IN_PERIOD = 1 AND EXISTS (SELECT 1 FROM CONTACTS c WHERE c.ID = r.CONTACT_ID)
            ), active_payments AS (
                SELECT t.CONTACT_ID,
                    TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', t.CLEARED_DATE) AS DATE), 'YYYY-MM-DD') AS CLEARED_DATE,
                    CONVERT_TIMEZONE('America/Los_Angeles', t.PROCESS_DATE) AS PROCESS_DATE
                FROM TRANSACTIONS t JOIN candidates c ON c.CONTACT_ID = t.CONTACT_ID
                WHERE (t._FIVETRAN_DELETED = FALSE OR t._FIVETRAN_DELETED IS NULL) AND t.TRANS_TYPE = 'D'
                  AND t.CLEARED_DATE IS NOT NULL AND t.RETURNED_DATE IS NULL
                  AND (t.RETURN_CODE IS NULL OR t.RETURN_CODE = '')
            ), payment_presence AS (
                SELECT DISTINCT CONTACT_ID, 1 AS PAYMENT_PRESENT FROM active_payments
            ), ranked_payments AS (
                SELECT CONTACT_ID, CLEARED_DATE,
                    DENSE_RANK() OVER (PARTITION BY CONTACT_ID ORDER BY PROCESS_DATE DESC) AS PAYMENT_RANK
                FROM active_payments WHERE CLEARED_DATE <= '$cutoff'
            ), payments AS (
                SELECT CONTACT_ID, MIN(CLEARED_DATE) AS CLEARED_DATE,
                    COUNT(DISTINCT CLEARED_DATE) AS CLEAR_VARIANTS
                FROM ranked_payments WHERE PAYMENT_RANK = 1 GROUP BY CONTACT_ID
            )
            SELECT c.CONTACT_ID AS ID, a.AGENT,
                TO_VARCHAR(c.NSF_RETURNED_DATE, 'YYYY-MM-DD') AS NSF_RETURNED_DATE,
                x.NSF_ACTION, TO_VARCHAR(r.NSF_RECOUP_DATE, 'YYYY-MM-DD') AS NSF_RECOUP_DATE,
                p.CLEARED_DATE, COALESCE(a.AGENT_VARIANTS, 0) AS AGENT_VARIANTS,
                c.RETURN_VARIANTS, COALESCE(x.ACTION_VARIANTS, 0) AS ACTION_VARIANTS,
                COALESCE(r.RECOUP_VARIANTS, 0) AS RECOUP_VARIANTS,
                COALESCE(p.CLEAR_VARIANTS, 0) AS CLEAR_VARIANTS,
                COALESCE(pp.PAYMENT_PRESENT, 0) AS PAYMENT_PRESENT
            FROM candidates c
            LEFT JOIN agents a ON a.CONTACT_ID = c.CONTACT_ID
            LEFT JOIN actions x ON x.CONTACT_ID = c.CONTACT_ID
            LEFT JOIN recoups r ON r.CONTACT_ID = c.CONTACT_ID
            LEFT JOIN payments p ON p.CONTACT_ID = c.CONTACT_ID
            LEFT JOIN payment_presence pp ON pp.CONTACT_ID = c.CONTACT_ID
            ORDER BY a.AGENT, c.NSF_RETURNED_DATE, c.CONTACT_ID
        ";
        $result = $sf->query($query);
        if (($result['success'] ?? null) === false || !is_array($result['data'] ?? null)) {
            throw new \RuntimeException('NSF source query failed or returned invalid data; previous commission results were not replaced.');
        }
        $rows = [];
        $seen = [];
        foreach ($result['data'] as $row) {
            if (!is_array($row) || trim((string) ($row['ID'] ?? '')) === '') {
                throw new \RuntimeException('NSF source query returned an invalid row.');
            }
            $id = (string) $row['ID'];
            foreach (['AGENT_VARIANTS', 'RETURN_VARIANTS', 'ACTION_VARIANTS', 'RECOUP_VARIANTS', 'CLEAR_VARIANTS'] as $field) {
                if (!isset($row[$field]) || !ctype_digit((string) $row[$field])) {
                    throw new \RuntimeException("NSF source query is missing uniqueness evidence for contact {$id} ({$field}).");
                }
                if ((int) $row[$field] > 1) {
                    throw new \RuntimeException("NSF source contains conflicting {$field} for contact {$id}; correct the active source records before generating commissions.");
                }
            }
            if (!isset($row['PAYMENT_PRESENT']) || !in_array((string) $row['PAYMENT_PRESENT'], ['0', '1'], true)
                || (int) $row['RETURN_VARIANTS'] !== 1) {
                throw new \RuntimeException("NSF source query returned invalid eligibility evidence for contact {$id}.");
            }
            // Preserve the old T.RN=1 eligibility/denominator rule. Validate field
            // conflicts first, including candidates without a cleared payment.
            if ((int) $row['PAYMENT_PRESENT'] === 0) continue;
            // A later-only deposit preserves the old assignment denominator but
            // supplies no qualifying clear date for this report's fifth cutoff.
            if (((int) $row['CLEAR_VARIANTS'] === 0 && ($row['CLEARED_DATE'] ?? null) !== null)
                || ((int) $row['CLEAR_VARIANTS'] === 1 && !self::dateValid((string) ($row['CLEARED_DATE'] ?? '')))) {
                throw new \RuntimeException("NSF source query returned invalid payment evidence for contact {$id}.");
            }
            $clean = ['ID' => $id];
            foreach (['AGENT', 'NSF_RETURNED_DATE', 'NSF_ACTION', 'NSF_RECOUP_DATE', 'CLEARED_DATE'] as $field) {
                if (!array_key_exists($field, $row) || (!is_scalar($row[$field]) && $row[$field] !== null)) {
                    throw new \RuntimeException("NSF source query returned an invalid {$field} for contact {$id}.");
                }
                $clean[$field] = $row[$field];
            }
            if (self::validCommission($clean) && self::agentKey((string) ($clean['AGENT'] ?? '')) === '') {
                throw new \RuntimeException("NSF contact {$id} has a qualifying cleared payment but no assigned agent; correct its NSF agent before generating commissions.");
            }
            if (isset($seen[$id])) {
                if ($seen[$id] !== $clean) throw new \RuntimeException("NSF source returned conflicting rows for contact {$id}.");
                continue;
            }
            $seen[$id] = $clean;
            // Filter after global conflict checks, so a conflicting assignment
            // cannot disappear merely because MIN chose a different agent name.
            $rows[] = $clean;
        }
        return $agent === null ? $rows : array_values(array_filter($rows,
            static fn (array $row): bool => self::agentKey((string) ($row['AGENT'] ?? '')) === self::agentKey($agent)
        ));
    }

    public static function validCommission(array $row): bool
    {
        $row = array_change_key_case($row, CASE_UPPER);
        $returned = (string) ($row['NSF_RETURNED_DATE'] ?? '');
        $recoup = (string) ($row['NSF_RECOUP_DATE'] ?? '');
        $cleared = (string) ($row['CLEARED_DATE'] ?? '');
        if (!self::dateValid($returned) || !self::dateValid($recoup) || !self::dateValid($cleared)
            || substr($returned, 0, 7) !== substr($recoup, 0, 7)) return false;
        $cutoff = (new \DateTimeImmutable($returned))->modify('first day of next month')->format('Y-m-05');
        return $cleared <= $cutoff && $cleared > $recoup;
    }

    private static function dateValid(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private static function agentKey(string $name): string
    {
        return strtoupper((string) preg_replace('/\s+/', ' ', trim($name)));
    }
}
