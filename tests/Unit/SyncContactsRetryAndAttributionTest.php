<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncContactsData;
use Cmd\Reports\Services\ContactSyncCampaign;
use Cmd\Reports\Services\ContactSyncRunScope;
use Cmd\Reports\Services\ContactSyncTargets;
use Cmd\Reports\Services\ContactSyncWatermark;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once dirname(__DIR__, 2) . '/src/Console/Commands/SyncContactsData.php';

class SyncContactsRetryAndAttributionTest extends TestCase
{
    private const LT_ID = 'LLG-1259577465';
    private const PLAW_ID = 'LLG-1259580618';
    private const MAILER_KEY = '3830207554';

    private function contactsDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $columns = implode(', ', array_map(fn ($field) => "{$field} TEXT", ContactSyncTargets::FIELDS));
        foreach (['TblContacts', 'TblContactsLDR', 'TblContactsPLAW'] as $table) {
            $pdo->exec("CREATE TABLE {$table} ({$columns})");
        }
        return $pdo;
    }

    private function insert(\PDO $pdo, string $table, array $row): void
    {
        $row = $this->row($row);
        unset($row['_campaign_proof']);
        $statement = $pdo->prepare("INSERT INTO {$table} (" . implode(', ', array_keys($row)) . ') VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')');
        $statement->execute(array_values($row));
    }

    private function row(array $values): array
    {
        return $values + [
            'client' => 'Ana Maria Lopez', 'email' => 'ana@example.invalid', 'phone' => '2065550101',
            'created_date' => '2026-10-07 15:45:00', 'debt_amount' => 16254, 'debt_enrolled' => 16254.0,
        ] + array_fill_keys(ContactSyncTargets::FIELDS, '');
    }

    private function unresolvedProof(string $key, ?string $reference = null): array
    {
        return ['status' => 'unresolved', 'campaign' => '', 'external_id' => $key,
            'candidate_campaigns' => ['LLGRS30', 'LTIWCO3830A'], 'reason' => ContactSyncCampaign::RECIPIENT_UNVERIFIED]
            + ($reference === null ? [] : ['source_reference' => $reference]);
    }

    private function codes(array $change): array
    {
        return array_column($change['warnings'] ?? [], 'code');
    }

    private function command(): SyncContactsData
    {
        $command = new SyncContactsData();
        $input = new ArrayInput([], $command->getDefinition());
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput()));
        return $command;
    }

    private function set(object $object, string $name, mixed $value): void
    {
        (new \ReflectionProperty(SyncContactsData::class, $name))->setValue($object, $value);
    }

    private function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(SyncContactsData::class, $method))->invoke($object, ...$arguments);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/contact-sync-retry-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) unlink($path);
        }
        foreach ([$directory . '/app', $directory] as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $file) if (is_file($file)) unlink($file);
                rmdir($path);
            }
        }
    }

    public function test_campaign_doubt_withholds_attribution_for_a_new_contact_instead_of_rejecting_it(): void
    {
        foreach ([ContactSyncCampaign::RECIPIENT_UNVERIFIED, ContactSyncCampaign::MULTIPLE_CAMPAIGNS] as $reason) {
            $proof = ['status' => 'unresolved', 'campaign' => '', 'external_id' => self::MAILER_KEY, 'reason' => $reason];
            self::assertSame(['value' => '', 'reason' => null, 'withhold_attribution' => true, 'review_reason' => $reason],
                ContactSyncCampaign::plan(['campaign' => 'LLGRS30'], null, $proof));
        }
    }

    public function test_identity_doubt_still_rejects_and_existing_attribution_is_still_preserved(): void
    {
        $identity = ['status' => 'unresolved', 'campaign' => '', 'external_id' => '',
            'reason' => 'LT recipient identity did not verify for this backend reference.'];
        self::assertSame(['value' => '', 'reason' => $identity['reason']], ContactSyncCampaign::plan(['campaign' => ''], null, $identity));

        $existing = ContactSyncCampaign::plan(['campaign' => ''], ['campaign' => 'OLDCAMP'], $this->unresolvedProof(self::MAILER_KEY));
        self::assertSame('OLDCAMP', $existing['value']);
        self::assertTrue($existing['preserve_attribution']);
        self::assertArrayNotHasKey('withhold_attribution', $existing);
    }

    public function test_new_lt_contact_with_a_reused_mailer_key_is_accepted_with_a_blank_campaign(): void
    {
        $pdo = $this->contactsDatabase();
        $row = $this->row(['llg_id' => self::LT_ID, 'external_id' => self::MAILER_KEY,
            '_campaign_proof' => $this->unresolvedProof(self::MAILER_KEY)]);

        $plan = ContactSyncTargets::plan($pdo, [$row], 'LT', false, null, true);

        self::assertCount(1, $plan);
        self::assertEmpty($plan[0]['skip'] ?? false);
        self::assertNull($plan[0]['before']);
        self::assertSame(self::LT_ID, $plan[0]['target_id']);
        self::assertSame('', $plan[0]['after']['campaign']);
        self::assertSame(self::MAILER_KEY, $plan[0]['after']['external_id']);
        self::assertSame(['campaign_attribution_withheld'], $this->codes($plan[0]));
        self::assertSame([self::LT_ID], $plan[0]['campaign_hold_ids']);
        self::assertArrayNotHasKey('campaign_proof', $plan[0]);
    }

    public function test_new_backend_file_linked_to_a_held_lt_contact_is_accepted_with_a_blank_campaign(): void
    {
        $pdo = $this->contactsDatabase();
        $this->insert($pdo, 'TblContacts', ['llg_id' => self::LT_ID, 'external_id' => self::MAILER_KEY]);
        $row = $this->row(['llg_id' => self::PLAW_ID, 'external_id' => substr(self::LT_ID, 4),
            '_campaign_proof' => $this->unresolvedProof(self::MAILER_KEY, substr(self::LT_ID, 4))]);

        $plan = ContactSyncTargets::plan($pdo, [$row], 'PLAW', false, null, true, [self::LT_ID]);

        self::assertCount(1, $plan);
        self::assertEmpty($plan[0]['skip'] ?? false);
        self::assertSame(self::PLAW_ID, $plan[0]['target_id']);
        self::assertSame('', $plan[0]['after']['campaign']);
        self::assertSame(substr(self::LT_ID, 4), $plan[0]['after']['external_id']);
        self::assertSame(['campaign_attribution_withheld'], $this->codes($plan[0]));
        self::assertContains(self::LT_ID, $plan[0]['warnings'][0]['related_ids']);
    }

    public function test_a_proof_for_a_different_mailer_key_is_still_rejected(): void
    {
        $pdo = $this->contactsDatabase();
        $row = $this->row(['llg_id' => self::LT_ID, 'external_id' => self::MAILER_KEY, 'campaign' => 'LTIWCO3830A',
            '_campaign_proof' => ['status' => 'verified', 'campaign' => 'LTIWCO3830A', 'external_id' => '3830999999']]);

        $plan = ContactSyncTargets::plan($pdo, [$row], 'LT', false, null, true);

        self::assertTrue($plan[0]['skip']);
        self::assertSame(['campaign_attribution_review', 'contact_review'], $this->codes($plan[0]));
    }

    public function test_existing_contact_keeps_its_campaign_and_is_not_marked_withheld(): void
    {
        $pdo = $this->contactsDatabase();
        $this->insert($pdo, 'TblContacts', ['llg_id' => self::LT_ID, 'external_id' => self::MAILER_KEY, 'campaign' => 'OLDCAMP']);
        $row = $this->row(['llg_id' => self::LT_ID, 'external_id' => self::MAILER_KEY,
            '_campaign_proof' => $this->unresolvedProof(self::MAILER_KEY)]);

        $plan = ContactSyncTargets::plan($pdo, [$row], 'LT', false, null, true);

        self::assertEmpty($plan[0]['skip'] ?? false);
        self::assertSame('OLDCAMP', $plan[0]['after']['campaign']);
        self::assertSame(['campaign_attribution_preserved'], $this->codes($plan[0]));
    }

    public function test_retry_ids_are_stored_per_source_beside_the_checkpoint(): void
    {
        $directory = $this->temporaryDirectory();
        $retry = $directory . '/sync_retry_ids.json';
        $checkpoint = $directory . '/sync_timestamps.json';
        try {
            ContactSyncWatermark::write($checkpoint, 'LT', '2026-10-31 07:00:03');
            ContactSyncWatermark::writeRetryIds($retry, 'LT', ['1259577465', '1259584318', '1259577465']);
            ContactSyncWatermark::writeRetryIds($retry, 'PLAW', ['1259580618']);
            self::assertSame(['1259577465', '1259584318'], ContactSyncWatermark::readRetryIds($retry, 'LT'));
            self::assertSame(['1259580618'], ContactSyncWatermark::readRetryIds($retry, 'PLAW'));
            self::assertSame([], ContactSyncWatermark::readRetryIds($retry, 'LDR'));

            ContactSyncWatermark::writeRetryIds($retry, 'LT', []);
            self::assertSame([], ContactSyncWatermark::readRetryIds($retry, 'LT'));
            self::assertSame(['1259580618'], ContactSyncWatermark::readRetryIds($retry, 'PLAW'));
            // The checkpoint file keeps its own strict timestamp format.
            self::assertSame('2026-10-31 07:00:03', ContactSyncWatermark::read($checkpoint, 'LT'));
            ContactSyncWatermark::write($checkpoint, 'LT', '2026-10-30 07:00:03');
            self::assertSame('2026-10-31 07:00:03', ContactSyncWatermark::read($checkpoint, 'LT'));

            foreach ([['LLG-1259577465'], ['0'], ['1234567840'], [1259577465], array_map('strval', range(1000000001, 1000000000 + ContactSyncWatermark::MAX_RETRY_IDS + 1))] as $invalid) {
                try {
                    ContactSyncWatermark::writeRetryIds($retry, 'LT', $invalid);
                    self::fail('Invalid retry IDs were stored.');
                } catch (\RuntimeException) {
                }
            }
            file_put_contents($retry, '{"LT": "1259577465"}');
            $this->expectException(\RuntimeException::class);
            ContactSyncWatermark::readRetryIds($retry, 'LT');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_skipped_ids_are_fetched_again_by_id_in_the_incremental_window(): void
    {
        $command = $this->command();
        $this->set($command, 'source', 'PLAW');
        self::assertStringNotContainsString('c.ID IN', $this->call($command, 'contactChangedSql', '2026-10-30 07:00:03'));

        $this->set($command, 'retryIds', ['1259580618', '1259614642']);
        self::assertStringContainsString('OR c.ID IN (1259580618, 1259614642)', $this->call($command, 'contactChangedSql', '2026-10-30 07:00:03'));

        $this->set($command, 'source', 'LT');
        $this->set($command, 'retryIds', ['1259577465']);
        $sql = $this->call($command, 'contactChangedSql', '2026-10-30 07:00:03');
        self::assertStringContainsString('OR c.ID IN (1259577465)', $sql);
        self::assertStringContainsString('CONTACTS_ASSIGNED', $sql);
    }

    public function test_checkpoint_retries_cover_every_skip_or_keep_the_checkpoint(): void
    {
        $command = $this->command();
        $retries = fn (array $sources, array $changes = []) => $this->call($command, 'checkpointRetries', $sources, $changes);
        $change = fn (string $id) => ['llg_id' => $id, 'client' => 'A', 'email' => null, 'phone' => null, 'before' => 'LDR', 'category' => 'PLAW', 'agent' => 'X'];

        self::assertSame(['LT' => ['1259577465'], 'LDR' => [], 'PLAW' => ['1259580618']],
            $retries(['LT' => [self::LT_ID], 'LDR' => [], 'PLAW' => [self::PLAW_ID]]));
        self::assertNull($retries(['LT' => null, 'LDR' => [], 'PLAW' => []]), 'A skip with no fetchable ID keeps every checkpoint.');

        // A final-matching skip is retried through the source that proposed the enrollment change.
        $this->set($command, 'skippedContactIds', ['LLG-1263787206' => true]);
        self::assertSame(['LT' => [], 'LDR' => ['1263787206'], 'PLAW' => []],
            $retries(['LT' => [], 'LDR' => [], 'PLAW' => []], ['LDR' => ['categories' => [$change('LLG-1263787206')], 'affiliates' => []]]));
        self::assertNull($retries(['LT' => [], 'LDR' => [], 'PLAW' => []]), 'An unmapped final-matching skip keeps every checkpoint.');

        // An enrollment change blocked by an exclusion is fetched again instead of being lost.
        $this->set($command, 'skippedContactIds', []);
        $this->set($command, 'matchingExclusions', ['LLG-1260635515']);
        self::assertSame(['LT' => [], 'LDR' => ['1260635515'], 'PLAW' => []],
            $retries(['LT' => [], 'LDR' => [], 'PLAW' => []], ['LDR' => ['categories' => [], 'affiliates' => [$change('LLG-1260635515')]]]));

        $this->set($command, 'matchingExclusions', []);
        $many = array_map(fn ($id) => 'LLG-' . $id, range(1000000001, 1000000000 + ContactSyncWatermark::MAX_RETRY_IDS + 1));
        self::assertNull($retries(['LT' => $many]), 'Above the cap the checkpoint is kept.');
    }

    public function test_checkpoints_advance_after_the_retry_list_is_saved(): void
    {
        $storage = $this->temporaryDirectory();
        mkdir($storage . '/app', 0700, true);
        $app = new \Illuminate\Foundation\Application(dirname(__DIR__, 2));
        $app->useStoragePath($storage);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        try {
            $command = $this->command();
            $this->call($command, 'saveCheckpoints', ['LT' => ['1259577465'], 'LDR' => [], 'PLAW' => ['1259580618']],
                ['LT' => '2026-10-31 07:00:03', 'LDR' => '2026-10-31 07:40:10', 'PLAW' => '2026-10-31 08:05:44']);
            self::assertSame(['1259577465'], ContactSyncWatermark::readRetryIds($storage . '/app/sync_retry_ids.json', 'LT'));
            self::assertSame(['1259580618'], ContactSyncWatermark::readRetryIds($storage . '/app/sync_retry_ids.json', 'PLAW'));
            self::assertSame('2026-10-31 08:05:44', ContactSyncWatermark::read($storage . '/app/sync_timestamps.json', 'PLAW'));

            $this->call($command, 'saveCheckpoints', ['LT' => [], 'LDR' => [], 'PLAW' => []], ['LT' => '2026-11-01 07:00:03']);
            self::assertSame([], ContactSyncWatermark::readRetryIds($storage . '/app/sync_retry_ids.json', 'PLAW'));
            self::assertSame('2026-11-01 07:00:03', ContactSyncWatermark::read($storage . '/app/sync_timestamps.json', 'LT'));
        } finally {
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
            $this->removeDirectory($storage);
        }
    }

    public function test_child_scope_carries_its_retry_ids(): void
    {
        $directory = $this->temporaryDirectory();
        $scope = ['source' => 'PLAW', 'complete' => true, 'started_at' => '2026-10-31 08:05:44', 'excluded_ids' => [self::PLAW_ID],
            'campaigns' => [], 'campaign_hold_ids' => [], 'retry_ids' => [self::PLAW_ID], 'retry_complete' => true,
            'enrollment_changes' => ['categories' => [], 'affiliates' => []]];
        try {
            ContactSyncRunScope::write($directory . '/PLAW.json', $scope);
            self::assertSame([self::PLAW_ID], ContactSyncRunScope::read($directory . '/PLAW.json', 'PLAW')['retry_ids']);
            foreach ([['retry_complete' => null], ['retry_ids' => ['1259580618']]] as $broken) {
                try {
                    ContactSyncRunScope::write($directory . '/PLAW.json', array_replace($scope, $broken));
                    self::fail('An invalid retry scope was accepted.');
                } catch (\RuntimeException) {
                }
            }
        } finally {
            $this->removeDirectory($directory);
        }
    }
}
