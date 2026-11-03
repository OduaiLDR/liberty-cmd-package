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

    public function test_legacy_options_keep_their_shape_and_floor_while_only_by_tier_runs_drop_it(): void
    {
        $old = ['target' => 3, 'rows_per_file' => 3, 'tiers' => SmsRunOptions::TIERS, 'csv_format' => SmsRunOptions::CSV_FORMAT];
        $before = json_encode($old);
        self::assertSame(25000, SmsDebtPolicy::minimum(null));
        self::assertSame(25000, SmsDebtPolicy::minimum($old));
        self::assertSame([25000, 25000], [SmsDebtPolicy::lowerBound(null), SmsDebtPolicy::lowerBound($old)]);
        self::assertFalse(SmsDebtPolicy::floorless(null) || SmsDebtPolicy::floorless($old));
        self::assertSame($before, json_encode($old));
        $byTier = SmsRunOptions::normalize(3, null, ['T1'], SmsRunOptions::LEAN_WINDOWED_TIER_SELECTION_POLICY);
        self::assertTrue(SmsDebtPolicy::floorless($byTier));
        self::assertSame([0, [0]], [SmsDebtPolicy::lowerBound($byTier), SmsDebtPolicy::bindings($byTier)]);
        $range = SmsRunOptions::normalize(3, null, null, SmsRunOptions::LEAN_WINDOWED_TIER_SELECTION_POLICY, null, null, null, 35000);
        self::assertFalse(SmsDebtPolicy::floorless($range));
        self::assertSame(35000, SmsDebtPolicy::minimum(['minimum_debt_amount' => 35000]));
        self::assertSame(35000, SmsDebtPolicy::lowerBound($range));
        self::assertSame(SmsDebtPolicy::MAX, SmsDebtPolicy::validate(SmsDebtPolicy::MAX));
    }

    public function test_by_tier_runs_without_a_minimum_release_low_debt_and_low_tier_fallbacks_but_hold_invalid_debt(): void
    {
        $db = $this->db();
        $db->table('TblSMSRuns')->update(['Run_Options_JSON' => json_encode(SmsRunOptions::normalize(3, null, ['T1', 'T5'],
            SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY), JSON_THROW_ON_ERROR)]);
        $this->frozen($db, '9999.99', '10000');
        $db->table('TblProspectsExport')->insert(['PK' => 2, 'Request_ID' => self::ID, 'Debt_Amount' => 10000, 'Debt_Tier' => 'T1', 'Debt_Source' => 'Tier']);
        // No boundary source proof either: the frozen TU row is not re-read.
        $db->unprepared('DROP TABLE TblTUData; DROP TABLE TblMailers;');
        SmsDebtPolicy::assertPending($db, self::ID);
        $byTier = $db->table('TblSMSRuns')->value('Run_Options_JSON');
        // Legacy runs without a selector keep the original 25,000 pending guard.
        $db->table('TblSMSRuns')->update(['Run_Options_JSON' => null]);
        try {
            SmsDebtPolicy::assertPending($db, self::ID);
            self::fail('Legacy low debt was released');
        } catch (SmsFrozenEligibilityHold $error) {
            self::assertStringContainsString('below the required debt minimum', $error->getMessage());
        }
        $db->table('TblSMSRuns')->update(['Run_Options_JSON' => $byTier]);
        foreach ([null, 0, -1000, SmsDebtPolicy::MAX + 1000] as $invalid) {
            $db->table('TblProspectsExport')->where('PK', 1)->update(['Debt_Amount' => $invalid]);
            try {
                SmsDebtPolicy::assertPending($db, self::ID);
                self::fail('Invalid frozen debt was released without a minimum');
            } catch (SmsFrozenEligibilityHold $error) {
                self::assertStringContainsString('unknown, non-positive or unsupported debt', $error->getMessage());
            }
        }
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
        // Boundary receipts below exercise range mode's frozen 25,000 minimum.
        $options = SmsRunOptions::normalize(3, null, SmsRunOptions::TIERS, SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY, null, null, null, 25000);
        $db->table('TblSMSRuns')->insert(['Request_ID' => self::ID, 'Target' => 3, 'Run_Options_JSON' => json_encode($options, JSON_THROW_ON_ERROR)]);

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

    public function test_maximum_is_optional_whole_thousands_in_range_and_not_below_the_minimum(): void
    {
        self::assertNull(SmsDebtPolicy::maximum(null));
        self::assertNull(SmsDebtPolicy::maximum(['minimum_debt_amount' => 31000]));
        self::assertSame(31000, SmsDebtPolicy::maximum(['minimum_debt_amount' => 31000, 'maximum_debt_amount' => 31000]));
        self::assertSame(SmsDebtPolicy::MAX, SmsDebtPolicy::validateMaximum(SmsDebtPolicy::MAX, SmsDebtPolicy::FLOOR));
        self::assertSame(SmsDebtPolicy::FLOOR, SmsDebtPolicy::validateMaximum(SmsDebtPolicy::FLOOR, SmsDebtPolicy::FLOOR));
        self::assertSame([31000, 35000], SmsDebtPolicy::bindings(['minimum_debt_amount' => 31000, 'maximum_debt_amount' => 35000]));
    }

    #[DataProvider('invalidMaximums')]
    public function test_invalid_or_orphan_maximum_is_rejected(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(SmsDebtPolicy::MAXIMUM_MESSAGE);
        SmsDebtPolicy::maximum($options);
    }

    public static function invalidMaximums(): array
    {
        $range = static fn (mixed $maximum, int $minimum = 25000): array => [['minimum_debt_amount' => $minimum, 'maximum_debt_amount' => $maximum]];
        return [
            'not whole thousands' => $range(35500), 'one dollar' => $range(35001), 'below floor' => $range(24000),
            'zero' => $range(0), 'above max' => $range(SmsDebtPolicy::MAX + 1000), 'below minimum' => $range(30000, 31000),
            'string' => $range('35000'), 'float' => $range(35000.0), 'null' => $range(null),
            'without minimum' => [['maximum_debt_amount' => 35000]],
        ];
    }

    private function range(SQLiteConnection $db, int $minimum, int $maximum): void
    {
        $options = SmsRunOptions::normalize(3, null, SmsRunOptions::TIERS, SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY, null, null, null, $minimum, $maximum);
        $db->table('TblSMSRuns')->update(['Run_Options_JSON' => json_encode($options, JSON_THROW_ON_ERROR)]);
    }

    public function test_frozen_member_above_the_chosen_maximum_is_held_and_the_exact_ceiling_passes(): void
    {
        $db = $this->db();
        $this->frozen($db, '35000.00', '35000');
        $this->range($db, 25000, 35000);
        SmsDebtPolicy::assertPending($db, self::ID);
        $db->table('TblProspectsExport')->update(['Debt_Amount' => 36000]);
        $before = $db->table('TblProspectsExport')->get()->toJson();
        try {
            SmsDebtPolicy::assertPending($db, self::ID);
            self::fail('Frozen debt above the chosen maximum was released');
        } catch (SmsFrozenEligibilityHold $error) {
            self::assertStringContainsString('above the chosen debt maximum', $error->getMessage());
        }
        self::assertSame($before, $db->table('TblProspectsExport')->get()->toJson());
        // Minimum-only and legacy runs have no ceiling below the supported range.
        $db->table('TblSMSRuns')->update(['Run_Options_JSON' => null]);
        SmsDebtPolicy::assertPending($db, self::ID);
    }

    public function test_tier_fallback_above_the_maximum_is_held_even_when_its_frozen_amount_fits(): void
    {
        $db = $this->db();
        $db->table('TblProspectsExport')->insert(['PK' => 1, 'Request_ID' => self::ID, 'Debt_Amount' => 31000, 'Debt_Tier' => 'T5', 'Debt_Source' => 'Tier']);
        $this->range($db, 25000, 31000);
        SmsDebtPolicy::assertPending($db, self::ID);
        // T6 is a fixed 42,500 fallback: a fitting rounded amount cannot release it.
        $db->table('TblProspectsExport')->update(['Debt_Tier' => 'T6']);
        try {
            SmsDebtPolicy::assertPending($db, self::ID);
            self::fail('Tier fallback above the maximum was released');
        } catch (SmsFrozenEligibilityHold $error) {
            self::assertStringContainsString('above the chosen maximum', $error->getMessage());
        }
        $db->table('TblProspectsExport')->update(['Debt_Tier' => 'T5']);
        $this->range($db, 25000, 30000);
        $this->expectException(SmsFrozenEligibilityHold::class);
        SmsDebtPolicy::assertPending($db, self::ID);
    }

    public function test_boundary_reproof_uses_the_frozen_maximum_as_its_upper_bound(): void
    {
        $db = $this->db();
        $this->frozen($db, '25000.00', '25000');
        $this->range($db, 25000, 25000);
        SmsDebtPolicy::assertPending($db, self::ID);
        $db->table('TblTUData')->update(['derivedFields1_unsecdebtbalance' => '25000.50']);
        try {
            SmsDebtPolicy::assertPending($db, self::ID);
            self::fail('Source above the frozen maximum proved the boundary');
        } catch (SmsFrozenEligibilityHold) {
        }
        // Without a maximum the same boundary only proves the floor.
        $db->table('TblSMSRuns')->update(['Run_Options_JSON' => null]);
        SmsDebtPolicy::assertPending($db, self::ID);
        self::assertSame(1, $db->table('TblProspectsExport')->count());
    }
}
