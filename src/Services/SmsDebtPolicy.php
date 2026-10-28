<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/** Generation policy only. The current-source waterfall and import validity stay unchanged. */
final class SmsDebtPolicy
{
    public const FLOOR = 25000;

    public const MAX = 9999999000;

    public const VERSION = 'source-debt-minimum-v1';

    public static function minimum(?array $options): int
    {
        if (! array_key_exists('minimum_debt_amount', $options ?? [])) {
            return self::FLOOR;
        }
        if (! is_int($options['minimum_debt_amount'])) {
            throw new InvalidArgumentException('The SMS debt minimum must be a normalized whole-dollar amount.');
        }

        return self::validate($options['minimum_debt_amount']);
    }

    public static function validate(int $minimum): int
    {
        if ($minimum < self::FLOOR || $minimum > self::MAX) {
            throw new InvalidArgumentException('The SMS debt minimum must be from 25,000 to 9,999,999,000 USD.');
        }

        return $minimum;
    }

    /** Pending snapshots only: approved historical evidence remains readable unchanged. */
    public static function assertPending(ConnectionInterface $db, string $requestId): void
    {
        $run = $db->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $requestId)->first();
        $options = isset($run->Run_Options_JSON) ? SmsRunOptions::fromStored($run->Run_Options_JSON, (int) $run->Target) : null;
        $minimum = self::minimum($options);
        $rows = fn (): Builder => $db->table('TblProspectsExport as x')->useWritePdo()->where('x.Request_ID', $requestId);
        if ($rows()->where(fn (Builder $q) => $q->whereNull('x.Debt_Amount')->orWhere('x.Debt_Amount', '<', $minimum))->exists()) {
            throw new SmsFrozenEligibilityHold('A frozen SMS member is below the required debt minimum or has unknown debt. The batch was held without changing membership.');
        }
        // Tier debt is an approved fixed amount. A frozen rounded amount cannot
        // turn a low tier fallback into source debt meeting a higher minimum.
        $allowed = array_keys(array_filter(SmsCurrentDebtSql::TIER_AMOUNTS, fn (int $amount) => $amount >= $minimum));
        $tiers = $rows()->where('x.Debt_Source', 'Tier');
        if (($allowed === [] ? $tiers : $tiers->where(fn (Builder $q) => $q->whereNull('x.Debt_Tier')->orWhereNotIn('x.Debt_Tier', $allowed)))->exists()) {
            throw new SmsFrozenEligibilityHold('A frozen SMS tier fallback is below the required source-debt minimum. The batch was held without changing membership.');
        }
        // CEILING to a thousand can hide a shortfall only in this one boundary
        // bucket. Larger rounded snapshots prove the floor mathematically. Do
        // not run the global resolver or reprice/fallback any frozen member.
        $border = fn (): Builder => $rows()->whereRaw('x.Debt_Amount - 1000 < ?', [$minimum])->where(fn (Builder $q) => $q->whereNull('x.Debt_Source')->orWhere('x.Debt_Source', '<>', 'Tier'));
        if (! $border()->exists()) {
            return;
        }
        if ($border()->where(fn (Builder $q) => $q->whereNull('x.Debt_Source')->orWhereNotIn('x.Debt_Source', ['TblTUData', 'TblMailers'])
            ->orWhereNull('x.Debt_Source_PK')->orWhere('x.Debt_Source_PK', '<=', 0))->exists()) {
            self::holdSource();
        }
        $native = $db->getDriverName() === 'sqlsrv';
        foreach (['TblTUData' => 'derivedFields1_unsecdebtbalance', 'TblMailers' => 'Debt_Amount'] as $table => $debt) {
            if (! $border()->where('x.Debt_Source', $table)->exists()) {
                continue;
            }
            if ($native) {
                self::assertNativeSource($db, $requestId, $table, $debt, $minimum);

                continue;
            }
            $identity = $table === 'TblMailers'
                ? 's.Client=x.Client AND s.Address=x.Address AND substr(s.Zip,1,5)=substr(x.Zip,1,5)'
                : "s.ncoaAddr_addrLine1Txt=x.Address AND substr(s.ncoaAddr_zipCde,1,5)=substr(x.Zip,1,5)
 AND s.ncoaAddr_frstNme<>'' AND s.ncoaAddr_lastNme<>'' AND substr(x.Client,1,length(s.ncoaAddr_frstNme)+1)=s.ncoaAddr_frstNme||' '
 AND substr(x.Client,-(length(s.ncoaAddr_lastNme)+1))=' '||s.ncoaAddr_lastNme";
            $safe = $border()->where('x.Debt_Source', $table)->where(function (Builder $q) use ($table, $debt, $minimum, $identity): void {
                $q->whereExists(fn (Builder $duplicate) => $duplicate->selectRaw('1')->fromRaw($table.' s')
                    ->whereColumn('s.PK', 'x.Debt_Source_PK')->whereRaw($identity)->orderBy('s.PK')->offset(1)->limit(1))
                    ->orWhereNotExists(function (Builder $s) use ($table, $debt, $minimum, $identity): void {
                        $s->selectRaw('1')->fromRaw($table.' s')->whereColumn('s.PK', 'x.Debt_Source_PK')
                            ->where('s.'.$debt, '>=', $minimum)->where('s.'.$debt, '<=', self::MAX)->whereRaw($identity);
                    });
            });
            if ($safe->exists()) {
                self::holdSource();
            }
        }
    }

    private static function holdSource(): never
    {
        throw new SmsFrozenEligibilityHold('Frozen SMS debt cannot prove the required unrounded source-debt minimum. The batch was held without changing membership.');
    }

    /** Only the frozen boundary subset is visited, using a catalog-proven forced seek. */
    private static function assertNativeSource(ConnectionInterface $db, string $requestId, string $table, string $debt, int $minimum): void
    {
        [$columns, $index, $identitySeek] = self::sourceAccess($db, $table, $debt);
        $casts = [];
        $guards = ['x.Client IS NOT NULL', 'x.Debt_Source_PK<='.($columns['PK']->type_name === 'int' ? '2147483647' : '9223372036854775807')];
        $keys = $table === 'TblTUData'
            ? ['ncoaAddr_addrLine1Txt' => 'x.Address']
            : ['Client' => 'x.Client', 'Address' => 'x.Address'];
        foreach ($keys as $column => $outer) {
            [$casts[$column], $guard] = self::outerKey($columns[$column], $outer);
            $guards[] = $guard;
        }
        $zip = $identitySeek ? 'SMS_Zip5' : ($table === 'TblTUData' ? 'ncoaAddr_zipCde' : 'Zip');
        [$zipCast, $guard] = self::outerKey($columns[$zip], 'LEFT(x.Zip,5)');
        $guards[] = $guard;
        // Reject outer truncation/code-page loss before querying any source rows.
        if ($db->table('TblProspectsExport as x')->useWritePdo()->where('x.Request_ID', $requestId)
            ->where('x.Debt_Source', $table)->whereRaw('x.Debt_Amount-1000<?', [$minimum])
            ->whereRaw('NOT ('.implode(' AND ', $guards).')')->exists()) {
            self::holdSource();
        }
        $zipPredicate = ($identitySeek ? 's.SMS_Zip5' : 'LEFT(s.'.$zip.',5)').'='.$zipCast;
        $identity = $table === 'TblTUData'
            ? 's.ncoaAddr_addrLine1Txt='.$casts['ncoaAddr_addrLine1Txt'].' AND '.$zipPredicate.
                " AND s.ncoaAddr_frstNme<>'' AND s.ncoaAddr_lastNme<>''
 AND LEFT(x.Client,LEN(s.ncoaAddr_frstNme)+1)=s.ncoaAddr_frstNme+' '
 AND RIGHT(x.Client,LEN(s.ncoaAddr_lastNme)+1)=' '+s.ncoaAddr_lastNme"
            : 's.Client='.$casts['Client'].' AND s.Address='.$casts['Address'].' AND '.$zipPredicate;
        $seekKeys = $identitySeek
            ? ($table === 'TblTUData' ? ['ncoaAddr_addrLine1Txt', 'SMS_Zip5'] : ['Client', 'Address', 'SMS_Zip5'])
            : ['PK'];
        $hint = ' WITH (FORCESEEK(['.str_replace(']', ']]', $index).'] (['.implode('],[', $seekKeys).']))'.($db->transactionLevel() > 0 ? ',HOLDLOCK' : '').')';
        $pk = $columns['PK']->type_name;
        $max = self::MAX;
        // TOP(2) is inside the aggregate, BEFORE debt validity/minimum filters.
        // Same-PK evidence for unrelated identities is deliberately irrelevant to
        // the v1/v2 frozen composite identity; the lean global PK gate is separate.
        $sql = <<<SQL
SELECT TOP(1) 1 AS held
FROM dbo.TblProspectsExport x
OUTER APPLY (
 SELECT COUNT_BIG(*) AS versions,COUNT(v.Debt) AS known_debts,MIN(v.Debt) AS debt
 FROM (SELECT TOP(2) s.{$debt} AS Debt FROM dbo.{$table} s{$hint}
       WHERE s.PK=CONVERT({$pk},x.Debt_Source_PK) AND {$identity}) v
) proof
WHERE x.Request_ID=? AND x.Debt_Source=? AND x.Debt_Amount-1000<?
 AND (proof.versions<>1 OR proof.known_debts<>1 OR proof.debt<? OR proof.debt>{$max}
 OR CASE WHEN proof.debt BETWEEN 0 AND {$max}
     THEN CAST(CEILING(proof.debt/CAST(1000 AS decimal(12,2)))*1000 AS decimal(12,2))
     ELSE NULL END<>x.Debt_Amount)
OPTION(LOOP JOIN,FORCE ORDER,RECOMPILE)
SQL;
        if ($db->selectOne($sql, [$requestId, $table, $minimum, $minimum], false) !== null) {
            self::holdSource();
        }
    }

    /** Catalog reads only; never fall back to a source scan or a global PK audit. */
    private static function sourceAccess(ConnectionInterface $db, string $table, string $debt): array
    {
        if ($db->getTablePrefix() !== '') {
            self::holdSchema($table);
        }
        $rows = $db->select(<<<'SQL'
SELECT c.name,ty.name AS type_name,c.max_length,c.collation_name,c.is_computed,cc.definition
FROM sys.columns c JOIN sys.types ty ON ty.user_type_id=c.user_type_id
LEFT JOIN sys.computed_columns cc ON cc.object_id=c.object_id AND cc.column_id=c.column_id
WHERE c.object_id=OBJECT_ID(?)
SQL, ['dbo.'.$table], false);
        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->name] = $row;
        }
        if (! in_array($columns['PK']->type_name ?? '', ['int', 'bigint'], true)
            || ! in_array($columns[$debt]->type_name ?? '', ['decimal', 'numeric', 'money', 'smallmoney', 'int', 'bigint'], true)) {
            self::holdSchema($table);
        }
        $identity = $table === 'TblTUData'
            ? ['ncoaAddr_addrLine1Txt', 'ncoaAddr_zipCde', 'ncoaAddr_frstNme', 'ncoaAddr_lastNme']
            : ['Client', 'Address', 'Zip'];
        foreach ($identity as $column) {
            if (! self::stringColumn($columns[$column] ?? null)) {
                self::holdSchema($table);
            }
        }
        $frozen = $db->select("SELECT c.name,ty.name AS type_name,c.max_length,c.collation_name FROM sys.columns c
 JOIN sys.types ty ON ty.user_type_id=c.user_type_id WHERE c.object_id=OBJECT_ID('dbo.TblProspectsExport')
 AND c.name IN ('Client','Address','Zip','Debt_Source_PK')", [], false);
        $seen = [];
        foreach ($frozen as $column) {
            $seen[$column->name] = true;
            if ($column->name === 'Debt_Source_PK'
                ? ! in_array($column->type_name, ['int', 'bigint'], true)
                : ! self::stringColumn($column)) {
                self::holdSchema($table);
            }
        }
        if (count($seen) !== 4) {
            self::holdSchema($table);
        }
        $indexes = $db->select(<<<'SQL'
SELECT i.name,i.type,i.is_disabled,i.is_hypothetical,i.has_filter,c.name AS column_name,
 ic.key_ordinal,ic.is_descending_key
FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
WHERE i.object_id=OBJECT_ID(?) ORDER BY i.index_id,ic.key_ordinal
SQL, ['dbo.'.$table], false);
        $groups = [];
        foreach ($indexes as $row) {
            $groups[$row->name][] = $row;
        }
        $reviewed = $table === 'TblTUData' ? 'IX_TblTUData_SMS_Identity' : 'IX_TblMailers_SMS_Identity';
        $keys = $table === 'TblTUData' ? ['ncoaAddr_addrLine1Txt', 'SMS_Zip5'] : ['Client', 'Address', 'SMS_Zip5'];
        $coverage = $table === 'TblTUData'
            ? ['PK', 'Creation_Date', 'ncoaAddr_frstNme', 'ncoaAddr_lastNme', $debt, 'Tier_Tag']
            : ['PK', 'Drop_Name', $debt, 'Debt_Tier'];
        foreach ($groups as $name => $parts) {
            $first = $parts[0];
            if ((int) $first->is_disabled !== 0 || (int) $first->is_hypothetical !== 0 || (int) $first->has_filter !== 0) {
                continue;
            }
            $actual = array_values(array_filter($parts, fn ($p) => (int) $p->key_ordinal > 0));
            // A PK-leading B-tree can safely use either varchar or nvarchar
            // identity residuals; an identity-only path requires Unicode keys.
            if (in_array((int) $first->type, [1, 2], true) && ($actual[0]->column_name ?? null) === 'PK') {
                return [$columns, $name, false];
            }
        }
        $parts = $groups[$reviewed] ?? [];
        $first = $parts[0] ?? null;
        $actual = array_values(array_filter($parts, fn ($p) => (int) $p->key_ordinal > 0));
        if ($first === null || (int) $first->type !== 2 || (int) $first->is_disabled !== 0
            || (int) $first->is_hypothetical !== 0 || (int) $first->has_filter !== 0
            || array_map(fn ($p) => $p->column_name, $actual) !== $keys
            || array_filter($actual, fn ($p) => (int) $p->is_descending_key !== 0) !== []
            || array_diff($coverage, array_map(fn ($p) => $p->column_name, $parts)) !== []) {
            self::holdSchema($table);
        }
        foreach ($keys as $key) {
            if (! self::stringColumn($columns[$key] ?? null) || $columns[$key]->type_name !== 'nvarchar') {
                self::holdSchema($table);
            }
        }
        $computed = $columns['SMS_Zip5'];
        $definition = strtolower((string) preg_replace('/[\s\[\]()]+/', '', (string) $computed->definition));
        if ((int) $computed->is_computed !== 1 || (int) $computed->max_length !== 10
            || $definition !== ($table === 'TblTUData' ? 'leftncoaaddr_zipcde,5' : 'leftzip,5')) {
            self::holdSchema($table);
        }
        $settings = $db->selectOne("SELECT SESSIONPROPERTY('ANSI_NULLS') AS ansi_nulls,SESSIONPROPERTY('ANSI_PADDING') AS ansi_padding,
 SESSIONPROPERTY('ANSI_WARNINGS') AS ansi_warnings,SESSIONPROPERTY('CONCAT_NULL_YIELDS_NULL') AS concat_null,
 SESSIONPROPERTY('QUOTED_IDENTIFIER') AS quoted_identifier,SESSIONPROPERTY('NUMERIC_ROUNDABORT') AS numeric_roundabort,
 SESSIONPROPERTY('ARITHABORT') AS arithabort,compatibility_level FROM sys.databases WHERE database_id=DB_ID()", [], false);
        foreach (['ansi_nulls', 'ansi_padding', 'ansi_warnings', 'concat_null', 'quoted_identifier'] as $setting) {
            if ((int) ($settings->$setting ?? 0) !== 1) {
                self::holdSchema($table);
            }
        }
        if ((int) ($settings->numeric_roundabort ?? 1) !== 0
            || ((int) ($settings->arithabort ?? 0) !== 1 && (int) ($settings->compatibility_level ?? 0) < 90)) {
            self::holdSchema($table);
        }

        return [$columns, $reviewed, true];
    }

    private static function stringColumn(?object $column): bool
    {
        return $column !== null && in_array($column->type_name, ['varchar', 'nvarchar'], true)
            && (int) $column->max_length > 0 && (int) $column->max_length <= 8000
            && $column->collation_name === 'SQL_Latin1_General_CP1_CI_AS';
    }

    /** Cast only OUTER values, with exact byte/Unicode roundtrip protection. */
    private static function outerKey(object $column, string $outer): array
    {
        $type = $column->type_name;
        $bytes = (int) $column->max_length;
        $width = $type === 'nvarchar' ? intdiv($bytes, 2) : $bytes;
        $unicode = 'CONVERT(nvarchar(max),'.$outer.') COLLATE SQL_Latin1_General_CP1_CI_AS';
        $wide = 'CONVERT('.$type.'(max),'.$unicode.')';
        $roundtrip = 'CONVERT(nvarchar(max),'.$wide.')';
        $cast = 'CONVERT('.$type.'('.$width.'),'.$unicode.')';
        $guard = $outer.' IS NOT NULL AND DATALENGTH('.$wide.')<='.$bytes.
            ' AND DATALENGTH('.$roundtrip.')=DATALENGTH('.$unicode.')'.
            ' AND CONVERT(varbinary(max),'.$roundtrip.')=CONVERT(varbinary(max),'.$unicode.')';

        return [$cast, '('.$guard.')'];
    }

    private static function holdSchema(string $table): never
    {
        throw new SmsFrozenEligibilityHold('Frozen SMS boundary debt requires a reviewed seekable '.$table.' source schema. The batch remains held; no source scan was attempted.');
    }
}
