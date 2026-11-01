<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Opt-in, flag-only Unicode seek. Schema installation is a separate operator change. */
final class SmsContactFlagSeek
{
    public const INDEX = 'IX_TblProspects_SMS_ContactPhone';

    // INDEX is legal on the UPDATE target; FORCESEEK is not. Contact-first loop order
    // permits keyed Unicode seeks without a second self-join to the prospect PK.
    // SQL Server owns DML locking and Halloween protection for the direct update.
    public const SQL = <<<'SQL'
WITH Contacts AS (
    SELECT DISTINCT CASE WHEN LEN(Phone) = 11 AND LEFT(Phone, 1) = '1'
        THEN SUBSTRING(Phone, 2, 10) ELSE Phone END AS Phone
    FROM dbo.TblPhoneNumbers
    WHERE Phone IS NOT NULL
)
UPDATE p SET Contacted = 1
FROM Contacts c
INNER JOIN dbo.TblProspects p WITH (INDEX(IX_TblProspects_SMS_ContactPhone))
    ON p.Contacted = 0 AND p.SMS_ContactPhone = c.Phone
WHERE p.Phone IS NOT NULL
OPTION (LOOP JOIN, FORCE ORDER)
SQL;

    public static function sql(bool $widePhones = false): string
    {
        return $widePhones ? str_replace('FROM dbo.TblPhoneNumbers',
            'FROM '.SmsPhoneNumbersSql::from('contact_numbers', '', true), self::SQL) : self::SQL;
    }

    public static function assertReady(ConnectionInterface $db): void
    {
        if ($db->getDriverName() !== 'sqlsrv' || $db->getTablePrefix() !== '') {
            throw new RuntimeException('Contact flag seeks require unprefixed SQL Server tables.');
        }
        $settings = $db->selectOne("SELECT SESSIONPROPERTY('ANSI_NULLS') AS ansi_nulls,
            SESSIONPROPERTY('ANSI_PADDING') AS ansi_padding,SESSIONPROPERTY('ANSI_WARNINGS') AS ansi_warnings,
            SESSIONPROPERTY('CONCAT_NULL_YIELDS_NULL') AS concat_null,SESSIONPROPERTY('QUOTED_IDENTIFIER') AS quoted_identifier,
            SESSIONPROPERTY('NUMERIC_ROUNDABORT') AS numeric_roundabort,SESSIONPROPERTY('ARITHABORT') AS arithabort,
            compatibility_level FROM sys.databases WHERE database_id=DB_ID()", [], false);
        foreach (['ansi_nulls', 'ansi_padding', 'ansi_warnings', 'concat_null', 'quoted_identifier'] as $setting) {
            if ((int) ($settings->$setting ?? 0) !== 1) {
                throw new RuntimeException('Contact flag seeks require compatible session SET options.');
            }
        }
        if ((int) ($settings->numeric_roundabort ?? 1) !== 0
            || ((int) ($settings->arithabort ?? 0) !== 1 && (int) ($settings->compatibility_level ?? 0) < 90)) {
            throw new RuntimeException('Contact flag seeks require compatible arithmetic SET options.');
        }
        $columns = $db->select("SELECT OBJECT_NAME(c.object_id) AS table_name,c.name,c.system_type_id,c.max_length,
                c.collation_name,c.is_computed,cc.definition
            FROM sys.columns c LEFT JOIN sys.computed_columns cc
                ON cc.object_id=c.object_id AND cc.column_id=c.column_id
            WHERE (c.object_id=OBJECT_ID('dbo.TblProspects') AND c.name IN ('Phone','SMS_ContactPhone'))
                OR (c.object_id=OBJECT_ID('dbo.TblPhoneNumbers') AND c.name='Phone')", [], false);
        $found = [];
        foreach ($columns as $column) {
            $found[$column->table_name.'.'.$column->name] = $column;
        }
        $phone = $found['TblProspects.Phone'] ?? null;
        $key = $found['TblProspects.SMS_ContactPhone'] ?? null;
        $contact = $found['TblPhoneNumbers.Phone'] ?? null;
        $definition = strtolower((string) preg_replace('/[\s\[\]()]+/', '', (string) ($key->definition ?? '')));
        if ((int) ($phone->system_type_id ?? 0) !== 167 || (int) ($phone->max_length ?? 0) !== 10
            || (int) ($key->system_type_id ?? 0) !== 231 || (int) ($key->max_length ?? 0) !== 20
            || (int) ($key->is_computed ?? 0) !== 1 || $definition !== 'convertnvarchar10,phone'
            || (int) ($contact->system_type_id ?? 0) !== 231
            || empty($phone->collation_name) || $phone->collation_name !== ($key->collation_name ?? null)
            || $phone->collation_name !== ($contact->collation_name ?? null)) {
            throw new RuntimeException('Contact flag seeks require the reviewed lossless Unicode phone expression and matching collations.');
        }
        $rows = $db->select("SELECT i.name,i.type,i.is_disabled,i.is_hypothetical,i.is_unique,i.is_primary_key,i.filter_definition,
                c.name AS column_name,ic.key_ordinal,ic.is_descending_key
            FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
            JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
            WHERE i.object_id=OBJECT_ID('dbo.TblProspects') AND (i.name='IX_TblProspects_SMS_ContactPhone' OR i.type=1)
            ORDER BY i.name,ic.key_ordinal", [], false);
        $keys = [];
        $coverage = [];
        $usable = false;
        $clustered = [];
        $uniqueClustered = false;
        foreach ($rows as $row) {
            if ((int) $row->type === 1) {
                if ((int) $row->key_ordinal > 0) {
                    $clustered[] = $row->column_name;
                }
                $uniqueClustered = (int) $row->is_unique === 1 && (int) $row->is_primary_key === 1
                    && (int) $row->is_disabled === 0 && (int) $row->is_hypothetical === 0;
            }
            if ($row->name !== self::INDEX) {
                continue;
            }
            if ((int) $row->key_ordinal > 0) {
                $keys[] = $row->column_name.':'.(int) $row->is_descending_key;
            }
            $coverage[] = $row->column_name;
            $usable = (int) $row->type === 2 && (int) $row->is_disabled === 0 && (int) $row->is_hypothetical === 0
                && $row->filter_definition === null;
        }
        if (! $usable || $keys !== ['Contacted:0', 'SMS_ContactPhone:0'] || ! in_array('Phone', $coverage, true)
            || ! $uniqueClustered || $clustered !== ['PK']) {
            throw new RuntimeException('Contact flag seeks require the reviewed covering index and unique clustered prospect PK.');
        }
    }
}
