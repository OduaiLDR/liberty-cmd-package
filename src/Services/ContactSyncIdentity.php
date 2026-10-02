<?php

namespace Cmd\Reports\Services;

/** An ID is only a routing hint: companies can reuse each other's numeric IDs. */
final class ContactSyncIdentity
{
    public static function matches(array $left, array $right): bool
    {
        $left = array_change_key_case($left, CASE_LOWER);
        $right = array_change_key_case($right, CASE_LOWER);
        $name = static fn ($row) => mb_strtoupper(str_replace([' ', '.', ',', '-', '/', "'", "\t"], '', trim((string) ($row['client'] ?? ''), ' ')), 'UTF-8');
        $phone = static fn ($row) => str_replace([' ', '-', '(', ')', '+', '.', '/', "\t", "\n", "\r"], '', trim((string) ($row['phone'] ?? '')));
        $le = mb_strtolower(trim((string) ($left['email'] ?? ''), ' '), 'UTF-8');
        $re = mb_strtolower(trim((string) ($right['email'] ?? ''), ' '), 'UTF-8');
        $lp = $phone($left);
        $rp = $phone($right);

        return $name($left) !== '' && $name($left) === $name($right)
            && ($le === '' || $re === '' || $le === $re)
            && ($lp === '' || $rp === '' || $lp === $rp)
            && (($le === $re && strpos($le, '@') > 0) || ($lp === $rp && strlen($lp) >= 7 && ctype_digit($lp)));
    }

    public static function nameSql(string $alias): string
    {
        $value = "UPPER(LTRIM(RTRIM(COALESCE({$alias}.Client, ''))))";
        foreach (["' '", "'.'", "','", "'-'", "'/'", 'CHAR(39)', 'CHAR(9)'] as $character) {
            $value = "REPLACE({$value}, {$character}, '')";
        }
        return "({$value} COLLATE Latin1_General_100_BIN2)";
    }

    public static function phoneSql(string $alias): string
    {
        $value = "LTRIM(RTRIM(COALESCE({$alias}.Phone, '')))";
        foreach (["' '", "'-'", "'('", "')'", "'+'", "'.'", "'/'", 'CHAR(9)', 'CHAR(10)', 'CHAR(13)'] as $character) {
            $value = "REPLACE({$value}, {$character}, '')";
        }
        return $value;
    }

    public static function sql(string $left, string $right): string
    {
        $ln = self::nameSql($left);
        $rn = self::nameSql($right);
        $lp = self::phoneSql($left);
        $rp = self::phoneSql($right);
        $le = "(LOWER(LTRIM(RTRIM(COALESCE({$left}.Email, '')))) COLLATE Latin1_General_100_BIN2)";
        $re = "(LOWER(LTRIM(RTRIM(COALESCE({$right}.Email, '')))) COLLATE Latin1_General_100_BIN2)";
        return "({$ln} <> '' AND {$ln} = {$rn}
            AND ({$le} = '' OR {$re} = '' OR {$le} = {$re})
            AND ({$lp} = '' OR {$rp} = '' OR {$lp} = {$rp})
            AND ((CHARINDEX('@', {$le}) > 1 AND {$le} = {$re})
                OR (LEN({$lp}) >= 7 AND {$lp} NOT LIKE '%[^0-9]%' AND {$lp} = {$rp})))";
    }
}
