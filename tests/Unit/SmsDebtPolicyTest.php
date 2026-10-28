<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsDebtPolicy;
use Cmd\Reports\Services\SmsFrozenEligibilityHold;
use Cmd\Reports\Services\SmsRunOptions;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Owned in-memory monetary-boundary receipts only; no application or external database. */
final class SmsDebtPolicyTest extends TestCase
{
    private const ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    public function test_legacy_options_keep_their_shape_but_the_effective_floor_is_mandatory(): void
    {
        $old = ['target' => 3, 'rows_per_file' => 3, 'tiers' => SmsRunOptions::TIERS, 'csv_format' => SmsRunOptions::CSV_FORMAT];
        $before = json_encode($old);
        self::assertSame(25000, SmsDebtPolicy::minimum(null));
        self::assertSame(25000, SmsDebtPolicy::minimum($old));
        self::assertSame($before, json_encode($old));
        self::assertSame(35000, SmsDebtPolicy::minimum(['minimum_debt_amount' => 35000]));
        self::assertSame(SmsDebtPolicy::MAX, SmsDebtPolicy::validate(SmsDebtPolicy::MAX));
    }

    #[DataProvider('invalidMinimums')]
    public function test_missing_normalization_or_floor_bypass_is_rejected(mixed $minimum): void
    {
        $this->expectException(InvalidArgumentException::class);
        SmsDebtPolicy::minimum(['minimum_debt_amount' => $minimum]);
    }

    public static function invalidMinimums(): array
    {
        return [[0], [24999], [SmsDebtPolicy::MAX + 1], ['25000'], [25000.0], [null], [true], ['25000 OR 1=1']];
    }

