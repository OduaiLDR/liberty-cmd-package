<?php

namespace Cmd\Reports\Services;

/**
 * Which company a commission report is for, and whether an agent's employee record agrees.
 *
 * Jacob, 2026-09-03: "Add a red highlight if the company does not match. If you are in Progress Law
 * and the company is Liberty or vise versa then flag that red." He raised it on Retention Bonus and
 * NSF both — Katherine Caceres showing Progress Law on the LDR report, Lucas Wright showing LDR on
 * the Progress Law one — so the rule lives here rather than in one report.
 *
 * The company itself comes from TblEmployees.Company, which spells the same two companies several
 * ways ('LDR' / 'Liberty Debt Relief', 'PLAW' / 'Progress Law'), hence the normalisation.
 */
class CommissionCompanyMatch
{
    /**
     * Reduce a TblEmployees.Company value to 'ldr', 'plaw', or '' when it is blank or is some
     * other company entirely (Lending Tower, say).
     */
    public static function normalize(string $company): string
    {
        $c = strtoupper(trim(preg_replace('/\s+/', ' ', $company) ?? ''));

        if ($c === 'LDR' || $c === 'LIBERTY DEBT RELIEF' || $c === 'LIBERTY') {
            return 'ldr';
        }
        if ($c === 'PLAW' || $c === 'PROGRESS LAW' || $c === 'PROGRESS') {
            return 'plaw';
        }

        return '';
    }

    /**
     * True when this agent's company contradicts the report they are on.
     *
     * A BLANK company is deliberately not a mismatch — the reports already highlight blanks
     * separately, and conflating the two would hide which of the two problems a row has.
     *
     * A company that is neither LDR nor Progress Law (Lending Tower, for example) IS treated as a
     * mismatch. Jacob described the LDR/PLAW swap because that is what he saw, but the rule he
     * asked for is "the company does not match this report", and a Lending Tower employee is no
     * more entitled to be on the Progress Law retention report than an LDR one is.
     *
     * @param string $source  'ldr' | 'plaw' — the report being generated.
     * @param string $company The agent's TblEmployees.Company value.
     */
    public static function mismatches(string $source, string $company): bool
    {
        $reportCompany = self::normalize($source);
        $agentCompany  = trim($company);

        // Unknown report company: nothing to compare against, so never flag.
        if ($reportCompany === '' || $agentCompany === '') {
            return false;
        }

        return self::normalize($agentCompany) !== $reportCompany;
    }

    /** Display label for a source code, matching how the reports title themselves. */
    public static function label(string $source): string
    {
        return self::normalize($source) === 'plaw' ? 'Progress Law' : 'LDR';
    }
}
