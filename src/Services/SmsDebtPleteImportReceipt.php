<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use InvalidArgumentException;

/** Strict aggregate-only receiver receipts. No remote payload, paths or row data are retained. */
final class SmsDebtPleteImportReceipt
{
    private const PART_STATES = ['awaiting_upload', 'uploading', 'uploaded', 'validating', 'validated', 'importing', 'completed', 'held'];
    private const RUN_STATES = ['queued' => 'queued', 'importing' => 'importing', 'completed' => 'completed', 'held' => 'failed'];

    public static function validate(array $receipt, SmsDebtPleteImportPlan $plan): array
    {
        self::keys($receipt, ['request_id', 'plan_sha256', 'state', 'status', 'processed', 'total', 'parts', 'error_code'],
            ['updated', 'already_completed', 'retry_pending', 'processing_queue']);
        self::require($receipt['request_id'] === $plan->requestId() && $receipt['plan_sha256'] === $plan->sha256()
            && is_string($receipt['state']) && array_key_exists($receipt['state'], self::RUN_STATES)
            && $receipt['status'] === self::RUN_STATES[$receipt['state']]
            && $receipt['total'] === $plan->rows() && self::count($receipt['processed'], $plan->rows())
            && is_array($receipt['parts']) && array_is_list($receipt['parts']) && count($receipt['parts']) === count($plan->parts())
            && in_array($receipt['error_code'], [null, 'source_unconfirmed', 'csv_invalid', 'receipt_invalid'], true)
            && ($receipt['state'] === 'held' || $receipt['error_code'] === null));
        $parts = []; $committed = 0; $held = false;
        foreach ($receipt['parts'] as $index => $part) {
            self::require(is_array($part));
            self::keys($part, ['part', 'state', 'rows_expected', 'rows_committed', 'accepted', 'bytes', 'sha256']);
            $expected = $plan->parts()[$index];
            self::require($part['part'] === $expected['part'] && $part['rows_expected'] === $expected['rows']
                && $part['bytes'] === $expected['bytes'] && $part['sha256'] === $expected['sha256']
                && in_array($part['state'], self::PART_STATES, true) && is_bool($part['accepted'])
                && self::count($part['rows_committed'], $expected['rows']));
            if (in_array($part['state'], ['awaiting_upload', 'uploading'], true)) { self::require(!$part['accepted']); }
            elseif ($part['state'] !== 'held') { self::require($part['accepted']); }
            if (!in_array($part['state'], ['importing', 'completed', 'held'], true)) { self::require($part['rows_committed'] === 0); }
            if ($part['rows_committed'] > 0) { self::require($part['accepted']); }
            if ($part['state'] === 'completed') { self::require($part['rows_committed'] === $expected['rows']); }
            if ($receipt['state'] === 'queued') {
                self::require(!in_array($part['state'], ['importing', 'completed', 'held'], true) && $part['rows_committed'] === 0);
            } elseif ($receipt['state'] === 'importing') {
                self::require(in_array($part['state'], ['validated', 'importing', 'completed'], true));
            } elseif ($receipt['state'] === 'completed') { self::require($part['state'] === 'completed'); }
            $held = $held || $part['state'] === 'held';
            $committed += $part['rows_committed'];
            $parts[] = ['part' => $part['part'], 'state' => $part['state'], 'rows_expected' => $part['rows_expected'],
                'rows_committed' => $part['rows_committed'], 'accepted' => $part['accepted'], 'bytes' => $part['bytes'], 'sha256' => $part['sha256']];
        }
        self::require($committed === $receipt['processed'] && ($receipt['state'] !== 'held' || $held)
            && ($receipt['state'] !== 'completed' || $committed === $plan->rows()));
        $safe = ['request_id' => $receipt['request_id'], 'plan_sha256' => $receipt['plan_sha256'],
            'state' => $receipt['state'], 'status' => $receipt['status'], 'processed' => $receipt['processed'],
            'total' => $receipt['total'], 'parts' => $parts, 'error_code' => $receipt['error_code']];
        foreach (['updated', 'already_completed', 'retry_pending', 'processing_queue'] as $key) {
            if (!array_key_exists($key, $receipt)) { continue; }
            $value = $receipt[$key];
            if ($key === 'updated') { self::require(self::count($value, $receipt['processed'])); }
            elseif ($key === 'processing_queue') { self::require($value === 'pending_recovery'); }
            else { self::require(is_bool($value)); }
            if ($key === 'already_completed' && $value) { self::require($receipt['state'] === 'completed' && ($receipt['updated'] ?? 0) === 0); }
            $safe[$key] = $value;
        }

        return $safe;
    }

    /** Compare only validated receipts; optional replay/queue acknowledgement metadata may differ. */
    public static function assertNotRegressed(array $previous, array $current): void
    {
        foreach (['request_id', 'plan_sha256', 'total'] as $key) { self::require(isset($previous[$key], $current[$key]) && $previous[$key] === $current[$key]); }
        self::require(is_int($previous['processed'] ?? null) && is_int($current['processed'] ?? null)
            && $current['processed'] >= $previous['processed']
            && is_array($previous['parts'] ?? null) && is_array($current['parts'] ?? null)
            && count($previous['parts']) === count($current['parts'])
            && array_key_exists($previous['state'] ?? '', self::RUN_STATES) && array_key_exists($current['state'] ?? '', self::RUN_STATES));
        $order = ['queued' => 0, 'importing' => 1, 'completed' => 2, 'held' => 3];
        self::require($order[$current['state']] >= $order[$previous['state']]
            && (!in_array($previous['state'], ['held', 'completed'], true) || $current['state'] === $previous['state']));
        foreach ($previous['parts'] as $index => $before) {
            $after = $current['parts'][$index] ?? null;
            self::require(is_array($before) && is_array($after));
            foreach (['part', 'rows_expected', 'bytes', 'sha256'] as $key) { self::require(isset($before[$key], $after[$key]) && $before[$key] === $after[$key]); }
            self::require(is_int($before['rows_committed'] ?? null) && is_int($after['rows_committed'] ?? null)
                && $after['rows_committed'] >= $before['rows_committed'] && is_bool($before['accepted'] ?? null) && is_bool($after['accepted'] ?? null)
                && (!$before['accepted'] || $after['accepted'])
                && in_array($before['state'] ?? null, self::PART_STATES, true) && in_array($after['state'] ?? null, self::PART_STATES, true)
                && array_search($after['state'], self::PART_STATES, true) >= array_search($before['state'], self::PART_STATES, true));
        }
        if (in_array($previous['state'], ['completed', 'held'], true)) {
            foreach (['state', 'status', 'processed', 'parts', 'error_code'] as $key) { self::require(($previous[$key] ?? null) === ($current[$key] ?? null)); }
        }
    }

    private static function count(mixed $value, int $maximum): bool { return is_int($value) && $value >= 0 && $value <= $maximum; }
    private static function keys(array $value, array $required, array $optional = []): void
    {
        self::require(array_diff($required, array_keys($value)) === [] && array_diff(array_keys($value), [...$required, ...$optional]) === []);
    }
    private static function require(bool $condition): void
    {
        if (!$condition) { throw new InvalidArgumentException('The SMS import receipt could not be verified.'); }
    }
}
