<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Invoices\InvoiceObjectStore;
use Cmd\Reports\Invoices\InvoiceStoreTransientException;
use Fiber;

/** In-memory S3 with atomic create-if-absent. Inside a Fiber it yields before each put so racers interleave. */
final class FakeInvoiceStore implements InvoiceObjectStore
{
    /** @var array<string, string> */
    public array $objects = [];
    public int $puts = 0;
    /** @var list<string> keys whose next put fails transiently, one entry per failure */
    public array $transientFailures = [];
    public bool $failAlways = false;

    public bool $listDenied = false;
    public int $lists = 0;

    public function listKeys(string $prefix = ''): array
    {
        $this->lists++;
        if ($this->listDenied) {
            throw new \Cmd\Reports\Invoices\InvoiceRegistryException('S3 could not list the invoice bucket: AccessDenied');
        }
        $keys = array_values(array_filter(array_keys($this->objects), static fn (string $k) => $prefix === '' || str_starts_with($k, $prefix)));
        sort($keys);

        return $keys;
    }

    public function get(string $key): ?string
    {
        return $this->objects[$key] ?? null;
    }

    public function putIfAbsent(string $key, string $body, string $contentType): bool
    {
        if (Fiber::getCurrent() !== null) {
            Fiber::suspend();
        }
        $this->puts++;
        if ($this->failAlways) {
            throw new \RuntimeException('S3 unavailable');
        }
        if (($i = array_search($key, $this->transientFailures, true)) !== false) {
            unset($this->transientFailures[$i]);
            throw new InvoiceStoreTransientException('409 ConditionalRequestConflict');
        }
        if (isset($this->objects[$key])) {
            return false;
        }
        $this->objects[$key] = $body;

        return true;
    }
}
