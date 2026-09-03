<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodActionMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The `Action:` labels the client portal actually sends, taken from
 * READER.CONTACTS_NOTES for 2026-06-28 onward (LDR and PLAW). fromLabel() throws
 * on anything it does not know, so an unmapped label is a 422 at the door.
 */
class PmodActionMapperPortalLabelsTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: PmodActionType}>
     */
    public static function realPortalLabels(): array
    {
        return [
            ['Reschedule Payment', PmodActionType::CHANGE_PAYMENT],
            ['Make an additional payment', PmodActionType::ADDITIONAL_PAYMENT],
            ['Updated Banking', PmodActionType::ADD_BANK_ACCOUNT],
            ['Skip Payment', PmodActionType::SKIP_PAYMENT],
            ['Remove Creditor and Decrease Payment', PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT],
            ['Remove Creditor and Decrease Term', PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM],
            ['Add Creditor and Increase Payment', PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT],
            ['Add Creditor and Extend Program', PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM],
            ['Reschedule all future payments', PmodActionType::RESCHEDULE_ALL_PAYMENTS],
            ['Increase All Future Payments', PmodActionType::INCREASE_ALL_FUTURE_PAYMENTS],
            ['Increase Payments', PmodActionType::PMOD_INCREASE_PAYMENTS],
            ['Extend Program', PmodActionType::PMOD_EXTEND_PROGRAM],
        ];
    }

    /**
     * @dataProvider realPortalLabels
     */
    public function testRealPortalLabelsMap(string $label, PmodActionType $expected): void
    {
        $this->assertSame($expected, PmodActionMapper::fromLabel($label));
    }

    /**
     * The portal sends "Reschedule  Payment" with two spaces, 22 times in the
     * period measured. Every one would have thrown.
     */
    public function testDoubleSpacedLabelsStillMap(): void
    {
        $this->assertSame(PmodActionType::CHANGE_PAYMENT, PmodActionMapper::fromLabel('Reschedule  Payment'));
        $this->assertSame(PmodActionType::CHANGE_PAYMENT, PmodActionMapper::fromLabel("Reschedule\tPayment"));
        $this->assertSame(PmodActionType::SKIP_PAYMENT, PmodActionMapper::fromLabel('  Skip   Payment  '));
    }

    /**
     * These are real labels the portal sends that we deliberately do NOT map -
     * recorded so nobody "fixes" them by guessing. Each needs Coalition to say
     * what it means before it can be given a handler.
     *
     * @return list<array{0: string}>
     */
    public static function unmappedPortalLabels(): array
    {
        return [
            ['PMOD Approved'],
            ['Reschedule NSF Payment'],
            ['switch account'],
            ['Decrease Payment'],
        ];
    }

    /**
     * @dataProvider unmappedPortalLabels
     */
    public function testUnknownLabelsThrowRatherThanGuess(string $label): void
    {
        $this->expectException(InvalidArgumentException::class);
        PmodActionMapper::fromLabel($label);
    }
}