    private function db(): SQLiteConnection
    {
        $db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $db->unprepared('CREATE TABLE TblSMSRuns(Request_ID TEXT,Target INTEGER,Run_Options_JSON TEXT);
CREATE TABLE TblProspectsExport(PK INTEGER PRIMARY KEY,Request_ID TEXT,Debt_Amount DECIMAL(12,2),Debt_Tier TEXT,Debt_Source TEXT,Debt_Source_PK INTEGER,Client TEXT,Address TEXT,Zip TEXT);
CREATE TABLE TblTUData(PK INTEGER,derivedFields1_unsecdebtbalance DECIMAL(18,2),ncoaAddr_frstNme TEXT,ncoaAddr_lastNme TEXT,ncoaAddr_addrLine1Txt TEXT,ncoaAddr_zipCde TEXT);
CREATE TABLE TblMailers(PK INTEGER,Debt_Amount DECIMAL(18,2),Client TEXT,Address TEXT,Zip TEXT);
CREATE TABLE TblSMSHistory(PK INTEGER,Request_ID TEXT,Debt_Amount DECIMAL(12,2));');
        $db->table('TblSMSRuns')->insert(['Request_ID' => self::ID, 'Target' => 3]);

        return $db;
    }

    private function frozen(SQLiteConnection $db, string $actual, string $rounded = '25000', string $source = 'TblTUData'): void
    {
        $db->table('TblProspectsExport')->insert(['PK' => 1, 'Request_ID' => self::ID, 'Debt_Amount' => $rounded, 'Debt_Tier' => 'T5', 'Debt_Source' => $source,
            'Debt_Source_PK' => 1, 'Client' => 'Synthetic Person', 'Address' => '1 Test Lane', 'Zip' => '00123']);
        if ($source === 'TblTUData') {
            $db->table($source)->insert(['PK' => 1, 'derivedFields1_unsecdebtbalance' => $actual, 'ncoaAddr_frstNme' => 'Synthetic',
                'ncoaAddr_lastNme' => 'Person', 'ncoaAddr_addrLine1Txt' => '1 Test Lane', 'ncoaAddr_zipCde' => '00123-9999']);
        }
        if ($source === 'TblMailers') {
            $db->table($source)->insert(['PK' => 1, 'Debt_Amount' => $actual, 'Client' => 'Synthetic Person', 'Address' => '1 Test Lane', 'Zip' => '00123-9999']);
        }
    }

    #[DataProvider('sourceBoundaries')]
    public function test_unrounded_boundary_is_enforced_for_exact_frozen_source_without_changing_membership(string $source, string $actual, string $rounded, bool $allowed): void
    {
        $db = $this->db();
        $this->frozen($db, $actual, $rounded, $source);
        $before = $db->table('TblProspectsExport')->get()->toJson();
        try {
            SmsDebtPolicy::assertPending($db, self::ID);
            self::assertTrue($allowed, 'Low unrounded debt was allowed');
        } catch (SmsFrozenEligibilityHold $error) {
            self::assertFalse($allowed, $error->getMessage());
        }
        self::assertSame($before, $db->table('TblProspectsExport')->get()->toJson());
    }

    public static function sourceBoundaries(): array
    {
        $cases = [];
        foreach (['TblTUData', 'TblMailers'] as $source) {
            $cases[$source.' below'] = [$source, '24999.99', '25000', false];
            $cases[$source.' exact'] = [$source, '25000.00', '25000', true];
            $cases[$source.' above'] = [$source, '25000.01', '26000', true];
        }

        return $cases;
    }

    public function test_low_frozen_snapshot_and_low_fixed_tier_never_gain_an_allowed_high_fallback(): void
    {
        $db = $this->db();
        $this->frozen($db, '10000', '10000');
        $db->table('TblMailers')->insert(['PK' => 2, 'Debt_Amount' => 85000, 'Client' => 'Synthetic Person', 'Address' => '1 Test Lane', 'Zip' => '00123']);
        try {
            SmsDebtPolicy::assertPending($db, self::ID);
            self::fail('Old low snapshot released');
        } catch (SmsFrozenEligibilityHold) {
        }
        $db->table('TblProspectsExport')->update(['Debt_Amount' => 30000, 'Debt_Source' => 'Tier', 'Debt_Tier' => 'T4']);
        $this->expectException(SmsFrozenEligibilityHold::class);
        SmsDebtPolicy::assertPending($db, self::ID);
    }

    public function test_boundary_pk_removal_identity_drift_or_ambiguous_duplicate_is_held(): void
    {
        foreach (['missing', 'identity', 'duplicate'] as $change) {
            $db = $this->db();
            $this->frozen($db, '25000');
            if ($change === 'missing') {
                $db->table('TblTUData')->delete();
            }
            if ($change === 'identity') {
                $db->table('TblTUData')->update(['ncoaAddr_addrLine1Txt' => 'Other address']);
            }
            if ($change === 'duplicate') {
                $db->table('TblTUData')->insert((array) $db->table('TblTUData')->first());
            }
            try {
                SmsDebtPolicy::assertPending($db, self::ID);
                self::fail('Unprovable source accepted');
            } catch (SmsFrozenEligibilityHold) {
                self::assertTrue(true);
            }
        }
    }

    #[DataProvider('compositeDuplicates')]
    public function test_duplicate_scope_is_the_full_identity_before_debt_validation(string $source, mixed $duplicateDebt): void
    {
        $db = $this->db();
        $this->frozen($db, '25000', '25000', $source);
        $copy = (array) $db->table($source)->first();
        $debt = $source === 'TblTUData' ? 'derivedFields1_unsecdebtbalance' : 'Debt_Amount';
        $copy[$debt] = $duplicateDebt;
        $db->table($source)->insert($copy);
        $this->expectException(SmsFrozenEligibilityHold::class);
        SmsDebtPolicy::assertPending($db, self::ID);
    }

    public static function compositeDuplicates(): array
    {
        $cases = [];
        foreach (['TblTUData', 'TblMailers'] as $source) {
            foreach ([null, 0, -1, '24999.99', '25000', SmsDebtPolicy::MAX + 1] as $value) {
                $cases[] = [$source, $value];
            }
        }

        return $cases;
    }

    public function test_unrelated_identity_sharing_a_source_pk_is_not_the_lean_global_pk_gate(): void
    {
        foreach (['TblTUData', 'TblMailers'] as $source) {
            $db = $this->db();
            $this->frozen($db, '25000', '25000', $source);
            $other = (array) $db->table($source)->first();
            $other[$source === 'TblTUData' ? 'ncoaAddr_addrLine1Txt' : 'Address'] = 'Another unrelated identity';
            $other[$source === 'TblTUData' ? 'derivedFields1_unsecdebtbalance' : 'Debt_Amount'] = null;
            $db->table($source)->insert($other);
            SmsDebtPolicy::assertPending($db, self::ID);
            self::assertSame(1, $db->table('TblProspectsExport')->count());
        }
    }

    public function test_above_boundary_guard_does_not_run_a_broad_current_source_resolution(): void
    {
        $db = $this->db();
        $this->frozen($db, '25000.01', '26000');
        $db->unprepared('DROP TABLE TblTUData; DROP TABLE TblMailers;');
        SmsDebtPolicy::assertPending($db, self::ID);
        self::assertSame(1, $db->table('TblProspectsExport')->count());
    }

    public function test_historical_approved_rows_are_never_scanned_or_rewritten_by_pending_guard(): void
    {
        $db = $this->db();
        $db->table('TblSMSHistory')->insert(['PK' => 1, 'Request_ID' => self::ID, 'Debt_Amount' => 10000]);
        $before = $db->table('TblSMSHistory')->get()->toJson();
        SmsDebtPolicy::assertPending($db, self::ID);
        self::assertSame($before, $db->table('TblSMSHistory')->get()->toJson());
    }

    public function test_higher_frozen_minimum_checks_the_actual_source_within_its_rounding_bucket(): void
    {
        $db = $this->db();
        $this->frozen($db, '25000.01', '26000');
        $options = SmsRunOptions::normalize(3, null, SmsRunOptions::TIERS, SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY, null, null, null, 25500);
        self::assertSame(25500, SmsDebtPolicy::minimum($options));
        $db->table('TblSMSRuns')->update(['Run_Options_JSON' => json_encode($options, JSON_THROW_ON_ERROR)]);
        try {
            SmsDebtPolicy::assertPending($db, self::ID);
            self::fail('Rounded26000 hid actual debt below chosen25500');
        } catch (SmsFrozenEligibilityHold) {
        }
        $db->table('TblTUData')->update(['derivedFields1_unsecdebtbalance' => '25500.00']);
        SmsDebtPolicy::assertPending($db, self::ID);
        self::assertSame('26000', (string) $db->table('TblProspectsExport')->value('Debt_Amount'));
    }
}
