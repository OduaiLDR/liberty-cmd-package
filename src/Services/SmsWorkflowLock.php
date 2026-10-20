<?php

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\DB;

/** SQL Server application locks also protect an empty queue and survive multiple app hosts. */
final class SmsWorkflowLock
{
    public static function run(string $resource, callable $work): mixed
    {
        $db = DB::connection('sqlsrv');
        if ($db->getDriverName() !== 'sqlsrv') return $work();
        $name = 'sms:'.$resource;
        $lock = $db->selectOne("DECLARE @result int; EXEC @result = sys.sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 0; SELECT @result AS Result", [$name]);
        if ((int) $lock->Result < 0) throw new SmsWorkflowBusyException;
        try {
            return $work();
        } finally {
            $db->statement("EXEC sys.sp_releaseapplock @Resource = ?, @LockOwner = 'Session'", [$name]);
        }
    }
}
