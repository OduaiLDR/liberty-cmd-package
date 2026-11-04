<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\ContactSyncSourceEvidence;
use Cmd\Reports\Services\ContactSyncTargets;
use PDO;
use PHPUnit\Framework\TestCase;

final class ContactMigrationMatchingTest extends TestCase
{
    private function row(array $values = []): array
    {
        return $values + ['llg_id' => 'LLG-101', 'external_id' => '900', 'client' => 'Synthetic Client',
            'email' => 'client@example.invalid', 'phone' => '2025550101', 'agent' => 'Synthetic Agent',
            'debt_amount' => 1000, 'debt_enrolled' => 1000.0] + array_fill_keys(ContactSyncTargets::FIELDS, '');
    }

    private function insert(PDO $pdo, string $table, array $values): void
    {
        $row = $this->row($values);
        $stmt = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES ('
            . implode(',', array_fill(0, count($row), '?')) . ')');
        $stmt->execute(array_values($row));
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['TblContacts', 'TblContactsLDR', 'TblContactsPLAW'] as $table) {
            $pdo->exec('CREATE TABLE ' . $table . ' (' . implode(',', array_map(fn ($field) => $field . ' TEXT', ContactSyncTargets::FIELDS)) . ')');
        }
        $this->insert($pdo, 'TblContacts', []);
        $this->insert($pdo, 'TblContacts', ['llg_id' => 'LLG-301']);
        $this->insert($pdo, 'TblContactsLDR', ['llg_id' => 'LLG-201', 'external_id' => '101']);
        $this->insert($pdo, 'TblContactsPLAW', ['llg_id' => 'LLG-301', 'external_id' => '101']);
        return $pdo;
    }

    private function evidence(PDO $pdo, array $sources = []): ContactSyncSourceEvidence
    {
        $sources += ['LT' => [$this->sourceRow('101', '900', '0')],
            'LDR' => [$this->sourceRow('201', '101', '1')], 'PLAW' => []];
        return ContactSyncSourceEvidence::collect(ContactSyncTargets::evidenceRequests($pdo, [$this->row()], 'LT'),
            static fn (string $source, string $sql): array => $sources[$source]);
    }

    private function sourceRow(string $id, string $reference, string $enrolled): array
    {
        return ['ID' => $id, 'TP_ID' => $reference, 'DEL' => 'false', 'ISCOAPP' => '0', 'ENROLLED' => $enrolled,
            'FULLNAME' => 'Synthetic Client', 'EMAIL' => 'client@example.invalid', 'PHONE3' => '2025550101'];
    }

    private function plan(PDO $pdo, ?ContactSyncSourceEvidence $evidence): array
    {
        return ContactSyncTargets::plan($pdo, [$this->row()], 'LT', false, $evidence, true);
    }

    public function test_exact_current_enrolled_proof_remaps_native_main_and_preserves_stale_records(): void
    {
        $pdo = $this->database();
        $loserMain = $pdo->query("SELECT * FROM TblContacts WHERE llg_id='LLG-301'")->fetchAll(PDO::FETCH_ASSOC);
        $loserCompany = $pdo->query('SELECT * FROM TblContactsPLAW')->fetchAll(PDO::FETCH_ASSOC);
        $evidence = $this->evidence($pdo);
        $plan = $this->plan($pdo, $evidence);
        self::assertFalse($plan[0]['skip'] ?? false);
        self::assertSame('LLG-101', $plan[0]['before']['llg_id']);
        self::assertSame('LLG-201', $plan[0]['target_id']);
        self::assertSame('LLG-201', $plan[0]['after']['llg_id']);
        self::assertContains('verified_migration_remap', array_column($plan[0]['warnings'], 'code'));
        $pdo->beginTransaction();
        try {
            self::assertSame(1, ContactSyncTargets::apply($pdo, $plan, 'LT'));
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        self::assertSame($loserMain, $pdo->query("SELECT * FROM TblContacts WHERE llg_id='LLG-301'")->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($loserCompany, $pdo->query('SELECT * FROM TblContactsPLAW')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame('900', $pdo->query("SELECT external_id FROM TblContacts WHERE llg_id='LLG-201'")->fetchColumn());
        $repeat = $this->plan($pdo, $evidence);
        self::assertFalse($repeat[0]['skip'] ?? false);
        self::assertSame('LLG-201', $repeat[0]['target_id']);
        self::assertSame([], $repeat[0]['changes']);
    }

    public function test_missing_source_proof_or_changed_stored_snapshot_cannot_remap(): void
    {
        $pdo = $this->database();
        self::assertTrue($this->plan($pdo, null)[0]['skip']);
        $evidence = $this->evidence($pdo);
        $pdo->exec("UPDATE TblContactsLDR SET email='different@example.invalid'");
        self::assertTrue($this->plan($pdo, $evidence)[0]['skip']);
    }

    public function test_multiple_enrolled_or_still_live_loser_or_plan_fallback_cannot_remap(): void
    {
        foreach (['both_enrolled', 'live_unenrolled_loser', 'plan_fallback'] as $scenario) {
            $pdo = $this->database();
            $lt = $this->sourceRow('101', '900', '0');
            if ($scenario === 'plan_fallback') $lt['PLAN_TITLE'] = 'Liberty Debt Relief';
            $sources = ['LT' => [$lt], 'PLAW' => [$this->sourceRow('301', '101', $scenario === 'live_unenrolled_loser' ? '0' : '1')]];
            self::assertTrue($this->plan($pdo, $this->evidence($pdo, $sources))[0]['skip'], $scenario);
        }
    }

    public function test_occupied_or_duplicate_destination_and_changed_forth_identity_are_blocked(): void
    {
        foreach (['occupied_main', 'duplicate_company', 'changed_source_identity'] as $scenario) {
            $pdo = $this->database();
            $sources = [];
            if ($scenario === 'occupied_main') $this->insert($pdo, 'TblContacts', ['llg_id' => 'LLG-201']);
            if ($scenario === 'duplicate_company') $this->insert($pdo, 'TblContactsPLAW', ['llg_id' => 'LLG-201', 'external_id' => '101']);
            if ($scenario === 'changed_source_identity') {
                $changed = $this->sourceRow('201', '101', '1');
                $changed['FULLNAME'] = 'Another Person';
                $changed['EMAIL'] = 'other@example.invalid';
                $changed['PHONE3'] = '2025550199';
                $sources['LDR'] = [$changed];
            }
            self::assertTrue($this->plan($pdo, $this->evidence($pdo, $sources))[0]['skip'], $scenario);
        }
    }

    public function test_destination_claim_after_planning_fails_without_changing_the_native_row(): void
    {
        $pdo = $this->database();
        $plan = $this->plan($pdo, $this->evidence($pdo));
        $this->insert($pdo, 'TblContacts', ['llg_id' => 'LLG-201', 'client' => 'Concurrent Claim']);
        $pdo->beginTransaction();
        try {
            ContactSyncTargets::apply($pdo, $plan, 'LT');
            self::fail('Occupied destination must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('write count was not one', $error->getMessage());
            $pdo->rollBack();
        }
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM TblContacts WHERE llg_id='LLG-101'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM TblContacts WHERE llg_id='LLG-201'")->fetchColumn());
    }
}
