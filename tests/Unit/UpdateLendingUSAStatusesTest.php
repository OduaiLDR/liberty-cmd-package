<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\UpdateLendingUSAStatuses;
use Cmd\Reports\Services\DBConnector;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class UpdateLendingUSAStatusesTest extends TestCase
{
    /** @var list<LendingUsaReconciliationHarness> */
    private array $commands = [];

    private function command(): LendingUsaReconciliationHarness
    {
        return $this->commands[] = new LendingUsaReconciliationHarness();
    }

    protected function tearDown(): void
    {
        foreach ($this->commands as $command) {
            if (is_file($command->snapshotFile)) unlink($command->snapshotFile);
        }
        parent::tearDown();
    }

    #[DataProvider('plans')]
    public function test_restores_status_after_payment_change_without_a_lending_status_change(string $title, string $plan): void
    {
        $command = $this->command();
        $command->planTitle = $title;
        $command->contact = ['status_label' => "$plan Enrolled", 'stage_label' => 'Client'];
        $command->seed('Withdrawn');

        $command->process('Withdrawn');

        self::assertSame(["$plan Enrolled (LUSA-Withdrawn)"], $command->updates);
        self::assertSame('Withdrawn', $command->snapshot()['123456']);
        self::assertFileDoesNotExist($command->snapshotFile);
        self::assertSame(0, $command->fundedCalls);
        self::assertSame(1, $command->changes()[0]['crm_reconciliation']);
        self::assertSame("$plan Enrolled", $command->changes()[0]['old_status']);
    }

    public static function plans(): array
    {
        return [
            ['LT LDR 29% with Veritas', 'LDR'],
            ['PLAW Program', 'PLAW'],
            ['Progress Law Program', 'ProLaw'],
        ];
    }

    public function test_a_second_run_is_a_no_op_after_restoration(): void
    {
        $command = $this->command();
        $command->seed('Withdrawn');
        $command->process('Withdrawn');
        $command->process('Withdrawn');
        self::assertCount(1, $command->updates);
        self::assertSame(2, $command->contactReads);
    }

    public function test_dry_run_previews_restoration_without_status_or_snapshot_writes(): void
    {
        $command = $this->command();
        $command->seed('Withdrawn');
        $command->enableDryRun();
        $command->process('Withdrawn');
        self::assertSame([], $command->updates);
        self::assertFileDoesNotExist($command->snapshotFile);
        self::assertSame('LDR Enrolled', $command->contact['status_label']);
        self::assertStringContainsString('[DRY-RUN] Would update CRM status', $command->outputText());
        self::assertSame(0, $command->fundedCalls);
    }

    #[DataProvider('protectedStatuses')]
    public function test_reconciliation_preserves_protected_or_unknown_crm_statuses(string $status, string $stage): void
    {
        $command = $this->command();
        $command->contact = ['status_label' => $status, 'stage_label' => $stage];
        $command->seed('Withdrawn');
        $command->process('Withdrawn');
        self::assertSame([], $command->updates);
        self::assertFileDoesNotExist($command->snapshotFile);
        self::assertSame(0, $command->fundedCalls);
    }

    public static function protectedStatuses(): array
    {
        return [
            ['Dropped / Cancelled', 'Cancel'],
            ['Graduated / Completed', 'Graduated'],
            ['LDR Enrolled (NSF-1)', 'Client'],
            ['NSF Payment Stopped - 30', 'NSF'],
            ['Enrolled (Reconsideration Pending)', 'Client'],
            ['Enrolled (Cancellation Pending EPF Hold)', 'Client'],
            ['Manual Review', 'Client'],
            ['LDR Enrolled (LUSA-FUNDED)', 'Client'],
            ['LDR Enrolled', 'Cancel'],
            ['New Lead', 'Lead'],
            ['Some future custom status', 'Client'],
            ['', 'Client'],
        ];
    }

    public function test_existing_lusa_status_can_be_reconciled_to_current_lending_status(): void
    {
        $command = $this->command();
        $command->contact['status_label'] = 'LDR Enrolled (LUSA-Approved)';
        $command->seed('Withdrawn');
        $command->process('Withdrawn');
        self::assertSame(['LDR Enrolled (LUSA-Withdrawn)'], $command->updates);
    }

    public function test_a_different_brand_is_not_automatically_reclassified(): void
    {
        $command = $this->command();
        $command->contact['status_label'] = 'ProLaw Enrolled';
        $command->seed('Withdrawn');
        $command->process('Withdrawn');
        self::assertSame([], $command->updates);
    }

    public function test_failed_new_status_update_does_not_checkpoint_and_can_retry(): void
    {
        $command = $this->command();
        $command->seed('Approved');
        $command->updateOutcomes = [false, true];
        $command->process('Withdrawn');
        self::assertSame('Approved', $command->snapshot()['123456']);
        self::assertFileDoesNotExist($command->snapshotFile);
        $command->process('Withdrawn');
        self::assertSame('Withdrawn', $command->snapshot()['123456']);
        self::assertFileExists($command->snapshotFile);
        self::assertCount(2, $command->updates);
    }

    public function test_failed_reconciliation_retries_even_with_an_old_withdrawn_checkpoint(): void
    {
        $command = $this->command();
        $command->seed('Withdrawn');
        $command->updateOutcomes = [false, true];
        $command->process('Withdrawn');
        $command->process('Withdrawn');
        self::assertCount(2, $command->updates);
        self::assertSame('LDR Enrolled (LUSA-Withdrawn)', $command->contact['status_label']);
        self::assertFileDoesNotExist($command->snapshotFile);
    }

    public function test_a_temporary_crm_read_error_does_not_checkpoint_or_update(): void
    {
        $command = $this->command();
        $command->readFails = true;
        $command->process('Withdrawn');
        self::assertSame([], $command->snapshot());
        self::assertSame([], $command->updates);
        self::assertGreaterThan(0, $command->errors());
        $command->readFails = false;
        $command->process('Withdrawn');
        self::assertSame('Withdrawn', $command->snapshot()['123456']);
    }

    public function test_a_missing_contact_does_not_checkpoint_and_can_be_found_later(): void
    {
        $command = $this->command();
        $command->contact = null;
        $command->process('Withdrawn');
        self::assertSame([], $command->snapshot());
        $command->contact = ['status_label' => 'LDR Enrolled', 'stage_label' => 'Client'];
        $command->process('Withdrawn');
        self::assertSame('Withdrawn', $command->snapshot()['123456']);
    }

    public function test_a_missing_plan_does_not_checkpoint_and_retries_when_it_is_available(): void
    {
        $command = $this->command();
        $command->planTitle = '';
        $command->process('Withdrawn');
        self::assertSame([], $command->snapshot());
        self::assertSame([], $command->updates);
        $command->planTitle = 'LDR Program';
        $command->process('Withdrawn');
        self::assertSame('Withdrawn', $command->snapshot()['123456']);
    }

    public function test_not_interested_keeps_its_existing_unmapped_behavior(): void
    {
        $command = $this->command();
        $command->process('Not Interested');
        self::assertSame([], $command->updates);
        self::assertSame('Not Interested', $command->snapshot()['123456']);
        $command->process('Not Interested');
        self::assertSame([], $command->updates);
    }

    public function test_reconciliation_never_replays_funded_side_effects(): void
    {
        $command = $this->command();
        $command->seed('Funded');
        $command->process('Funded');
        self::assertSame(['LDR Enrolled (LUSA-Funded)'], $command->updates);
        self::assertSame(0, $command->fundedCalls);
    }

    public function test_new_funded_transition_runs_once_only_after_crm_update_succeeds(): void
    {
        $command = $this->command();
        $command->seed('Approved');
        $command->updateOutcomes = [false, true];
        $command->process('Funded');
        self::assertSame(0, $command->fundedCalls);
        self::assertSame('Approved', $command->snapshot()['123456']);
        $command->process('Funded');
        $command->process('Funded');
        self::assertSame(1, $command->fundedCalls);
    }

    public function test_blocked_states_stay_excluded(): void
    {
        $command = $this->command();
        $command->state = 'CT';
        $command->seed('Withdrawn');
        $command->process('Withdrawn');
        self::assertSame([], $command->updates);
        self::assertSame(0, $command->contactReads);
    }

    public function test_actual_http_500_is_retried_instead_of_being_treated_as_contact_missing(): void
    {
        $command = $this->command();
        $command->mockContactReads([new Response(500), new Response(200, [], json_encode([
            'response' => ['status_label' => 'LDR Enrolled', 'stage_label' => 'Client'],
        ]))]);
        $command->process('Withdrawn');
        self::assertSame([], $command->snapshot());
        self::assertSame([], $command->updates);
        self::assertSame(1, $command->errors());
        $command->process('Withdrawn');
        self::assertSame('Withdrawn', $command->snapshot()['123456']);
        self::assertCount(1, $command->updates);
    }

    public function test_actual_http_404_is_unresolved_without_a_false_success_checkpoint(): void
    {
        $command = $this->command();
        $command->mockContactReads([new Response(404)]);
        $command->process('Withdrawn');
        self::assertSame([], $command->snapshot());
        self::assertSame([], $command->updates);
        self::assertSame(0, $command->errors());
    }

    #[DataProvider('invalidContactResponses')]
    public function test_invalid_or_incomplete_crm_response_cannot_trigger_an_update(string $body): void
    {
        $command = $this->command();
        $command->mockContactReads([new Response(200, [], $body)]);
        $command->process('Withdrawn');
        self::assertSame([], $command->snapshot());
        self::assertSame([], $command->updates);
        self::assertSame(1, $command->errors());
    }

    public static function invalidContactResponses(): array
    {
        return [['not-json'], ['{}'], ['{"response":null}'], ['{"response":[]}'],
            ['{"response":{"leadstatus":378206}}']];
    }

    public function test_fallback_status_label_is_used_when_primary_label_is_blank(): void
    {
        $command = $this->command();
        $command->contact = ['status_label' => '', 'client_status' => 'LDR Enrolled', 'stage_label' => 'Client'];
        $command->seed('Withdrawn');
        $command->process('Withdrawn');
        self::assertSame(['LDR Enrolled (LUSA-Withdrawn)'], $command->updates);
    }

    public function test_new_funded_transition_does_not_override_an_operational_hold(): void
    {
        $command = $this->command();
        $command->contact['status_label'] = 'Enrolled (Reconsideration Pending)';
        $command->seed('Approved');
        $command->process('Funded');
        self::assertSame([], $command->updates);
        self::assertSame(0, $command->fundedCalls);
    }

    public function test_dry_run_refreshes_credentials_only_in_memory(): void
    {
        $command = $this->command();
        $command->enableDryRun();
        $command->useKeyResolver();
        self::assertSame('synthetic-refreshed-key', $command->key());
        self::assertSame(0, $command->storedKeys);
    }

    public function test_live_credential_refresh_retains_its_existing_persistence(): void
    {
        $command = $this->command();
        $command->useKeyResolver();
        self::assertSame('synthetic-refreshed-key', $command->key());
        self::assertSame(1, $command->storedKeys);
    }
}

