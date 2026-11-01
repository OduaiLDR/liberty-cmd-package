<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsContactEvent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Pure canonical-value checks: no application bootstrap, clock, database or provider. */
final class SmsContactEventTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private function event(array $values = []): SmsContactEvent
    {
        return SmsContactEvent::fromCanonical(...array_replace([
            'prospectPk' => 1,
            'phone' => '0005550100',
            'explicitOriginalUtc' => '2026-10-23T07:00:00Z',
            'sourceSystem' => 'snowflake.contacts-v1',
            'sourceContactId' => 'synthetic-contact-1',
            'sourceContractSha256' => self::SHA,
            'candidateCount' => 1,
            'coverageComplete' => true,
        ], $values));
    }

    private function refuses(array $values): void
    {
        try {
            $this->event($values);
            self::fail('Expected refusal of malformed canonical contact-event values.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function test_canonical_values_are_preserved_without_claiming_source_verification(): void
    {
        $event = $this->event(['prospectPk' => 2147483647, 'sourceContactId' => '  Synthetic Contact #1  ']);
        self::assertSame(2147483647, $event->prospectPk);
        self::assertSame('0005550100', $event->phone);
        self::assertSame('2026-10-23T07:00:00.000000000Z', $event->utcExact);
        self::assertSame('2026-10-23 07:00:00.0000000', $event->utcSql);
        self::assertSame('2026-10-23', $event->californiaDate);
        self::assertSame('snowflake.contacts-v1', $event->sourceSystem);
        self::assertSame('Synthetic Contact #1', $event->sourceContactId);
        self::assertSame(self::SHA, $event->sourceContractSha256);
        self::assertSame(1, $event->candidateCount);
        self::assertTrue($event->coverageComplete);
    }

    public function test_dto_is_final_readonly_and_requires_explicit_coverage_inputs(): void
    {
        $class = new ReflectionClass(SmsContactEvent::class);
        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertTrue($class->getConstructor()->isPrivate());
        self::assertSame(8, (new ReflectionMethod(SmsContactEvent::class, 'fromCanonical'))->getNumberOfRequiredParameters());
        try {
            $this->event()->phone = '9995550100';
            self::fail('Canonical event properties must be immutable.');
        } catch (\Error) { self::assertTrue(true); }
    }

    public function test_incomplete_coverage_and_multiple_candidates_remain_explicit_holds_for_the_writer(): void
    {
        $event = $this->event(['candidateCount' => 3, 'coverageComplete' => false]);
        self::assertSame(3, $event->candidateCount);
        self::assertFalse($event->coverageComplete);
        // No source lookup or assertion of eligibility is performed by this DTO.
        self::assertSame('snowflake.contacts-v1', $event->sourceSystem);
        $this->refuses(['candidateCount' => 0]);
        $this->refuses(['candidateCount' => -1]);
    }

    public function test_fractional_precision_is_canonicalized_and_sql_is_floored_never_rounded(): void
    {
        foreach (['', '1', '12', '123', '1234', '12345', '123456', '1234567', '12345678', '123456789'] as $fraction) {
            $input = '2026-10-23T06:59:59'.($fraction === '' ? '' : '.'.$fraction).'Z';
            $exactFraction = str_pad($fraction, 9, '0');
            $event = $this->event(['explicitOriginalUtc' => $input]);
            self::assertSame('2026-10-23T06:59:59.'.$exactFraction.'Z', $event->utcExact);
            self::assertSame('2026-10-23 06:59:59.'.substr($exactFraction, 0, 7), $event->utcSql);
            self::assertSame('2026-10-22', $event->californiaDate);
        }
        $last = $this->event(['explicitOriginalUtc' => '2026-10-23T06:59:59.999999999Z']);
        self::assertSame('2026-10-23 06:59:59.9999999', $last->utcSql);
        self::assertSame('2026-10-22', $last->californiaDate);
        self::assertSame('2026-10-23', $this->event(['explicitOriginalUtc' => '2026-10-23T07:00:00.000000001Z'])->californiaDate);
    }

    public function test_exact_lexical_order_retains_nanoseconds_even_when_sql_values_are_equal(): void
    {
        $inputs = ['2026-10-23T00:00:00.000000001Z', '2026-10-23T00:00:00.000000009Z',
            '2026-10-23T00:00:00.00000001Z', '2026-10-23T00:00:00.999999999Z', '2026-10-23T00:00:01Z'];
        $exact = array_map(SmsContactEvent::canonicalUtc(...), $inputs);
        for ($i = 1; $i < count($exact); $i++) self::assertLessThan(0, strcmp($exact[$i - 1], $exact[$i]));
        self::assertSame($this->event(['explicitOriginalUtc' => $inputs[0]])->utcSql,
            $this->event(['explicitOriginalUtc' => $inputs[1]])->utcSql);
        self::assertNotSame($exact[0], $exact[1]);
    }

    public function test_california_dates_use_iana_dst_rules_not_a_fixed_offset(): void
    {
        foreach ([
            '2026-01-01T07:59:59.999999999Z' => '2025-12-31',
            '2026-01-01T08:00:00Z' => '2026-01-01',
            '2026-07-01T06:59:59.999999999Z' => '2026-06-30',
            '2026-07-01T07:00:00Z' => '2026-07-01',
            // Spring skips 02:00 local; both explicit UTC instants remain distinct.
            '2026-03-08T09:59:59.999999999Z' => '2026-03-08',
            '2026-03-08T10:00:00Z' => '2026-03-08',
            // Both instances of 01:30 on the fall transition have one calendar date.
            '2026-11-01T08:30:00.123456789Z' => '2026-11-01',
            '2026-11-01T09:30:00.123456789Z' => '2026-11-01',
        ] as $utc => $date) {
            self::assertSame($date, $this->event(['explicitOriginalUtc' => $utc])->californiaDate);
        }
    }

    public function test_default_process_timezone_does_not_change_either_utc_or_california_date(): void
    {
        $previous = date_default_timezone_get();
        try {
            foreach (['Pacific/Kiritimati', 'America/Adak', 'UTC'] as $zone) {
                date_default_timezone_set($zone);
                $event = $this->event(['explicitOriginalUtc' => '2026-07-01T06:59:59.999999999Z']);
                self::assertSame('2026-07-01T06:59:59.999999999Z', $event->utcExact);
                self::assertSame('2026-06-30', $event->californiaDate);
            }
        } finally { date_default_timezone_set($previous); }
    }

    public function test_calendar_validation_accepts_real_leap_days_and_supported_years(): void
    {
        foreach (['0001-01-01T12:00:00Z', '2000-02-29T12:00:00Z', '2024-02-29T12:00:00Z', '9999-12-31T23:59:59.999999999Z'] as $utc) {
            self::assertSame(substr($utc, 0, 19).'.'.str_pad(str_contains($utc, '.') ? substr($utc, 20, -1) : '', 9, '0').'Z',
                SmsContactEvent::canonicalUtc($utc));
        }
    }

    public function test_factory_rejects_a_california_date_outside_sql_years_without_restricting_utc_helper(): void
    {
        $minimumUtc = '0001-01-01T00:00:00.000000001Z';
        self::assertSame($minimumUtc, SmsContactEvent::canonicalUtc($minimumUtc));
        $this->refuses(['explicitOriginalUtc' => $minimumUtc]);
        self::assertSame('0001-01-01', $this->event(['explicitOriginalUtc' => '0001-01-01T12:00:00Z'])->californiaDate);
        self::assertSame('9999-12-31', $this->event(['explicitOriginalUtc' => '9999-12-31T23:59:59.999999999Z'])->californiaDate);
    }

    public function test_timestamp_rejects_ambiguous_noncanonical_or_rolled_over_values(): void
    {
        foreach (['', '2026-10-23', '2026-10-23 07:00:00', '2026-10-23T07:00:00',
            '2026-10-23T07:00:00+00:00', '2026-10-23T00:00:00-07:00', '2026-10-23t07:00:00Z',
            '2026-10-23T07:00:00z', '2026-1-23T07:00:00Z', '2026-10-23T7:00:00Z',
            '2026-10-23T07:00:00.Z', '2026-10-23T07:00:00.1234567890Z',
            '2026-10-23T07:00:00.12e3Z', '2026-10-23T24:00:00Z', '2026-10-23T07:60:00Z',
            '2026-10-23T07:00:60Z', '2026-02-29T07:00:00Z', '1900-02-29T07:00:00Z',
            '2026-04-31T07:00:00Z', '2026-00-23T07:00:00Z', '2026-13-23T07:00:00Z',
            '2026-10-00T07:00:00Z', '0000-01-01T00:00:00Z', '10000-01-01T00:00:00Z',
            "2026-10-23T07:00:00Z\n", "2026-10-23T07:00:00Z\0", ' 2026-10-23T07:00:00Z'] as $utc) {
            $this->refuses(['explicitOriginalUtc' => $utc]);
            try { SmsContactEvent::canonicalUtc($utc); self::fail('The shared parser must also refuse malformed UTC.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function test_prospect_and_phone_require_exact_canonical_values(): void
    {
        foreach ([0, -1, 2147483648] as $pk) $this->refuses(['prospectPk' => $pk]);
        foreach (['', '123', '10005550100', '+0005550100', '000-555-0100', '0005550100 ',
            ' 0005550100', "0005550100\n", '00055501O0', '٠٠٠٥٥٥٠١٠٠'] as $phone) $this->refuses(['phone' => $phone]);
        self::assertSame('0000000000', $this->event(['phone' => '0000000000'])->phone, 'This DTO checks canonical shape, not provider usability.');
    }

    public function test_source_fields_are_bounded_printable_and_contract_hash_is_lowercase(): void
    {
        self::assertSame('a'.str_repeat('0', 39), $this->event(['sourceSystem' => 'a'.str_repeat('0', 39)])->sourceSystem);
        self::assertSame(str_repeat('X', 255), $this->event(['sourceContactId' => str_repeat('X', 255)])->sourceContactId);
        foreach (['', 'Uppercase', '1source', ' source', 'source ', 'source/path', 'évent', 'a'.str_repeat('0', 40)] as $source) $this->refuses(['sourceSystem' => $source]);
        foreach (['', '   ', str_repeat('X', 256), "a\tb", "a\nb", "a\rb", "a\0b", "\tidentifier", "identifier\n", "a\x7Fb", 'café'] as $id) $this->refuses(['sourceContactId' => $id]);
        foreach (['', str_repeat('0', 63), str_repeat('0', 65), str_repeat('g', 64), strtoupper(self::SHA), self::SHA."\n", ' '.self::SHA] as $sha) $this->refuses(['sourceContractSha256' => $sha]);
    }

    public function test_dto_has_no_clock_policy_and_does_not_reject_a_valid_future_instant(): void
    {
        self::assertSame('2099-01-01T00:00:00.000000000Z', $this->event(['explicitOriginalUtc' => '2099-01-01T00:00:00Z'])->utcExact);
    }
}
