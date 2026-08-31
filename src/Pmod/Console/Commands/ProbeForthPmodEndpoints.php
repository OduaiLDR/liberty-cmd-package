<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Console\Commands;

use Cmd\Reports\Pmod\Services\ForthPayPmodExecutionGateway;
use Illuminate\Console\Command;

/**
 * Establishes which Forth endpoints behind the six capture-only PMOD actions
 * (Updated Banking, Updated Sponsor Banking, and the four creditor actions)
 * actually exist, so those handlers can be moved off capture-only.
 *
 * The live implementations already exist in src/Pmod/Actions; they were
 * reverted to CapturePmodRequestAction in 2026-06 after Forth answered 404 or
 * 400, without recording which endpoint gave which. Read the output as:
 *
 *   404 - wrong path, the handler is calling something that does not exist
 *   400 - right path, wrong payload (fixable on our side)
 *   200 - works
 *
 * Read-only unless --execute or --execute-banking is passed.
 */
final class ProbeForthPmodEndpoints extends Command
{
    protected $signature = 'pmod:probe-endpoints
        {--tenant=PLAW : Tenant slug - LDR, PLAW or LT.}
        {--contact= : Forth contact id to probe against. Required.}
        {--creditor=* : Resolve these creditor NAMES to Forth ids and exit (no contact needed).}
        {--refresh : Forget the cached creditor catalogue first (24h TTL — use after a code or catalogue change).}
        {--execute : Also create a probe debt and immediately cancel it. TEST FILES ONLY.}
        {--execute-banking : Also probe the bank-account write paths with an empty body. TEST FILES ONLY.}';

    protected $description = 'Probe the Forth endpoints behind the capture-only banking and creditor PMOD actions.';

    public function handle(ForthPayPmodExecutionGateway $gateway): int
    {
        $tenant = strtoupper(trim((string) $this->option('tenant')));

        // Creditor-name resolution is a standalone diagnostic: it needs no contact,
        // so handle it before the --contact requirement below.
        $names = array_values(array_filter(array_map('trim', (array) $this->option('creditor'))));

        if ($names !== []) {
            if (! $gateway instanceof \Cmd\Reports\Pmod\Contracts\PmodCreditorDirectory) {
                $this->error('This gateway cannot resolve creditor names.');

                return self::FAILURE;
            }

            if ($this->option('refresh')) {
                $key = $gateway->forgetCreditorCatalogue(strtolower($tenant));
                $this->info("[INFO] Forgot cached catalogue [{$key}] - refetching.");
            }

            $rows = [];
            foreach ($names as $name) {
                $id = $gateway->findCreditorId(strtolower($tenant), $name);
                $rows[] = [$name, $id ?? '-- unresolved --'];
            }

            $this->table(['Creditor name', 'Forth creditor id'], $rows);
            $this->line('');
            $this->line('Unresolved means unknown or ambiguous. The resolver never guesses —');
            $this->line('an ambiguous name routes the PMOD to manual review instead.');

            return self::SUCCESS;
        }

        $contactId = trim((string) $this->option('contact'));

        if ($contactId === '') {
            $this->error('--contact is required. Use a test file, not a real client.');

            return self::FAILURE;
        }

        $execute        = (bool) $this->option('execute');
        $executeBanking = (bool) $this->option('execute-banking');

        if ($execute || $executeBanking) {
            $this->warn("[WARN] Write probes enabled against contact {$contactId} on {$tenant}. Test files only.");
        }

        $result = $gateway->probeCreditorAndBankingEndpoints($tenant, $contactId, $execute, $executeBanking);

        $rows = [];
        foreach ($result['checks'] as $check) {
            $rows[] = [
                $check['ok'] ? 'OK' : 'FAIL',
                $check['status'] === 0 ? '-' : $check['status'],
                $check['call'],
                $check['label'],
                mb_substr((string) preg_replace('/\s+/', ' ', $check['body']), 0, 80),
            ];
        }

        $this->table(['', 'HTTP', 'Call', 'Used by', 'Body (truncated)'], $rows);

        $path = storage_path("app/pmod-endpoint-probe-{$tenant}-{$contactId}.json");
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("[INFO] Full output with untruncated bodies: {$path}");
        $this->line('');
        $this->line('404 = wrong path, 400 = right path with a bad payload, 200 = works.');
        $this->line('Send the JSON to Bryan Roland to confirm the correct paths and payloads.');

        return self::SUCCESS;
    }
}
