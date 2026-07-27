<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateReconsiderationReport\Formatter;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Support\Carbon;
use ReflectionMethod;

final class GenerateReconsiderationReportTest extends TestCase
{
    public function test_email_subject_identifies_each_company(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 24, 12, 0, 0, 'America/Los_Angeles'));
        $formatter = new Formatter;

        $this->assertSame(
            'Reconsideration Report - LDR - 07/24/2026',
            $this->invokePrivate($formatter, 'emailSubject', ['LDR'])
        );
        $this->assertSame(
            'Reconsideration Report - Progress Law - 07/24/2026',
            $this->invokePrivate($formatter, 'emailSubject', ['PLAW'])
        );
    }

    private function invokePrivate(object $target, string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }
}
