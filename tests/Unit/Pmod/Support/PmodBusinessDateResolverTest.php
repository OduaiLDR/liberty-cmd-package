<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Support;

use Cmd\Reports\Pmod\Support\PmodBusinessDateResolver;
use Cmd\Reports\Tests\TestCase;

final class PmodBusinessDateResolverTest extends TestCase
{
    public function test_it_moves_weekend_dates_forward_to_monday(): void
    {
        self::assertSame('2026-05-11', PmodBusinessDateResolver::nextBusinessDay('2026-05-09'));
        self::assertSame('2026-05-11', PmodBusinessDateResolver::nextBusinessDay('2026-05-10'));
        self::assertSame('2026-05-11', PmodBusinessDateResolver::nextBusinessDay('2026-05-11'));
    }

    public function test_it_detects_date_rejection_messages(): void
    {
        self::assertTrue(PmodBusinessDateResolver::looksLikeDateRejection('The process date is a holiday.'));
        self::assertTrue(PmodBusinessDateResolver::looksLikeDateRejection('Invalid draft date.'));
        self::assertFalse(PmodBusinessDateResolver::looksLikeDateRejection('Unauthorized token.'));
    }
}