final class LendingUsaReconciliationHarness extends UpdateLendingUSAStatuses
{
    public string $snapshotFile;
    public string $planTitle = 'LDR Program';
    public string $state = 'CA';
    public ?array $contact = ['status_label' => 'LDR Enrolled', 'stage_label' => 'Client'];
    public array $updates = [];
    public array $updateOutcomes = [];
    public bool $readFails = false;
    public int $contactReads = 0;
    public int $fundedCalls = 0;
    public int $storedKeys = 0;
    private bool $realContactReader = false;
    private bool $realKeyResolver = false;
    private BufferedOutput $buffer;

    public function __construct()
    {
        parent::__construct();
        $this->snapshotFile = sys_get_temp_dir() . '/lusa-test-' . bin2hex(random_bytes(8)) . '.json';
        $this->buffer = new BufferedOutput();
        $this->setOutput(new OutputStyle(new ArrayInput([]), $this->buffer));
    }

    public function seed(string $status): void { $this->localStatusSnapshot = ['123456' => $status]; }
    public function snapshot(): array { return $this->localStatusSnapshot; }
    public function changes(): array { return $this->changedRecords; }
    public function errors(): int { return $this->totalErrors; }
    public function enableDryRun(): void { $this->dryRun = true; }
    public function outputText(): string { return $this->buffer->fetch(); }
    public function mockContactReads(array $responses): void
    {
        $this->realContactReader = true;
        $this->httpClient = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }
    public function useKeyResolver(): void
    {
        $this->realKeyResolver = true;
        $this->connector = new LendingUsaKeyReadConnector();
    }
    public function key(): string { return $this->resolveForthApiKey(); }
    public function process(string $status): void
    {
        $this->processClient(['llgid' => '123456', 'status' => $status, 'status_date' => '2026-11-17'],
            $this->localStatusSnapshot, '2026-11-17 12:00:00');
    }
    protected function statusSnapshotPath(): string { return $this->snapshotFile; }
    protected function getEnrollmentState(string $llgid): string { return $this->state; }
    protected function getEnrollmentPlanTitle(string $llgid): string { return $this->planTitle; }
    protected function getClientName(string $llgid): string { return 'Synthetic Client'; }
    protected function getCrmContactData(string $contactId): ?array
    {
        $this->contactReads++;
        if ($this->realContactReader) return parent::getCrmContactData($contactId);
        if ($this->readFails) throw new \RuntimeException('Synthetic CRM read failure');
        return $this->contact;
    }
    protected function updateCrmStatus(string $contactId, string $status): bool
    {
        $this->updates[] = $status;
        $success = $this->updateOutcomes === [] ? true : array_shift($this->updateOutcomes);
        if ($success) $this->contact['status_label'] = $status;
        return $success;
    }
    protected function resolveForthApiKey(): string
    {
        return $this->realKeyResolver ? parent::resolveForthApiKey() : 'synthetic-key';
    }
    protected function fetchForthApiKey(): string { return 'synthetic-refreshed-key'; }
    protected function storeForthApiKey(string $category, string $apiKey): void { $this->storedKeys++; }
    protected function handleFundedWorkflow(string $llgid, string $clientName, string $plan, string $prevStatus = 'N/A', string $state = ''): void
    {
        $this->fundedCalls++;
    }
}

final class LendingUsaKeyReadConnector extends DBConnector
{
    public function __construct() {}
    public function querySqlServer(string $sql, array $params = []): array
    {
        if (!str_starts_with(ltrim($sql), 'SELECT ')) throw new \RuntimeException('Only credential reads are allowed in this fixture.');
        return ['success' => true, 'data' => []];
    }
}
