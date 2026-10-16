<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Invoices\InvoiceRegistryConfig;
use Cmd\Reports\Invoices\PacificTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InvoiceConfigAndTimeTest extends TestCase
{
    public function test_pacific_date_not_utc_including_dst_edges(): void
    {
        $d = static fn (string $utc) => PacificTime::date(new DateTimeImmutable($utc . ' UTC'));
        self::assertSame('2026-09-30', $d('2026-10-01 06:00'), 'the monthly 06:00 UTC run is still Sep 30 in PT (PDT)');
        self::assertSame('2026-10-31', $d('2026-11-01 06:00'));
        self::assertSame('2026-11-01', $d('2026-11-01 09:30'), '09:30Z on the fall-back day is 01:30 PST/PDT, still Nov 1');
        self::assertSame('2026-11-01', $d('2026-11-02 07:59'), 'PST (UTC-8) after fall back: 07:59Z is 23:59 PT on Nov 1');
        self::assertSame('2026-11-02', $d('2026-11-02 08:00'));
        self::assertSame('2026-03-08', $d('2026-03-09 06:59'), 'PDT (UTC-7) after spring forward: 06:59Z is 23:59 PT on Mar 8');
        self::assertSame('2026-03-09', $d('2026-03-09 07:00'));
        self::assertSame('2026-03-07', $d('2026-03-08 07:59'), 'PST before spring forward: 07:59Z is 23:59 PT on Mar 7');
        self::assertSame('2026-12-31', $d('2027-01-01 07:59'), 'year edge');
        self::assertSame('2027-01-01', $d('2027-01-01 08:00'));
        self::assertSame('2028-02-29', $d('2028-02-29 20:00'), 'leap day');
    }

    public function test_config_is_off_by_default_and_needs_master_switch_own_switch_and_bucket(): void
    {
        $cfg = static fn (array $env) => InvoiceRegistryConfig::fromReader(static fn (string $k) => $env[$k] ?? null);
        self::assertFalse($cfg([])->enabled);
        self::assertSame('us-east-2', $cfg([])->region);
        self::assertFalse($cfg([])->forGenerator('INVOICE_LT_PL_ENABLED'));
        $on = ['INVOICE_ENABLED' => 'true', 'INVOICE_S3_BUCKET' => 'ff-development-invoices', 'INVOICE_S3_REGION' => 'us-west-2'];
        self::assertFalse($cfg($on)->forGenerator('INVOICE_LT_PL_ENABLED'), 'master on but generator switch off');
        self::assertTrue($cfg($on + ['INVOICE_LT_PL_ENABLED' => '1'])->forGenerator('INVOICE_LT_PL_ENABLED'));
        self::assertFalse($cfg($on + ['INVOICE_LT_PL_ENABLED' => '1'])->forGenerator('INVOICE_LT_LDR_ENABLED'), 'switches are independent');
        self::assertFalse($cfg(['INVOICE_ENABLED' => 'false', 'INVOICE_S3_BUCKET' => 'b', 'INVOICE_LT_PL_ENABLED' => 'true'])->forGenerator('INVOICE_LT_PL_ENABLED'), 'master off wins');
        self::assertFalse($cfg(['INVOICE_ENABLED' => 'true', 'INVOICE_LT_PL_ENABLED' => 'true'])->forGenerator('INVOICE_LT_PL_ENABLED'), 'no bucket, no registry');
        self::assertSame('us-west-2', $cfg($on)->region);
    }
}
