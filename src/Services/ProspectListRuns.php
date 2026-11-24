<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class ProspectListRuns
{
    public const RETENTION_SECONDS = 86_400;

    public static function store(): Repository
    {
        return Cache::store('database');
    }

    public static function key(string $tenant, string $actor, string $id): string
    {
        return 'prospect-list:'.hash('sha256', "$tenant:$actor:".strtolower($id));
    }

    public static function artifact(string $key, ?int $part = null): string
    {
        $directory = self::artifactDirectory($key);

        return $part === null ? $directory.'.csv' : "$directory/part-$part.csv";
    }

    public static function artifactDirectory(string $key): string
    {
        return 'prospect-lists/'.substr($key, strlen('prospect-list:'));
    }

    public static function fingerprint(array $filters): string
    {
        ksort($filters);
        if (isset($filters['states'])) {
            sort($filters['states']);
        }
        if (isset($filters['fields'])) {
            ksort($filters['fields']);
            foreach ($filters['fields'] as &$field) {
                ksort($field);
            }
            unset($field);
        }

        return hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
    }
}
