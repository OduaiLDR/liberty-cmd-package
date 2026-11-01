<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsContactAttributionPlan;
use Cmd\Reports\Services\SmsContactEvent;
use PHPUnit\Framework\TestCase;

// These two pure classes need no application/bootstrap, container or database.
require_once dirname(__DIR__, 2).'/src/Services/SmsContactEvent.php';
require_once dirname(__DIR__, 2).'/src/Services/SmsContactAttributionPlan.php';

final class SmsContactAttributionPlanTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';
    private const C = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private function event(string $utc = '2026-10-23T12:00:01.123456789Z'): SmsContactEvent
    {
        return SmsContactEvent::fromCanonical(1, '0005550100', $utc, 'synthetic.contacts',
            'synthetic-contact', str_repeat('a', 64), 1, true);
    }

    private function row(string $id = self::A, ?string $at = '2026-10-23 12:00:00.1234560', mixed $precision = 6): array
    {
        return ['request_id' => $id, 'approved_at' => $at, 'precision' => $precision];
    }

    private function ready(?string $id): array { return ['state' => 'ready', 'request_id' => $id]; }
    private function hold(string $reason): array { return ['state' => 'hold', 'reason' => $reason]; }

    public function test_no_qualifying_membership_is_a_ready_null_not_an_invented_campaign(): void
    {
        self::assertSame($this->ready(null), SmsContactAttributionPlan::choose($this->event(), [], []));
        self::assertSame($this->ready(null), SmsContactAttributionPlan::choose($this->event(), [
            $this->row(self::A, '2026-10-23 12:00:02.0000000'),
            $this->row(self::B, '2026-10-23 12:00:03', null),
        ], []));
    }

    public function test_latest_exact_approval_wins_independently_of_row_and_uuid_order(): void
    {
        $old = $this->row(self::C, '2026-10-23 11:59:59.9999990');
        $latest = $this->row(self::A, '2026-10-23 12:00:01.1234560');
        $future = $this->row(self::B, '2026-10-23 12:00:01.1234570');
        foreach ([[$old, $latest, $future], [$future, $latest, $old], [$latest, $old, $future]] as $rows) {
            self::assertSame($this->ready(self::A), SmsContactAttributionPlan::choose($this->event(), $rows, []));
        }
    }

    public function test_full_contact_nanoseconds_are_compared_to_exact_microsecond_approvals_without_rounding(): void
    {
        $approved = [$this->row(self::A, '2026-10-23 12:00:00.1234560')];
        foreach ([
            '2026-10-23T12:00:00.123455999Z' => null,
            '2026-10-23T12:00:00.123456Z' => self::A,
            '2026-10-23T12:00:00.123456001Z' => self::A,
            '2026-10-23T12:00:00.123456099Z' => self::A,
        ] as $utc => $winner) {
            self::assertSame($this->ready($winner), SmsContactAttributionPlan::choose($this->event($utc), $approved, []));
        }
    }

    public function test_exact_second_and_short_fraction_sql_values_normalize_without_inventing_precision(): void
    {
        foreach (['2026-10-23 12:00:00', '2026-10-23 12:00:00.0', '2026-10-23 12:00:00.0000000',
            '2026-10-23 12:00:00.1', '2026-10-23 12:00:00.1234', '2026-10-23 12:00:00.123456'] as $at) {
            self::assertSame($this->ready(self::A), SmsContactAttributionPlan::choose($this->event(), [$this->row(self::A, $at)], []));
        }
    }

    public function test_historical_approval_in_contact_second_holds_at_every_contact_fraction(): void
    {
        foreach (['', '.000000001', '.100000000', '.999999999'] as $fraction) {
            foreach (['2026-10-23 12:00:00', '2026-10-23 12:00:00.0000000'] as $at) {
                self::assertSame($this->hold('historical_approval_precision'), SmsContactAttributionPlan::choose(
                    $this->event('2026-10-23T12:00:00'.$fraction.'Z'), [$this->row(self::A, $at, null)], []));
            }
        }
    }

    public function test_historical_previous_second_is_definitely_eligible_and_future_second_is_not(): void
    {
        self::assertSame($this->ready(self::A), SmsContactAttributionPlan::choose($this->event('2026-10-23T12:00:01Z'), [
            $this->row(self::A, '2026-10-23 12:00:00', null), $this->row(self::B, '2026-10-23 12:00:02', null),
        ], []));
        self::assertSame($this->ready(self::B), SmsContactAttributionPlan::choose($this->event('2026-10-23T12:00:01Z'), [
            $this->row(self::A, '2026-10-22 23:59:59', null), $this->row(self::B, '2026-10-23 00:00:00', null),
        ], []));
    }

    public function test_latest_tied_exact_or_historical_approvals_hold_without_a_uuid_tiebreak(): void
    {
        foreach ([6, null] as $precision) {
            $at = $precision === null ? '2026-10-23 12:00:00' : '2026-10-23 12:00:00.1234560';
            $rows = [$this->row(self::A, $at, $precision), $this->row(self::B, $at, $precision)];
            foreach ([$rows, array_reverse($rows)] as $order) {
                self::assertSame($this->hold('ambiguous_latest_approval'), SmsContactAttributionPlan::choose($this->event(), $order, []));
            }
        }
    }

    public function test_mixed_historical_and_exact_latest_second_has_unknown_order_even_after_that_second(): void
    {
        foreach (['2026-10-23 12:00:00', '2026-10-23 12:00:00.0000000', '2026-10-23 12:00:00.9999990'] as $exact) {
            $rows = [$this->row(self::A, '2026-10-23 12:00:00', null), $this->row(self::B, $exact)];
            foreach ([$rows, array_reverse($rows)] as $order) {
                self::assertSame($this->hold('ambiguous_approval_order'), SmsContactAttributionPlan::choose($this->event(), $order, []));
            }
        }
    }

    public function test_earlier_ties_and_uncertainty_do_not_block_a_definitely_later_approval(): void
    {
        foreach ([
            [$this->row(self::A, '2026-10-23 11:59:59', null), $this->row(self::B, '2026-10-23 11:59:59.9999990')],
            [$this->row(self::A, '2026-10-23 11:59:59', null), $this->row(self::B, '2026-10-23 11:59:59', null)],
            [$this->row(self::A, '2026-10-23 11:59:59.9999990'), $this->row(self::B, '2026-10-23 11:59:59.9999990')],
        ] as $earlier) {
            $rows = [...$earlier, $this->row(self::C, '2026-10-23 12:00:00.0000010')];
            self::assertSame($this->ready(self::C), SmsContactAttributionPlan::choose($this->event(), $rows, []));
            self::assertSame($this->ready(self::C), SmsContactAttributionPlan::choose($this->event(), array_reverse($rows), []));
        }
    }

    public function test_pending_approval_that_could_qualify_holds_even_if_its_history_is_not_committed(): void
    {
        foreach ([
            $this->row(self::A, '2026-10-20 00:00:00', null),
            $this->row(self::A, '2026-10-23 12:00:01', null),
            $this->row(self::A, '2026-10-23 12:00:01.1234560'),
        ] as $pending) {
            self::assertSame($this->hold('approval_in_progress'), SmsContactAttributionPlan::choose($this->event(), [], [$pending]));
            // Caller supplies both approving and failed-approve accepted states in this list.
            self::assertSame($this->hold('approval_in_progress'), SmsContactAttributionPlan::choose($this->event(), [
                $this->row(self::B, '2026-10-23 11:00:00.0000010'),
            ], [$pending]));
        }
    }

    public function test_definitely_future_pending_approvals_do_not_block_or_supply_a_winner(): void
    {
        $pending = [$this->row(self::A, '2026-10-23 12:00:01.1234570'), $this->row(self::B, '2026-10-23 12:00:02', null)];
        self::assertSame($this->ready(null), SmsContactAttributionPlan::choose($this->event(), [], $pending));
        self::assertSame($this->ready(self::C), SmsContactAttributionPlan::choose($this->event(), [$this->row(self::C)], $pending));
    }

    public function test_null_or_malformed_timestamps_hold_in_either_list_even_if_other_rows_could_win(): void
    {
        foreach ([null, '', '2026-10-23', '2026-10-23T12:00:00Z', '2026-10-23 12:00:00Z',
            '2026-10-23 12:00:00+00:00', '2026-10-23 12:00:00.12345678', '2026-02-29 12:00:00',
            '0000-01-01 00:00:00', '2026-10-23 24:00:00', '2026-10-23 12:60:00', '2026-10-23 12:00:60',
            "2026-10-23 12:00:00\n", ' 2026-10-23 12:00:00'] as $at) {
            foreach ([true, false] as $approved) {
                $bad = $this->row(self::A, $at);
                self::assertSame($this->hold('invalid_approval_metadata'), SmsContactAttributionPlan::choose($this->event(),
                    $approved ? [$bad] : [$this->row(self::B)], $approved ? [] : [$bad]));
            }
        }
    }

    public function test_unknown_precision_and_inconsistent_fractional_values_are_never_silently_truncated(): void
    {
        $rows = [];
        foreach ([0, 1, 3, 7, 9, -1, '6', '0', true, false, 6.0, [], ''] as $precision) {
            $rows[] = $this->row(self::A, '2026-10-23 12:00:00', $precision);
        }
        foreach (['2026-10-23 12:00:00.1', '2026-10-23 12:00:00.0000001'] as $at) $rows[] = $this->row(self::A, $at, null);
        foreach (['2026-10-23 12:00:00.1234561', '2026-10-23 12:00:00.9999999'] as $at) $rows[] = $this->row(self::A, $at, 6);
        foreach ($rows as $row) {
            self::assertSame($this->hold('unverified_approval_precision'), SmsContactAttributionPlan::choose($this->event(), [$row], []));
            self::assertSame($this->hold('unverified_approval_precision'), SmsContactAttributionPlan::choose($this->event(), [], [$row]));
        }
    }

    public function test_invalid_identifiers_shapes_and_missing_fields_fail_closed(): void
    {
        $badRows = [null, false, 'not an array', [], ['request_id' => self::A],
            ['request_id' => self::A, 'approved_at' => '2026-10-23 12:00:00'],
            ['request_id' => self::A, 'precision' => 6]];
        foreach ([null, 1, '', 'not-a-uuid', strtoupper(self::C), self::A."\n", ' '.self::A] as $id) {
            $badRows[] = array_replace($this->row(), ['request_id' => $id]);
        }
        foreach ($badRows as $row) {
            self::assertSame($this->hold('invalid_approval_metadata'), SmsContactAttributionPlan::choose($this->event(), [$row], []));
            self::assertSame($this->hold('invalid_approval_metadata'), SmsContactAttributionPlan::choose($this->event(), [], [$row]));
        }
        self::assertSame($this->hold('invalid_approval_metadata'), SmsContactAttributionPlan::choose($this->event(), ['named' => $this->row()], []));
        self::assertSame($this->hold('invalid_approval_metadata'), SmsContactAttributionPlan::choose($this->event(), [], [1 => $this->row()]));
        foreach ([20261023, false, []] as $at) {
            self::assertSame($this->hold('invalid_approval_metadata'), SmsContactAttributionPlan::choose($this->event(), [array_replace($this->row(), ['approved_at' => $at])], []));
        }
    }

    public function test_duplicate_request_ids_hold_even_when_metadata_is_identical_future_or_in_different_lists(): void
    {
        $row = $this->row();
        foreach ([$row, $this->row(self::A, '2099-01-01 00:00:00', null), $this->row(self::A, '2026-10-22 00:00:00', null)] as $duplicate) {
            foreach ([[[$row, $duplicate], []], [[], [$row, $duplicate]], [[$row], [$duplicate]]] as [$approved, $pending]) {
                self::assertSame($this->hold('invalid_approval_metadata'), SmsContactAttributionPlan::choose($this->event(), $approved, $pending));
            }
        }
    }

    public function test_calendar_boundaries_and_input_arrays_remain_exact_and_unchanged(): void
    {
        $event = $this->event('2024-02-29T00:00:00.000000001Z');
        $approved = [$this->row(self::A, '2024-02-28 23:59:59', null), $this->row(self::B, '2024-02-29 00:00:00.0000000')];
        $pending = [$this->row(self::C, '2024-03-01 00:00:00', null)];
        $before = [$approved, $pending, $event->utcExact];
        self::assertSame($this->ready(self::B), SmsContactAttributionPlan::choose($event, $approved, $pending));
        self::assertSame($before, [$approved, $pending, $event->utcExact]);
    }

    public function test_planner_does_not_invent_source_coverage_or_future_event_policy(): void
    {
        $event = SmsContactEvent::fromCanonical(1, '0005550100', '2099-01-01T00:00:00.123456789Z',
            'synthetic.contacts', 'synthetic-contact', str_repeat('a', 64), 2, false);
        self::assertSame($this->ready(self::A), SmsContactAttributionPlan::choose($event, [$this->row()], []));
        self::assertSame(2, $event->candidateCount); self::assertFalse($event->coverageComplete);
    }
}
