<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Host telemetry filters consult this while import files, credentials or receipts are in scope. */
final class SmsDebtPleteImportPrivacy
{
    private static int $depth = 0;

    public static function run(callable $operation): mixed
    {
        ++self::$depth;
        try { return $operation(); }
        finally { --self::$depth; }
    }

    public static function active(): bool { return self::$depth > 0; }
}
