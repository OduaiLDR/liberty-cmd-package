<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Invoices\InvoiceRegistry;
use Cmd\Reports\Invoices\InvoiceRegistryException;
use DateTimeImmutable;
use Fiber;
use PHPUnit\Framework\TestCase;

final class InvoiceRegistryTest extends TestCase
{
    private function at(string $utc): callable
    {
        return static fn () => new DateTimeImmutable($utc . ' UTC');
    }

    public function test_numbers_start_at_01_each_day_and_rise_only_when_taken(): void
    {
        $store = new FakeInvoiceStore();
        $registry = new InvoiceRegistry($store, $this->at('2026-10-15 20:00'));
        self::assertSame('LT-PL-20261015-01', $registry->reserve('LT-PL', '2026-10-15'));
        self::assertSame('LT-PL-20261015-02', $registry->reserve('LT-PL', '2026-10-15'));
        self::assertSame('LT-PL-20261016-01', $registry->reserve('LT-PL', '2026-10-16'), 'a new day starts at 01');
        self::assertSame('LDR-PAR-20261015-01', $registry->reserve('LDR-PAR', '2026-10-15'), 'each series counts on its own');
        self::assertSame('LDR-EXP-JO-20261015-01', $registry->reserve('LDR-EXP-JO', '2026-10-15'));
        self::assertSame('LDR-EXP-GT-20261015-01', $registry->reserve('LDR-EXP-GT', '2026-10-15'), 'Guatemala counts on its own');
        self::assertSame('LDR-EXP-JO-20261015-02', $registry->reserve('LDR-EXP-JO', '2026-10-15'));
        foreach (['LDR-EXP-JOR', 'COMB-EXP-GUA'] as $retired) {
            try {
                $registry->reserve($retired, '2026-10-15');
                self::fail("{$retired} is no longer a series");
            } catch (\Cmd\Reports\Invoices\InvoiceRegistryException) {
            }
        }
        foreach (['COMB', 'LDR', 'LT', 'PLAW'] as $c) {
            self::assertSame(['expenses-jordan', 'expenses-guatemala'], [\Cmd\Reports\Invoices\InvoiceSeries::get("{$c}-EXP-JO")['company'], \Cmd\Reports\Invoices\InvoiceSeries::get("{$c}-EXP-GT")['company']]);
        }
        self::assertSame('expenses-guatemala/ldr/2026/10/LDR-EXP-GT-20261015-01/', \Cmd\Reports\Invoices\InvoiceSeries::folder('LDR-EXP-GT', 'LDR-EXP-GT-20261015-01'));
        self::assertSame('2026-10-15', $registry->today());
        $reservation = json_decode($store->objects['_reservations/LT-PL/20261015/02.json'], true);
        self::assertSame(['reserved', 'LT-PL', '2026-10-15'], [$reservation['status'], $reservation['series'], $reservation['invoiceDate']]);
    }

    public function test_today_uses_pacific_date_at_the_scheduled_utc_time(): void
    {
        $registry = new InvoiceRegistry(new FakeInvoiceStore(), $this->at('2026-11-01 06:00'));
        self::assertSame('2026-10-31', $registry->today());
    }

    public function test_20_concurrent_reservations_all_get_distinct_numbers(): void
    {
        $store = new FakeInvoiceStore();
        $fibers = $numbers = [];
        for ($i = 0; $i < 20; $i++) {
            $fibers[$i] = new Fiber(function () use ($store, &$numbers, $i): void {
                $numbers[$i] = (new InvoiceRegistry($store))->reserve('LT-PL', '2026-10-15', ['requestedBy' => "racer-{$i}"]);
            });
            $fibers[$i]->start();
        }
        while ($fibers) {
            foreach ($fibers as $i => $fiber) {
                $fiber->isTerminated() ? $fibers = array_diff_key($fibers, [$i => 1]) : $fiber->resume();
            }
        }
        self::assertCount(20, $numbers);
        self::assertCount(20, array_unique($numbers), 'no number was handed out twice');
        $expected = array_map(static fn (int $n) => sprintf('LT-PL-20261015-%02d', $n), range(1, 20));
        $sorted = $numbers;
        sort($sorted);
        self::assertSame($expected, $sorted, 'numbers are 01..20 with no gaps');
        self::assertGreaterThan(20, $store->puts, 'racers really collided and retried the next number');
    }

    public function test_transient_conflicts_are_retried_then_give_up_loudly(): void
    {
        $store = new FakeInvoiceStore();
        $store->transientFailures = ['_reservations/LT-PL/20261015/01.json', '_reservations/LT-PL/20261015/01.json'];
        self::assertSame('LT-PL-20261015-01', (new InvoiceRegistry($store))->reserve('LT-PL', '2026-10-15'), 'two 409s then success keeps 01');

        $store = new FakeInvoiceStore();
        $store->transientFailures = array_fill(0, 3, '_reservations/LT-PL/20261015/01.json');
        $this->expectException(InvoiceRegistryException::class);
        (new InvoiceRegistry($store))->reserve('LT-PL', '2026-10-15');
    }

    public function test_exhausting_99_numbers_fails_instead_of_reusing(): void
    {
        $store = new FakeInvoiceStore();
        $registry = new InvoiceRegistry($store);
        for ($i = 1; $i <= 99; $i++) {
            self::assertSame(sprintf('LT-PL-20261015-%02d', $i), $registry->reserve('LT-PL', '2026-10-15'));
        }
        $this->expectException(InvoiceRegistryException::class);
        $this->expectExceptionMessage('01-99 are all taken');
        $registry->reserve('LT-PL', '2026-10-15');
    }

    public function test_bad_input_is_rejected_before_anything_is_written(): void
    {
        $store = new FakeInvoiceStore();
        $registry = new InvoiceRegistry($store);
        foreach ([['NOPE', '2026-10-15'], ['LT-PL', '2026-02-30'], ['LT-PL', '20261015'], ['LT-PL', '']] as [$series, $date]) {
            try {
                $registry->reserve($series, $date);
                self::fail("accepted {$series} {$date}");
            } catch (InvoiceRegistryException) {
            }
        }
        self::assertSame(0, $store->puts);
    }
}
