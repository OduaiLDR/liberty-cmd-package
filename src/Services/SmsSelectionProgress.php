<?php

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** Actor-scoped local status snapshots avoid SQL reads on every browser poll. */
class SmsSelectionProgress
{
    public function write(string $id, string $actor, array $result): void
    {
        try {
            Cache::store('file')->put($this->key($id), ['actor' => strtolower($actor), 'result' => $result], 86400);
        } catch (Throwable) {
            // SQL is authoritative; cache failure must not fail a selection.
        }
    }

    public function read(string $id, string $actor): ?array
    {
        try {
            $snapshot = Cache::store('file')->get($this->key($id));
            if (! is_array($snapshot) || ($snapshot['actor'] ?? '') !== strtolower($actor)
                || ! is_array($snapshot['result'] ?? null)
                || strcasecmp((string) ($snapshot['result']['request_id'] ?? ''), $id) !== 0) return null;

            return $snapshot['result'];
        } catch (Throwable) {
            return null;
        }
    }

    public function forget(string $id): void
    {
        try { Cache::store('file')->forget($this->key($id)); } catch (Throwable) {}
    }

    private function key(string $id): string
    {
        return 'cmd-sms-selection:'.strtolower($id);
    }
}
