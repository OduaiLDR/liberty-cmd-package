<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/** Monther, Nov 5, 2026: a run stops at never-texted people. Off by default, so today's behaviour holds until it's switched on. */
final class SmsSelectionPhasesTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        Container::setInstance(new Container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    private function set(array $values): void
    {
        Container::getInstance()->instance('config', new Repository(['sms-exports' => $values]));
    }

    public function test_by_default_a_run_keeps_topping_up_with_people_texted_before(): void
    {
        $this->set([]);
        self::assertSame([0, 1, 2], SmsRecordExportRepository::selectionPhases());
    }

    public function test_switched_on_a_run_stops_at_never_texted_people(): void
    {
        $this->set(['selection_never_texted_only' => true]);
        self::assertSame([0, 1], SmsRecordExportRepository::selectionPhases());
    }

    public function test_only_a_real_true_switches_it_on(): void
    {
        foreach ([false, null, 0, 1, '1', 'true', ''] as $value) {
            $this->set(['selection_never_texted_only' => $value]);
            self::assertSame([0, 1, 2], SmsRecordExportRepository::selectionPhases());
        }
    }

    public function test_the_selection_loop_uses_this_list(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Repositories/SmsRecordExportRepository.php');
        self::assertStringContainsString('foreach (self::selectionPhases() as $phase)', $source);
        self::assertStringNotContainsString('foreach ([0, 1, 2] as $phase)', $source);
    }
}
