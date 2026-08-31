<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Contracts;

/**
 * Looks creditors up in the Forth catalogue: name to id, and id validation.
 *
 * Kept separate from PmodExecutionGateway on purpose: only the Forth gateway can
 * do this, and widening the main interface would force the DebtPlete gateway and
 * three test doubles to grow methods none of them can implement.
 *
 * Why this exists: both Add Creditor actions need a Forth creditor id, and the
 * ids consumers send cannot be trusted. Measured on 2026-08-31, of four creditor
 * ids taken from real payloads only ONE existed in the catalogue - the others
 * (10016072, 10100974, 601) belong to a different id space, while the Forth
 * catalogue runs 25,399,828..28,315,871. The legacy VBA never used an id at all;
 * it typed the creditor name straight into the DPP form.
 */
interface PmodCreditorDirectory
{
    /**
     * Decide which creditor id to use, given whatever the consumer claimed and
     * the name they sent.
     *
     * A claimed id must be VALIDATED against the catalogue, never trusted. When
     * it does not exist, fall back to resolving the name. Returns null when
     * neither yields an unambiguous answer, so the caller can capture for manual
     * review.
     */
    public function resolveCreditorId(string $tenantId, ?string $claimedId, string $creditorName): ?string;

    /**
     * Drop any cached copy of the catalogue so the next lookup refetches, and
     * return the cache key that was cleared. Implementations MUST own the key -
     * a caller that rebuilds it would clear the wrong entry whenever the key
     * changes.
     */
    public function forgetCreditorCatalogue(string $tenantId): string;

    /** True when this creditor id exists in the tenant's Forth catalogue. */
    public function creditorExists(string $tenantId, string $creditorId): bool;

    /**
     * Return the Forth creditor id for a name, or null when it cannot be
     * resolved unambiguously.
     *
     * Implementations MUST fail closed: a name matching two or more creditors
     * returns null rather than guessing, because attaching a debt to the wrong
     * creditor is worse than routing the request to manual review. This is not a
     * theoretical concern - the LDR catalogue holds 55 rows matching CITI,
     * including near-duplicates and outright typos.
     */
    public function findCreditorId(string $tenantId, string $creditorName): ?string;
}
