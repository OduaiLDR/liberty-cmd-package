<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Canonical values from a trusted internal caller, not proof of source authenticity,
 * complete identity coverage, a unique match, or an event that is not in the future.
 */
final readonly class SmsContactEvent
{
    private function __construct(
        public int $prospectPk,
        public string $phone,
        public string $utcExact,
        public string $utcSql,
        public string $californiaDate,
        public string $sourceSystem,
        public string $sourceContactId,
        public string $sourceContractSha256,
        public int $candidateCount,
        public bool $coverageComplete,
    ) {}

    public static function fromCanonical(
        int $prospectPk,
        string $phone,
        string $explicitOriginalUtc,
        string $sourceSystem,
        string $sourceContactId,
        string $sourceContractSha256,
        int $candidateCount,
        bool $coverageComplete,
    ): self {
        if ($prospectPk < 1 || $prospectPk > 2147483647) {
            throw new InvalidArgumentException('A contact event requires a positive SQL int prospect key.');
        }
        if (! preg_match('/\A[0-9]{10}\z/', $phone)) {
            throw new InvalidArgumentException('A contact event requires an exact canonical ten-digit phone.');
        }
        if (! preg_match('/\A[a-z][a-z0-9._-]{0,39}\z/', $sourceSystem)) {
            throw new InvalidArgumentException('A contact event requires a canonical source-system identifier.');
        }
        // Only ordinary ASCII spaces may be trimmed. Never hide control characters
        // (including leading/trailing tabs or line breaks) through normalization.
        if (! preg_match('/\A[\x20-\x7E]+\z/', $sourceContactId)) {
            throw new InvalidArgumentException('A contact event requires a printable ASCII source contact identifier.');
        }
        $sourceContactId = trim($sourceContactId, ' ');
        if ($sourceContactId === '' || strlen($sourceContactId) > 255) {
            throw new InvalidArgumentException('A contact event requires a source contact identifier of 1 to 255 characters.');
        }
        if (! preg_match('/\A[0-9a-f]{64}\z/', $sourceContractSha256)) {
            throw new InvalidArgumentException('A contact event requires a lowercase SHA-256 source-contract fingerprint.');
        }
        if ($candidateCount < 1) {
            throw new InvalidArgumentException('A contact event requires a positive candidate count.');
        }

        [$exact, $sql, $instant] = self::parseUtc($explicitOriginalUtc);
        $california = $instant->setTimezone(new DateTimeZone('America/Los_Angeles'));
        $californiaYear = (int) $california->format('Y');
        if ($californiaYear < 1 || $californiaYear > 9999) {
            throw new InvalidArgumentException('The contact event California date is outside the SQL date year range.');
        }

        return new self($prospectPk, $phone, $exact, $sql,
            $california->format('Y-m-d'),
            $sourceSystem, $sourceContactId, $sourceContractSha256, $candidateCount, $coverageComplete);
    }

    /** Fixed-width UTC strings can be compared lexically without losing nanoseconds. */
    public static function canonicalUtc(string $explicitOriginalUtc): string
    {
        return self::parseUtc($explicitOriginalUtc)[0];
    }

    /** @return array{string, string, DateTimeImmutable} */
    private static function parseUtc(string $value): array
    {
        if (! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,9}))?Z\z/', $value, $parts)
            || (int) $parts[1] < 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            throw new InvalidArgumentException('A contact event requires an explicit valid UTC timestamp ending in Z, with at most nine fractional digits.');
        }

        $second = substr($value, 0, 19);
        $fraction = str_pad($parts[7] ?? '', 9, '0');
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $second.'Z', new DateTimeZone('UTC'));
        if ($instant === false || $instant->format('Y-m-d\TH:i:s') !== $second) {
            throw new InvalidArgumentException('The contact-event UTC timestamp could not be represented exactly.');
        }

        // PHP's date object is used only for calendar conversion. Preserve all nine
        // input fractional digits separately; SQL datetime2(7) receives a floor,
        // never rounding into a different second or California calendar day.
        return [$second.'.'.$fraction.'Z', str_replace('T', ' ', $second).'.'.substr($fraction, 0, 7), $instant];
    }
}
