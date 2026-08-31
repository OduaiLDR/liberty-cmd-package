<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Contracts;

/**
 * Resolves a creditor NAME to Forth's internal creditor id.
 *
 * Kept separate from PmodExecutionGateway on purpose: only the Forth gateway can
 * do this, and widening the main interface would force the DebtPlete gateway and
 * three test doubles to grow a method none of them can implement.
 *
 * Why this exists: both Add Creditor actions require Forth's numeric creditor id,
 * but only 11 of 24 measured payloads carry `creditor_change.creditor_id` — the
 * rest send a name only. The legacy VBA never needed an id at all; it typed the
 * name straight into the DPP form. This closes that gap.
 */
interface PmodCreditorDirectory
{
    /**
     * Return Forth's creditor id for a creditor name, or null when it cannot be
     * resolved unambiguously.
     *
     * Implementations MUST fail closed: a name matching two or more creditors
     * returns null rather than guessing, because attaching a debt to the wrong
     * creditor is worse than routing the request to manual review.
     */
    public function findCreditorId(string $tenantId, string $creditorName): ?string;
}
