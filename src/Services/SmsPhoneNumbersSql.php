<?php

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\DB;

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
        // Jacob, Nov 3: contacts are matched on Phone1-4. The old one-phone column is read only for a row
        // without Phone1, and only while it exists; once it is dropped, no query names it.
        $branches = self::hasLegacyPhone() ? ["SELECT Phone, {$fields} FROM TblPhoneNumbers{$hint} WHERE Phone1 IS NULL"] : [];
        foreach (range(1,4) as $slot) $branches[] = "SELECT Phone{$slot} AS Phone, {$fields} FROM TblPhoneNumbers{$hint} WHERE Phone{$slot} IS NOT NULL AND Phone{$slot}<>''";
        return '('.implode(' UNION ALL ', $branches).') AS '.$alias;
    }

    /** Checked on every call, so long-running workers stop naming the column as soon as it is dropped. */
    private static function hasLegacyPhone(): bool
    {
        try {
            return DB::connection('sqlsrv')->getSchemaBuilder()->hasColumn('TblPhoneNumbers', 'Phone');
        } catch (\Throwable) {
            return true;
        }
    }
}
