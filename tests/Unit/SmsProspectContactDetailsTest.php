<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsProspectContactDetails;
use PHPUnit\Framework\TestCase;

final class SmsProspectContactDetailsTest extends TestCase
{
    private function contact(string $cid = '123', ?string $date = '2026-10-12', string $source = 'DP_LT'): array
    {
        return ['source' => $source, 'cid' => $cid, 'date' => $date];
    }

    public function test_incomplete_or_first_row_only_coverage_cannot_select_attribution(): void
    {
        $plan = SmsProspectContactDetails::plan(null, null, null, [$this->contact()], ['DP_LT']);
        self::assertSame('held', $plan['state']);
        self::assertSame('coverage_incomplete', $plan['reason']);
    }

    public function test_new_details_remain_one_matching_pair_and_replays_do_not_update(): void
    {
        $row = $this->contact('000123');
        $plan = SmsProspectContactDetails::plan(null, null, null, [$row, $row], ['DP_LT'], true);
        self::assertSame(['state' => 'ready', 'reason' => null, 'details' => $row], $plan);
        $replay = SmsProspectContactDetails::plan('000123', '2026-10-12', null, [$row], ['DP_LT'], true);
        self::assertSame('unchanged', $replay['state']);
    }

    public function test_insert_order_does_not_choose_between_genuine_contacts(): void
    {
        $rows = [$this->contact('123'), $this->contact('124', '2026-10-11')];
        foreach ([$rows, array_reverse($rows)] as $candidates) {
            $plan = SmsProspectContactDetails::plan(null, null, null, $candidates, ['DP_LT'], true);
            self::assertSame('held', $plan['state']);
            self::assertSame('ambiguous_contact', $plan['reason']);
        }
    }

    public function test_existing_identity_is_preserved_and_missing_date_only_comes_from_that_identity(): void
    {
        $plan = SmsProspectContactDetails::plan('123', null, null,
            [$this->contact('124', '2026-10-11'), $this->contact('123')], ['DP_LT'], true);
        self::assertSame('ready', $plan['state']);
        self::assertSame($this->contact(), $plan['details']);
        $wrong = SmsProspectContactDetails::plan('123', null, null, [$this->contact('124')], ['DP_LT'], true);
        self::assertSame('unchanged', $wrong['state']);
        self::assertNull($wrong['details']);
    }

    public function test_old_unpaired_date_is_not_assigned_to_a_new_cid(): void
    {
        $plan = SmsProspectContactDetails::plan(null, '2026-10-11', null, [$this->contact()], ['DP_LT'], true);
        self::assertSame('unpaired_existing_date', $plan['reason']);
        self::assertNull($plan['details']);
    }

    public function test_existing_date_conflict_is_held_instead_of_overwritten(): void
    {
        $plan = SmsProspectContactDetails::plan('123', '2026-10-11', null, [$this->contact()], ['DP_LT'], true);
        self::assertSame('existing_date_conflict', $plan['reason']);
        self::assertNull($plan['details']);
    }

    public function test_missing_source_date_stays_null_without_borrowing_another_cids_date(): void
    {
        $row = $this->contact('123', null);
        $plan = SmsProspectContactDetails::plan(null, null, null, [$row], ['DP_LT'], true);
        self::assertSame(['state' => 'ready', 'reason' => 'source_date_unknown', 'details' => $row], $plan);
        $known = SmsProspectContactDetails::plan('123', '2026-10-12', null, [$row], ['DP_LT'], true);
        self::assertSame('unchanged', $known['state']);
        self::assertSame('2026-10-12', $known['details']['date']);
    }

    public function test_enrollment_cid_does_not_silently_cross_company_sources(): void
    {
        $rows = [$this->contact('123', '2026-10-12', 'DP_LDR'), $this->contact('123', '2026-10-13', 'DP_PLAW')];
        $unknown = SmsProspectContactDetails::plan('123', null, null, $rows, ['DP_LDR', 'DP_PLAW'], true);
        self::assertSame('ambiguous_contact', $unknown['reason']);
        $known = SmsProspectContactDetails::plan('123', null, 'DP_PLAW', $rows, ['DP_LDR', 'DP_PLAW'], true);
        self::assertSame($rows[1], $known['details']);
    }

    public function test_source_conflicts_and_invalid_calendar_values_remain_held(): void
    {
        foreach ([[$this->contact(), $this->contact('123', null)],
            [$this->contact('123', '2026-02-30')], [$this->contact('123', '0000-01-01')],
            [$this->contact('123', '2026-10-12', 'DP_LDR')],
            [$this->contact('123 ')]] as $rows) {
            $plan = SmsProspectContactDetails::plan(null, null, null, $rows, ['DP_LT'], true);
            self::assertSame('held', $plan['state']);
            self::assertNull($plan['details']);
        }
    }

    public function test_decimal_ids_are_not_coerced_and_excessive_matches_are_held(): void
    {
        $rows = [$this->contact('000123'), $this->contact('123')];
        $plan = SmsProspectContactDetails::plan(null, null, null, $rows, ['DP_LT'], true);
        self::assertSame('ambiguous_contact', $plan['reason']);
        $large = SmsProspectContactDetails::plan(null, null, null,
            array_fill(0, SmsProspectContactDetails::MAX_CANDIDATES + 1, $this->contact()), ['DP_LT'], true);
        self::assertSame('too_many_contacts', $large['reason']);
    }
}
