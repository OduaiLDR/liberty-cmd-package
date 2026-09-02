<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Console\Commands;

use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Services\PmodDispatcher;
use Cmd\Reports\Pmod\Support\PmodIdempotency;
use Cmd\Reports\Pmod\Support\PmodWorkItemFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class TestPmodHandler extends Command
{
    protected $signature = 'pmod:test
                            {action : The PMOD action type or label}
                            {contact_id : The CRM contact ID to test with}
                            {--amount= : Payment amount}
                            {--original_date= : Original payment date (Y-m-d)}
                            {--target_date= : Target/new payment date (Y-m-d)}
                            {--settlement_id=* : Settlement ID to void}
                            {--creditor-name= : Creditor name, resolved against the Forth catalogue}
                            {--creditor-id= : Claimed Forth creditor id (validated against the catalogue, not trusted)}
                            {--balance= : Creditor balance}
                            {--account-number= : Creditor account number}
                            {--months-to-extend= : Months to extend the program}
                            {--months-to-decrease= : Months to decrease the term}
                            {--routing-number= : Bank routing number}
                            {--bank-account-number= : Bank account number}
                            {--account-type= : checking or savings}
                            {--account-holder= : Name on the account}
                            {--company=LDR : Company context, LDR or PLAW}
                            {--requested_by=CLI Test : Requesting user label}
                            {--no-dry-run : Allow live execution when PMOD_LIVE_DRAFT_UPDATES=true}
                            {--force : Re-run a live request this command has already executed within the hour}';

    protected $description = 'Test a PMOD handler directly. Defaults to dry-run mode.';

    public function handle(PmodDispatcher $dispatcher): int
    {
        $action = trim((string) $this->argument('action'));
        $contactId = trim((string) $this->argument('contact_id'));
        $liveUpdatesEnabled = (bool) config('services.pmod.live_draft_updates', false);
        $dryRun = !$this->option('no-dry-run') || !$liveUpdatesEnabled;

        $payload = [
            'customer_id' => $contactId,
            'action' => $action,
            'requested_by' => (string) $this->option('requested_by'),
            'company' => (string) $this->option('company'),
        ];

        foreach (['amount', 'original_date', 'target_date'] as $option) {
            $value = $this->option($option);

            if ($value !== null && $value !== '') {
                $payload[$option] = $value;
            }
        }

        // Month counts travel at the top level; the handlers read them there
        // first and fall back to creditor_change (see PmodWebhookRequest::rules,
        // which had to declare them or validated() dropped them in transit).
        foreach (['months_to_extend' => 'months-to-extend', 'months_to_decrease' => 'months-to-decrease'] as $field => $option) {
            $value = $this->option($option);

            if ($value !== null && $value !== '') {
                $payload[$field] = $value;
            }
        }

        // Nested containers, matching what consumers actually send (see the
        // measured payload shapes in the working reference). creditor_change is
        // the shape carrying creditor_id; the older `creditor` shape has no id.
        $creditorChange = array_filter([
            'creditor_name'  => $this->option('creditor-name'),
            'creditor_id'    => $this->option('creditor-id'),
            'balance'        => $this->option('balance'),
            'account_number' => $this->option('account-number'),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        if ($creditorChange !== []) {
            $payload['creditor_change'] = $creditorChange;
        }

        $bankingUpdate = array_filter([
            'routing_number'      => $this->option('routing-number'),
            'account_number'      => $this->option('bank-account-number'),
            'account_type'        => $this->option('account-type'),
            'account_holder_name' => $this->option('account-holder'),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        if ($bankingUpdate !== []) {
            $payload['banking_update'] = $bankingUpdate;
        }

        $settlementIds = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) $this->option('settlement_id'),
        )));

        if ($settlementIds !== []) {
            $payload['settlement_ids'] = $settlementIds;
        }

        if ($this->option('no-dry-run') && !$liveUpdatesEnabled) {
            $this->warn('PMOD_LIVE_DRAFT_UPDATES=false, so this command will still run in dry-run mode.');
        }

        $idempotencyKey = PmodIdempotency::fromNormalizedPayload($payload);

        try {
            $workItem = PmodWorkItemFactory::fromWebhookPayload(
                normalizedPayload: $payload,
                rawPayload: $payload,
                tenantId: $this->tenantId(),
                idempotencyKey: $idempotencyKey,
                source: 'cli:pmod-test',
                executionOptions: ['flow' => 'cli_test'],
                dryRun: $dryRun,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            $this->line('Available action values: ' . implode(', ', array_map(
                static fn (PmodActionType $type): string => $type->value,
                PmodActionType::cases(),
            )));

            return self::FAILURE;
        }

        $this->info("Testing {$workItem->actionType->value} for contact {$workItem->contactId}");
        $this->line('Mode: ' . ($dryRun ? 'dry-run' : 'live'));

        // A live CLI run has NO duplicate protection of its own: this command
        // calls the dispatcher directly, so ProcessPmodWorkItemJob's guard never
        // sees it. Measured 2026-09-02 - the same command run twice 23 seconds
        // apart created TWO debts and TWO drafts on contact 462464571, with the
        // second extension anchored a month past the first. Identical arguments
        // produce an identical idempotency key, so that is what we lock on.
        if (! $dryRun && ! $this->guardLiveRun($idempotencyKey)) {
            return self::FAILURE;
        }

        $result = $dispatcher->dispatch($workItem);

        $this->newLine();
        $this->line('Result Status: ' . $result->status);
        $this->line('Message: ' . $result->message);

        if ($result->metadata !== []) {
            $this->newLine();
            $this->line('Metadata:');
            $this->table(
                ['Key', 'Value'],
                collect($result->metadata)->map(
                    static fn (mixed $value, string|int $key): array => [
                        (string) $key,
                        is_array($value) ? json_encode($value) : (string) $value,
                    ],
                )->values()->all(),
            );
        }

        return $result->isFailed() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Refuse a live run this command has already executed within the hour.
     *
     * `Cache::add()` is atomic: it only writes when the key is absent, so the
     * first run claims the key and any repeat inside the TTL is refused. One hour
     * matches ProcessPmodWorkItemJob::$uniqueFor, so the CLI and the queue path
     * hold the same line.
     *
     * This is a safety net, not a substitute for the durable guard: it is
     * per-cache, so clearing the cache or a different cache store reopens it.
     * `--force` is the deliberate way past it, and a re-run is often legitimate
     * (cleanup, then test again) - the point is that it has to be a decision.
     */
    private function guardLiveRun(string $idempotencyKey): bool
    {
        $cacheKey = 'pmod_cli_live_' . $idempotencyKey;

        if (Cache::add($cacheKey, now()->toIso8601String(), now()->addHour())) {
            return true;
        }

        if ($this->option('force')) {
            $this->warn('[FORCE] This exact live request already ran within the hour. Proceeding anyway.');

            return true;
        }

        $this->error('This exact live request already ran within the last hour, at ' . Cache::get($cacheKey) . '.');
        $this->line('');
        $this->line('Identical arguments mean an identical idempotency key. Running it again would apply');
        $this->line('the change a SECOND time - on 2026-09-02 that produced two debts and two drafts.');
        $this->line('');
        $this->line('If you have cleaned up and genuinely want to run it again, add --force.');

        return false;
    }

    private function tenantId(): string
    {
        if (!function_exists('tenant')) {
            return 'default';
        }

        try {
            return (string) (tenant('id') ?? 'default');
        } catch (\Throwable) {
            return 'default';
        }
    }
}
