<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Support;

use Cmd\Reports\Services\DBConnector;

/**
 * A DBConnector whose querySqlServer() answers from a list of (regex => result) rules and records
 * every statement it was asked to run, so a test can assert both what was queried and with what.
 */
final class RecordingSqlServerConnector extends DBConnector
{
    /** @var list<array{sql: string, params: array<int, mixed>}> */
    public array $calls = [];

    /**
     * @param array<string, array<string, mixed>|callable> $rules regex => querySqlServer() result, or a
     *        callable(string $sql, array $params): array producing one. First match wins.
     */
    public function __construct(private array $rules = [])
    {
    }

    public function querySqlServer(string $sql, array $params = []): array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        foreach ($this->rules as $pattern => $result) {
            if (preg_match($pattern, $sql)) {
                return is_callable($result) ? $result($sql, $params) : $result;
            }
        }

        return ['success' => true, 'data' => [], 'row_count' => 0];
    }

    /** @return list<array{sql: string, params: array<int, mixed>}> */
    public function callsMatching(string $pattern): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => preg_match($pattern, $call['sql']) === 1));
    }
}
