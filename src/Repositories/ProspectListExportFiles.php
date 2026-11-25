<?php

declare(strict_types=1);

namespace Cmd\Reports\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ProspectListExportFiles
{
    public static function reserve(string $key, int $part, ?string $mailerId): string
    {
        // The export job holds a shared per-run lock while reserving file IDs.
        return self::find($key, $part) ?? (string) DB::table('prospect_list_export_files')->insertGetId([
            'export_key' => $key,
            'part_number' => $part,
            'mailer_id' => $mailerId,
            'created_at' => now(),
        ]);
    }

    public static function find(string $key, int $part): ?string
    {
        $id = self::part($key, $part)->value('id');

        return $id === null ? null : (string) $id;
    }

    public static function recordRows(string $key, int $part, int $count): void
    {
        self::part($key, $part)->update(['row_count' => $count]);
    }

    private static function part(string $key, int $part): Builder
    {
        return DB::table('prospect_list_export_files')->where(['export_key' => $key, 'part_number' => $part]);
    }
}
