<?php

namespace Cmd\Reports\Services;

/** Compare address components; directional omission is a separate guarded fallback. */
final class ContactSyncAddress
{
    // Standard suffixes and observed CRM aliases, applied only at the street's end.
    private const SUFFIXES = ['STREET' => 'ST', 'ST' => 'ST', 'ROAD' => 'RD', 'RD' => 'RD',
        'AVENUE' => 'AVE', 'AV' => 'AVE', 'AVE' => 'AVE', 'DRIVE' => 'DR', 'DR' => 'DR',
        'BOULEVARD' => 'BLVD', 'BLVD' => 'BLVD', 'LANE' => 'LN', 'LN' => 'LN',
        'COURT' => 'CT', 'CT' => 'CT', 'CIRCLE' => 'CIR', 'CIR' => 'CIR', 'PLACE' => 'PL', 'PL' => 'PL',
        'TERRACE' => 'TER', 'TER' => 'TER', 'TRAIL' => 'TRL', 'TRL' => 'TRL', 'PARKWAY' => 'PKWY', 'PKWY' => 'PKWY',
        'HIGHWAY' => 'HWY', 'HWY' => 'HWY', 'EXPRESSWAY' => 'EXPY', 'EXPY' => 'EXPY',
        'SQUARE' => 'SQ', 'SQ' => 'SQ', 'POINT' => 'PT', 'PT' => 'PT', 'COVE' => 'CV', 'CV' => 'CV',
        'CROSSING' => 'XING', 'XING' => 'XING', 'CRESCENT' => 'CRES', 'CRES' => 'CRES',
        'TURNPIKE' => 'TPKE', 'TPKE' => 'TPKE', 'WAY' => 'WAY', 'WY' => 'WAY', 'LOOP' => 'LOOP', 'LP' => 'LOOP',
        'BV' => 'BLVD', 'CI' => 'CIR', 'TR' => 'TRL', 'TE' => 'TER', 'PY' => 'PKWY', 'HY' => 'HWY'];
    private const DIRECTIONS = ['NORTH' => 'N', 'N' => 'N', 'SOUTH' => 'S', 'S' => 'S',
        'EAST' => 'E', 'E' => 'E', 'WEST' => 'W', 'W' => 'W', 'NORTHEAST' => 'NE', 'NE' => 'NE',
        'NORTHWEST' => 'NW', 'NW' => 'NW', 'SOUTHEAST' => 'SE', 'SE' => 'SE', 'SOUTHWEST' => 'SW', 'SW' => 'SW'];
    private const UNITS = ['APARTMENT' => 'APT', 'APT' => 'APT', 'SUITE' => 'STE', 'STE' => 'STE',
        'UNIT' => 'UNIT', '#' => 'UNIT'];

    public static function matches(string $left, string $right): bool
    {
        foreach (self::variants($left) as $a) {
            foreach (self::variants($right) as $b) {
                if ($a['street'] === $b['street'] && self::sameUnit($a, $b)) return true;
            }
        }
        return false;
    }

    /** Caller must also verify exact key, name, ZIP/state and candidate uniqueness. */
    public static function matchesWithOmittedDirection(string $left, string $right): bool
    {
        foreach (self::variants($left) as $a) {
            foreach (self::variants($right) as $b) {
                if (!self::sameUnit($a, $b)) continue;
                $aParts = self::directionParts($a['street']);
                $bParts = self::directionParts($b['street']);
                if ($aParts['base'] !== $bParts['base']) continue;
                $missing = 0;
                foreach (['pre', 'post'] as $position) {
                    if ($aParts[$position] === $bParts[$position]) continue;
                    if ($aParts[$position] !== '' && $bParts[$position] !== '') continue 2;
                    $missing++;
                }
                if ($missing === 1) return true;
            }
        }
        return false;
    }

    private static function sameUnit(array $a, array $b): bool
    {
        return $a['unit'] === $b['unit'] && ($a['kind'] === $b['kind']
            || in_array('', [$a['kind'], $b['kind']], true) || in_array('UNIT', [$a['kind'], $b['kind']], true));
    }

    private static function directionParts(array $street): array
    {
        $pre = $post = '';
        $last = count($street) - 1;
        if ($last >= 3 && isset(self::DIRECTIONS[$street[$last]], self::SUFFIXES[$street[$last - 1]])) {
            $post = array_pop($street);
        }
        // House/name/suffix alone leaves NORTH as the actual street name.
        if (count($street) >= 4 && isset(self::DIRECTIONS[$street[1]])) {
            $pre = $street[1];
            array_splice($street, 1, 1);
        }
        return ['base' => $street, 'pre' => $pre, 'post' => $post];
    }

    private static function variants(string $address): array
    {
        $address = mb_strtoupper(trim($address), 'UTF-8');
        $address = preg_replace('/(?<=\p{L})\./u', '', $address);
        $tokens = preg_split('/\s+/u', trim(str_replace([',', '#'], [' ', ' # '], $address))) ?: [];
        if (count($tokens) < 3 || !preg_match('/^[0-9]+[A-Z]?(?:[-\/][0-9]+[A-Z]?)?$/D', $tokens[0])) return [];
        $last = count($tokens) - 1;
        if ($last >= 4 && isset(self::UNITS[$tokens[$last - 1]]) && self::unitValue($tokens[$last])) {
            $street = self::street(array_slice($tokens, 0, -2));
            return $street === null ? [] : [['street' => $street, 'unit' => $tokens[$last],
                'kind' => self::UNITS[$tokens[$last - 1]]]];
        }
        $variants = [];
        $street = self::street($tokens);
        if ($street !== null) $variants[] = ['street' => $street, 'unit' => '', 'kind' => ''];
        // An omitted unit label is allowed only when the same explicit unit value
        // exists on the other address. Do not reinterpret numbered roads as units.
        if ($street === null && $last >= 3 && self::unitValue($tokens[$last])
            && !array_intersect($tokens, ['COUNTY', 'STATE', 'ROUTE', 'RTE', 'HIGHWAY', 'HWY', 'HY', 'LOOP', 'LP', 'US'])) {
            $street = self::street(array_slice($tokens, 0, -1));
            if ($street !== null) $variants[] = ['street' => $street, 'unit' => $tokens[$last], 'kind' => ''];
        }
        return $variants;
    }

    private static function street(array $tokens): ?array
    {
        if (count($tokens) < 3) return null;
        $suffix = count($tokens) - 1;
        if ($suffix >= 3 && isset(self::DIRECTIONS[$tokens[$suffix]], self::SUFFIXES[$tokens[$suffix - 1]])) {
            $tokens[$suffix] = self::DIRECTIONS[$tokens[$suffix]];
            $suffix--;
        }
        if (!isset(self::SUFFIXES[$tokens[$suffix]])) return null;
        $tokens[$suffix] = self::SUFFIXES[$tokens[$suffix]];
        // With only house/name/suffix, NORTH is the street name, not a direction.
        if ($suffix >= 3 && isset(self::DIRECTIONS[$tokens[1]])) $tokens[1] = self::DIRECTIONS[$tokens[1]];
        return $tokens;
    }

    private static function unitValue(string $value): bool
    {
        return preg_match('/^(?:[A-Z]|[A-Z0-9]*[0-9][A-Z0-9]*)(?:[-\/][A-Z0-9]+)?$/D', $value) === 1;
    }
}
