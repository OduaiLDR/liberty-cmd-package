<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Console\Commands;

use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Services\PmodDispatcher;
use Cmd\Reports\Pmod\Support\PmodIdempotency;
use Cmd\Reports\Pmod\Support\PmodWorkItemFactory;
use Illuminate\Console\Command;

final class TestPmodHandler extends Command
{
    protected $signature = 'pmod:test
                            {action : The PMOD action type or label}
                            {contact_id : The CRM contact ID to test with}
                            {--amount= : Payment amount}
                            {--original_date= : Original payment date (Y-m-d)}
                            {--target_date= : Target/new payment date (Y-m-d)}
                            {--settlement_id=* : Settlement ID to void}
                            {--company=LDR : Company context, LDR or PLAW}
                            {--requested_by=CLI Test : Requesting user label}
                            {--no-dry-run : Allow live execution when PMOD_LIVE_DRAFT_UPDATES=true}';

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

        return $result->isSucceeded() ? self::SUCCESS : self::FAILURE;
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
