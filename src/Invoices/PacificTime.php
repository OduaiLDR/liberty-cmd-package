<?php

namespace Cmd\Reports\Invoices;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** The invoice date is always the America/Los_Angeles calendar date, never the server's UTC date. */
final class PacificTime
{
    public const ZONE = 'America/Los_Angeles';

    /** Y-m-d in Pacific time for an instant (now when omitted). */
    public static function date(?DateTimeInterface $instant = null): string
    {
        $instant ??= new DateTimeImmutable('now');

        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d');
    }
}
