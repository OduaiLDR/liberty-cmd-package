<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Explicit generation compatibility; response fields never alias original-event attribution. */
final class SmsProspectSchema
{
    public static function responseEnabled(): bool
    {
        return config('sms-exports.response_schema_enabled', false) === true;
    }

    public static function recordColumns(): array
    {
        return self::responseEnabled()
            ? ['Last_SMS_Date', 'Contacted', 'Response_Date', 'Response_CID', 'Enrollment_Date', 'Enrollment_CID']
            : ['Last_SMS_Date', 'Contacted_Date'];
    }

    public static function assertResponseReady(ConnectionInterface $db): void
    {
        if (! self::responseEnabled()) return;
        if ($db->getDriverName() !== 'sqlsrv') self::hold();
        $columns = $db->select("SELECT c.name,t.name AS type_name,c.max_length,c.is_nullable,d.definition AS default_definition
            FROM sys.columns c JOIN sys.types t ON c.user_type_id=t.user_type_id
            LEFT JOIN sys.default_constraints d ON d.object_id=c.default_object_id
            WHERE c.object_id=OBJECT_ID(N'dbo.TblProspects')
              AND c.name IN(N'Last_SMS_Date',N'Contacted',N'Opt_Out',N'Invalid_Phone',N'Response_Date',N'Response_CID',N'Enrollment_Date',N'Enrollment_CID')");
        $found=[];
        foreach($columns as $column) $found[$column->name]=$column;
        foreach(['Last_SMS_Date','Response_Date','Enrollment_Date'] as $name) {
            $column=$found[$name]??null;
            if(!$column || $column->type_name!=='date' || (int)$column->max_length!==3
                || !(bool)$column->is_nullable || $column->default_definition!==null) self::hold();
        }
        foreach(['Response_CID','Enrollment_CID'] as $name) {
            $column=$found[$name]??null;
            if(!$column || $column->type_name!=='nvarchar' || (int)$column->max_length!==100
                || !(bool)$column->is_nullable || $column->default_definition!==null) self::hold();
        }
        foreach(['Contacted','Opt_Out','Invalid_Phone'] as $name) {
            $column=$found[$name]??null;
            if(!$column || (bool)$column->is_nullable
                || !(($column->type_name==='bit' && (int)$column->max_length===1)
                    || ($column->type_name==='int' && (int)$column->max_length===4))) self::hold();
        }
    }

    private static function hold(): never
    {
        throw new HttpException(503, 'The reviewed SMS response and enrollment schema is not ready.');
    }
}
