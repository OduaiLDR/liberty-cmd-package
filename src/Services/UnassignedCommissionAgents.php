<?php

namespace Cmd\Reports\Services;

/**
 * "Who earned commission this period but is not on the roster?"
 *
 * Shared by the three agent commission reports (Retention, Retention Bonus, NSF Commission) so the
 * rule is stated once. Jacob asked for the same behaviour on all three:
 *
 *   2026-09-03: "names from the roster only, then show missing names on the emails. But for all 3 of
 *   these, when showing names on the email only show names that were missing, and have data that is
 *   in the range we are looking for. Some are showing with 0 because they only have data from months
 *   ago. Sales Rep, Other CS Agent can be hard coded to ignore."
 *
 *   2026-09-04: "If anyone shows on the data sheet with commission, then they should be in the email
 *   alerting her that there is someone missing from the roster." … "if there is data for anyone in
 *   that month (so not just 0 commission) and they are not in the roster then add that to the body.
 *   Like
 *       Unassigned Agents
 *       Joe Smith     $50
 *       Jane Doe      $25"
 */
class UnassignedCommissionAgents
{
    /**
     * Placeholder "agents" the CRM uses for unattributed work. They are not people, so they must
     * never be reported as someone missing from the roster.
     */
    public const IGNORED_AGENTS = ['Sales Rep', 'Other CS Agent'];

    /**
     * @param array<string,float>    $totalsByAgent Commission earned THIS period, keyed by the agent
     *                                             name as it appears on the data sheet.
     * @param array<int,string>|null $rosterAgents  Null when the roster is unavailable — nobody can
     *                                              be called unassigned when there is nothing to be
     *                                              absent from.
     * @return array<int,array{agent:string,amount:float}> Largest amount first.
     */
    public static function fromTotals(array $totalsByAgent, ?array $rosterAgents): array
    {
        if ($rosterAgents === null) {
            return [];
        }

        $ignored = array_map([self::class, 'key'], self::IGNORED_AGENTS);

        $out = [];
        foreach ($totalsByAgent as $agent => $amount) {
            $agent  = trim((string) $agent);
            $amount = round((float) $amount, 2);

            // Non-zero for THIS period. The base window on these reports reaches months back to
            // compute rates, so "appears in the data" is not the same as "earned this month" —
            // that is what produced the $0 names Jacob asked us to stop listing.
            if ($agent === '' || $amount <= 0) {
                continue;
            }
            if (in_array(self::key($agent), $ignored, true)) {
                continue;
            }
            if (CommissionRosterProvider::isOnRoster($rosterAgents, $agent)) {
                continue;
            }
            $out[] = ['agent' => $agent, 'amount' => $amount];
        }

        usort($out, fn ($a, $b) => $b['amount'] <=> $a['amount'] ?: strcasecmp($a['agent'], $b['agent']));

        return $out;
    }

    /**
     * Sum a report's rows into per-agent totals.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string $agentField  Row key holding the agent name.
     * @param string $amountField Row key holding this period's commission.
     * @return array<string,float>
     */
    public static function totals(array $rows, string $agentField, string $amountField): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $agent = trim((string) ($row[$agentField] ?? ''));
            if ($agent === '') {
                continue;
            }
            $totals[$agent] = ($totals[$agent] ?? 0.0) + (float) ($row[$amountField] ?? 0);
        }

        return $totals;
    }

    /**
     * The plain-text block appended to the All email body, in Jacob's format.
     *
     * @param array<int,array{agent:string,amount:float}> $unassigned
     * @param bool $rosterUnavailable
     */
    public static function emailBlock(array $unassigned, bool $rosterUnavailable, string $rosterLabel = 'roster'): string
    {
        if ($rosterUnavailable) {
            // Say nothing about who is missing when we could not read the list they would be missing
            // from — an empty "Unassigned Agents" block reads as "everyone is accounted for", which
            // is the opposite of what a broken roster means.
            return "\n\nNOTE: the {$rosterLabel} could not be read for this run, so the summary uses the "
                . 'source agent names and no unassigned-agent check was performed.';
        }

        if ($unassigned === []) {
            return '';
        }

        $width = 0;
        foreach ($unassigned as $row) {
            $width = max($width, strlen((string) $row['agent']));
        }

        $lines = ['', '', 'Unassigned Agents'];
        foreach ($unassigned as $row) {
            $lines[] = str_pad((string) $row['agent'], $width + 5)
                . '$' . number_format((float) $row['amount'], 2);
        }

        return implode("\n", $lines);
    }

    /** HTML variant, for the reports whose All email is sent as HTML. */
    public static function emailBlockHtml(array $unassigned, bool $rosterUnavailable, string $rosterLabel = 'roster'): string
    {
        if ($rosterUnavailable) {
            return '<p style="color:#b42318"><strong>NOTE:</strong> the ' . htmlspecialchars($rosterLabel)
                . ' could not be read for this run, so the summary uses the source agent names and no '
                . 'unassigned-agent check was performed.</p>';
        }

        if ($unassigned === []) {
            return '';
        }

        $html = '<p style="margin-top:16px"><strong>Unassigned Agents</strong><br>'
            . '<span style="color:#666">Earned commission this period but are not on the roster.</span></p>'
            . '<table cellpadding="4" cellspacing="0" style="border-collapse:collapse">';
        foreach ($unassigned as $row) {
            $html .= '<tr><td>' . htmlspecialchars((string) $row['agent']) . '</td>'
                . '<td align="right">$' . number_format((float) $row['amount'], 2) . '</td></tr>';
        }

        return $html . '</table>';
    }

    private static function key(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', trim($name))));
    }
}
