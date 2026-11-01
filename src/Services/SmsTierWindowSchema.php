<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Fail-closed physical prerequisites for the bounded source resolver. */
final class SmsTierWindowSchema
{
    private const INDEX_TABLES = [
        'IX_TblProspects_SMS_Never_Priority' => 'TblProspects',
        'IX_TblProspects_SMS_Previous_Priority' => 'TblProspects',
        'IX_TblTUData_SMS_Identity' => 'TblTUData',
        'IX_TblMailers_SMS_Identity' => 'TblMailers',
        'IX_TblMarketing_SMS_Drop_SendDate' => 'TblMarketing',
    ];

    private const INDEX_KEYS = [
        'IX_TblProspects_SMS_Never_Priority' => ['Creation_Date:1:1', 'PK:2:0'],
        'IX_TblProspects_SMS_Previous_Priority' => ['Last_SMS_Date:1:0', 'PK:2:0'],
        'IX_TblTUData_SMS_Identity' => ['ncoaAddr_addrLine1Txt:1:0', 'SMS_Zip5:2:0'],
        'IX_TblMailers_SMS_Identity' => ['Client:1:0', 'Address:2:0', 'SMS_Zip5:3:0'],
        'IX_TblMarketing_SMS_Drop_SendDate' => ['Drop_Name:1:0', 'Send_Date:2:0'],
    ];

    private const INDEX_COVERAGE = [
        'IX_TblProspects_SMS_Never_Priority' => ['Phone','Client','Address','City','State','Zip','Email','Last_SMS_Date'],
        'IX_TblProspects_SMS_Previous_Priority' => ['Phone','Client','Address','City','State','Zip','Email','Creation_Date'],
        'IX_TblTUData_SMS_Identity' => ['PK','Creation_Date','ncoaAddr_frstNme','ncoaAddr_lastNme','derivedFields1_unsecdebtbalance','Tier_Tag'],
        'IX_TblMailers_SMS_Identity' => ['PK','Drop_Name','Debt_Amount','Debt_Tier'],
        'IX_TblMarketing_SMS_Drop_SendDate' => [],
    ];

