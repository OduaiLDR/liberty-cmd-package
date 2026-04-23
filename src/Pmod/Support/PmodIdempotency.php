<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

final class PmodIdempotency
{
    /**
     * @param array<string, mixed> $normalizedPayload
     */
    public static function fromNormalizedPayload(array $normalizedPayload): string
    {
        $normalized = self::normalizeValue($normalizedPayload);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_values(array_map([self::class, 'normalizeValue'], array_filter(
                $value,
                static fn (mixed $item): bool => $item !== null && $item !== '',
            )));
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if ($item === null || $item === '') {
                continue;
            }

            $normalized[$key] = self::normalizeValue($item);
        }

        ksort($normalized);

        return $normalized;
    }
}
