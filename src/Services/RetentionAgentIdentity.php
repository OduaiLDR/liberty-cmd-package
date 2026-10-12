<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Canonical identities for explicitly verified CRM spelling variants. */
final class RetentionAgentIdentity
{
    /** @var array<string,string> */
    private const VERIFIED_ALIASES = [
        'andrea mendoze' => 'Andrea Mendoza',
        'andrea galves' => 'Andrea Galvez',
        'aleph bolanos' => 'Aleph Bolaños',
    ];

    public static function canonicalName(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', trim($name)));
        // PCRE's Unicode-aware case-insensitive match handles both ALEPH BOLAÑOS and
        // Aleph Bolaños without relying on mbstring or broad accent folding.
        if (preg_match('/\Aaleph bola(?:n|ñ)os\z/iu', $clean) === 1) {
            return 'Aleph Bolaños';
        }
        $key = strtolower($clean);

        return self::VERIFIED_ALIASES[$key] ?? $clean;
    }

    /** @param array<int,array<string,mixed>> $rows
     *  @return array<int,array<string,mixed>>
     */
    public static function canonicalizeRows(array $rows, string $field = 'RETENTION_AGENT'): array
    {
        foreach ($rows as &$row) {
            if (array_key_exists($field, $row)) {
                $row[$field] = self::canonicalName((string) $row[$field]);
            }
        }
        unset($row);

        return $rows;
    }

    /** @param array<int,string> $names
     *  @return list<string>
     */
    public static function canonicalizeNames(array $names): array
    {
        $canonical = [];
        $seen = [];
        foreach ($names as $name) {
            $name = self::canonicalName((string) $name);
            if ($name === '') {
                continue;
            }
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $canonical[] = $name;
        }

        return $canonical;
    }
}
