<?php

namespace Cmd\Reports\Services;

/** Pure projection of wide contact rows into the existing one-phone read contract. */
final class SmsPhoneNumbersSql
{
    public static function from(string $alias = 'c', string $hint = '', bool $wide = false): string
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $alias)) throw new \InvalidArgumentException('Invalid phone projection alias.');
        $normalized = strtoupper(preg_replace('/\s+/', '', $hint));
        $hint = match ($normalized) { ''=>'', 'WITH(HOLDLOCK)'=>' WITH (HOLDLOCK)',
            'WITH(UPDLOCK,HOLDLOCK)'=>' WITH (UPDLOCK, HOLDLOCK)', default=>throw new \InvalidArgumentException('Invalid phone projection hint.') };
        if (! $wide) return 'TblPhoneNumbers AS '.$alias.$hint;
        $fields = 'Source, CID, Created_Date, PK, Data_Source, External_ID';
        $branches = ["SELECT Phone, {$fields} FROM TblPhoneNumbers{$hint} WHERE Phone1 IS NULL"];
        foreach (range(1,4) as $slot) $branches[] = "SELECT Phone{$slot} AS Phone, {$fields} FROM TblPhoneNumbers{$hint} WHERE Phone{$slot} IS NOT NULL AND Phone{$slot}<>''";
        return '('.implode(' UNION ALL ', $branches).') AS '.$alias;
    }
}
