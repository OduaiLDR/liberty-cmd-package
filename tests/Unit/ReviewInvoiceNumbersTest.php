<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\ReviewInvoiceNumbers;
use Cmd\Reports\Tests\Unit\Invoices\TestFiles;
use PHPUnit\Framework\TestCase;

final class ReviewInvoiceNumbersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/review-numbers-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        TestFiles::removeTree($this->root);
    }

    public function test_company_pairs_and_dates_have_independent_atomic_sequences(): void
    {
        $first = new ReviewInvoiceNumbers($this->root);
        $second = new ReviewInvoiceNumbers($this->root);
        self::assertSame('LT-LDR-20261101-01', $first->next('LT', 'LDR', '2026-11-01'));
        self::assertSame('LT-LDR-20261101-02', $second->next('LT', 'LDR', '2026-11-01'));
        self::assertSame('LT-PL-20261101-01', $first->next('LT', 'PL', '2026-11-01'));
        self::assertSame('LT-LDR-20261102-01', $first->next('LT', 'LDR', '2026-11-02'));
        self::assertSame('LDR-NGF-20261101-01', $first->next('LDR', 'NGF', '2026-11-01'));
        self::assertSame('PL-NGF-20261101-01', $first->next('PL', 'NGF', '2026-11-01'));
        self::assertSame('PL-PL-20261101-01', $first->next('PL', 'PL', '2026-11-01'));
    }

    public function test_previews_do_not_consume_numbers_or_create_reservations(): void
    {
        $numbers = new ReviewInvoiceNumbers($this->root);
        self::assertSame('LT-PL-20261101-01', $numbers->next('LT', 'PL', '2026-11-01', false));
        self::assertDirectoryDoesNotExist($this->root);
        self::assertSame('LT-PL-20261101-01', $numbers->next('LT', 'PL', '2026-11-01'));
        self::assertSame('LT-PL-20261101-02', $numbers->next('LT', 'PL', '2026-11-01', false));
        self::assertSame('LT-PL-20261101-02', $numbers->next('LT', 'PL', '2026-11-01'));
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReviewInvoiceNumbers($this->root))->next('LT', 'LDR', '2026-02-30');
    }

    public function test_company_codes_cannot_escape_the_review_directory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReviewInvoiceNumbers($this->root))->next('../LT', 'LDR', '2026-11-01');
    }

    public function test_numbers_are_not_reused_after_the_two_digit_range_is_exhausted(): void
    {
        $numbers = new ReviewInvoiceNumbers($this->root);
        for ($sequence = 1; $sequence <= 99; $sequence++) {
            $numbers->next('LT', 'LDR', '2026-11-01');
        }
        $this->expectException(\RuntimeException::class);
        $numbers->next('LT', 'LDR', '2026-11-01');
    }
}
