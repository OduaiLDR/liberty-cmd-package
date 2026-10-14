<?php

namespace Cmd\Reports\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SmsDropPlanner
{
    /** @param Collection<int, object> $drops */
    public function select(Collection $drops, int $target): Collection
    {
        if ($target < 1) {
            throw ValidationException::withMessages(['target' => 'Enter a positive SMS target.']);
        }

        $ordered = $drops->filter(fn (object $drop): bool => (int) $drop->Amount_Dropped > 0)
            ->sort(function (object $left, object $right): int {
                $uses = (int) $left->SMS_Drops <=> (int) $right->SMS_Drops;
                if ($uses !== 0) {
                    return $uses;
                }
                $leftDate = $left->SMS_Last_Export_Date ?: $left->Send_Date;
                $rightDate = $right->SMS_Last_Export_Date ?: $right->Send_Date;
                $date = (int) $left->SMS_Drops === 0
                    ? strcmp((string) $rightDate, (string) $leftDate)
                    : strcmp((string) $leftDate, (string) $rightDate);

                return $date ?: strcmp((string) $right->Send_Date, (string) $left->Send_Date) ?: strcmp((string) $left->Drop_Name, (string) $right->Drop_Name);
            });

        $selected = collect();
        $total = 0;
        foreach ($ordered as $drop) {
            $selected->push($drop);
            $total += (int) $drop->Amount_Dropped;
            if ($total >= $target) {
                break;
            }
        }

        return $selected;
    }

    /** @param array<int|string, int> $counts @return array<int|string, string> */
    public function allocate(string $amount, array $counts): array
    {
        if (! preg_match('/^\\d{1,7}(?:\\.\\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['cost' => 'Enter a cost with at most two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        $total = array_sum($counts);
        if ($total <= 0 || min($counts) < 0) {
            throw ValidationException::withMessages(['cost' => 'The selected drops must have a positive total count.']);
        }

        $shares = $remainders = [];
        foreach ($counts as $id => $count) {
            $product = $cents * $count;
            $shares[$id] = intdiv($product, $total);
            $remainders[$id] = $product % $total;
        }
        arsort($remainders, SORT_NUMERIC);
        $remaining = $cents - array_sum($shares);
        foreach (array_keys($remainders) as $id) {
            if ($remaining-- <= 0) {
                break;
            }
            $shares[$id]++;
        }

        return array_map(
            fn (int $share): string => intdiv($share, 100).'.'.str_pad((string) ($share % 100), 2, '0', STR_PAD_LEFT),
            $shares,
        );
    }
}
