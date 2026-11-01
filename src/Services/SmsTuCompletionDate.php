<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/** Read only the TU pipeline's immutable successful-completion checkpoint. */
final class SmsTuCompletionDate
{
    public static function enabled(): bool
    {
        return (bool) config('sms-exports.tu_completion_date_enabled', false);
    }

    /** The caller owns the SAME consistent source snapshot as the eligibility count. */
    public function latest(ConnectionInterface $db): ?string
    {
        if (! config('sms-exports.durable_availability_enabled', false) || $db->transactionLevel() < 1) {
            throw new LogicException('TU completion dates require the durable SMS count snapshot.');
        }
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true) || $db->getTablePrefix() !== '') {
            throw new LogicException('TU completion dates require the reviewed unprefixed SQL schema.');
        }
        $prefix = $db->getDriverName() === 'sqlsrv' ? 'dbo.' : '';
        $batches = $prefix.'TblTUPipelineBatches';
        $phases = $prefix.'TblTUPipelinePhases';
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasColumns($batches, ['Batch_ID', 'Data_Date'])
            || ! $schema->hasColumns($phases, ['Batch_ID', 'Phase'])) {
            throw new RuntimeException('The reviewed TU completion schema is unavailable. Keep the last SMS summary.');
        }

        // completeSummary writes this phase only after archive, all processing and
        // returned-record counters reconcile. Later operational Status changes do
        // not revoke this immutable completion proof. This is small control metadata,
        // not a count of the purchased TU/prospect records.
        $row = $db->table($batches.' as b')->useWritePdo()
            ->join($phases.' as p', 'p.Batch_ID', '=', 'b.Batch_ID')
            ->where('p.Phase', 'summary-refreshed')
            ->selectRaw('MAX(b.Data_Date) AS latest_data_date, COUNT(*) AS completed_batches, COUNT(b.Data_Date) AS dated_batches')
            ->first();
        if ((int) $row->completed_batches !== (int) $row->dated_batches) {
            throw new RuntimeException('A completed TU batch has no verified data date.');
        }

        return self::validate($row->latest_data_date);
    }

    public static function validate(mixed $value): ?string
    {
        if ($value === null) return null;
        if (! is_string($value) || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)
            || substr($value, 0, 4) === '0000') {
            throw new InvalidArgumentException('A completed TU data date must be a valid YYYY-MM-DD date or null.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('A completed TU data date must be a valid YYYY-MM-DD date or null.');
        }
        return $value;
    }
}