    public static function ready(ConnectionInterface $db): bool
    {
        try {
            self::assertReady($db);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function assertReady(ConnectionInterface $db): void
    {
        if ($db->getDriverName() !== 'sqlsrv' || $db->getTablePrefix() !== '') {
            throw new RuntimeException('Windowed SMS selection requires unprefixed SQL Server source tables.');
        }
        $settings = $db->selectOne("SELECT SESSIONPROPERTY('ANSI_NULLS') AS ansi_nulls,
            SESSIONPROPERTY('ANSI_PADDING') AS ansi_padding,SESSIONPROPERTY('ANSI_WARNINGS') AS ansi_warnings,
            SESSIONPROPERTY('CONCAT_NULL_YIELDS_NULL') AS concat_null,SESSIONPROPERTY('QUOTED_IDENTIFIER') AS quoted_identifier,
            SESSIONPROPERTY('NUMERIC_ROUNDABORT') AS numeric_roundabort,SESSIONPROPERTY('ARITHABORT') AS arithabort,
            compatibility_level FROM sys.databases WHERE database_id=DB_ID()");
        foreach (['ansi_nulls','ansi_padding','ansi_warnings','concat_null','quoted_identifier'] as $setting) {
            if ((int) ($settings->$setting ?? 0) !== 1) throw new RuntimeException('Windowed SMS selection requires compatible session SET options.');
        }
        // At compatibility >=90, ANSI_WARNINGS ON makes ARITHABORT implicitly ON.
        if ((int) ($settings->numeric_roundabort ?? 1) !== 0
            || ((int) ($settings->arithabort ?? 0) !== 1 && (int) ($settings->compatibility_level ?? 0) < 90)) {
            throw new RuntimeException('Windowed SMS selection requires compatible arithmetic SET options.');
        }
        $columns = $db->select("SELECT t.name AS table_name,c.name AS column_name,c.is_computed,cc.definition
            FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id
            JOIN sys.columns c ON c.object_id=t.object_id
            LEFT JOIN sys.computed_columns cc ON cc.object_id=c.object_id AND cc.column_id=c.column_id
            WHERE s.name='dbo' AND ((t.name='TblTUData' AND c.name='SMS_Zip5')
                OR (t.name='TblMailers' AND c.name='SMS_Zip5'))");
        $computed = [];
        foreach ($columns as $column) {
            $definition = self::canonical((string) $column->definition);
            $computed[$column->table_name] = (int) $column->is_computed === 1
                && $definition === ($column->table_name === 'TblTUData' ? 'leftncoaaddr_zipcde,5' : 'leftzip,5');
        }
        if (($computed['TblTUData'] ?? false) !== true || ($computed['TblMailers'] ?? false) !== true) {
            throw new RuntimeException('Windowed SMS selection requires the reviewed TU and mailer SMS_Zip5 expressions.');
        }

        $rows = $db->select("SELECT t.name AS table_name,i.name AS index_name,i.filter_definition,
                i.type,i.is_disabled,i.is_hypothetical,c.name AS column_name,ic.key_ordinal,ic.is_descending_key
            FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id
            JOIN sys.indexes i ON i.object_id=t.object_id AND i.name IN ('".implode("','", array_keys(self::INDEX_KEYS))."')
            JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
            JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
            WHERE s.name='dbo' ORDER BY i.name,ic.key_ordinal");
        $actual = [];
        $filters = [];
        $usable = [];
        $coverage = [];
        foreach ($rows as $row) {
            if ((int) $row->key_ordinal > 0) $actual[$row->index_name][] = $row->column_name.':'.(int) $row->key_ordinal.':'.(int) $row->is_descending_key;
            $coverage[$row->index_name][] = $row->column_name;
            $filters[$row->index_name] = $row->filter_definition;
            $usable[$row->index_name] = $row->table_name === self::INDEX_TABLES[$row->index_name]
                && (int) $row->type === 2 && (int) $row->is_disabled === 0 && (int) $row->is_hypothetical === 0;
        }
        foreach (self::INDEX_KEYS as $index => $keys) {
            if (($usable[$index] ?? false) !== true || ($actual[$index] ?? null) !== $keys) {
                throw new RuntimeException('Windowed SMS selection index '.$index.' is missing or has different keys.');
            }
            if (array_diff(self::INDEX_COVERAGE[$index], $coverage[$index] ?? []) !== []) {
                throw new RuntimeException('Windowed SMS selection index '.$index.' lacks reviewed coverage.');
            }
        }
        foreach (['IX_TblProspects_SMS_Never_Priority', 'IX_TblProspects_SMS_Previous_Priority'] as $index) {
            $actualFilter = explode('and', self::canonical((string) ($filters[$index] ?? '')));
            $expectedFilter = ['contacted=0','opt_out=0','invalid_phone=0','phoneisnotnull',
                $index === 'IX_TblProspects_SMS_Never_Priority' ? 'last_sms_dateisnull' : 'last_sms_dateisnotnull'];
            sort($actualFilter); sort($expectedFilter);
            if ($actualFilter !== $expectedFilter) throw new RuntimeException('Windowed SMS prospect priority filter differs from the reviewed predicate.');
        }
        foreach (['IX_TblTUData_SMS_Identity','IX_TblMailers_SMS_Identity','IX_TblMarketing_SMS_Drop_SendDate'] as $index) {
            if ($filters[$index] !== null) throw new RuntimeException('Windowed SMS source indexes must retain all source evidence.');
        }
    }

    private static function canonical(string $sql): string
    {
        return strtolower((string) preg_replace('/[\s\[\]()]+/', '', $sql));
    }
}
