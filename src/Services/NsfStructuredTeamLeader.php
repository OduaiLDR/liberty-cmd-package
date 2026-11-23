<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Cmd\Reports\Console\Commands\GenerateNSFCommissionReport\Formatter;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use stdClass;

/** Read the roster fields and frozen contact eligibility from a trusted v1 shadow export. */
final class NsfStructuredTeamLeader
{
    public static function inputs(string $period, string $runId, array $envelope): array
    {
        self::requireFields($envelope, ['version', 'period', 'runId', 'sources']);
        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-01$/D', $period) || !self::isHexId($runId, 32)
            || $envelope['version'] !== 1 || $envelope['period'] !== $period || $envelope['runId'] !== $runId
            || !is_array($envelope['sources'])) {
            self::invalid();
        }
        self::requireFields($envelope['sources'], ['ldr', 'plaw']);

        $rows = $rosters = $readinessIds = [];
        foreach (['ldr' => 'LDR', 'plaw' => 'Progress Law'] as $source => $display) {
            $capture = self::capture($envelope['sources'][$source], $period, $runId, $source);
            $readinessId = self::readinessId($capture->readiness, $period, $source);
            if ($readinessId === $runId || in_array($readinessId, $readinessIds, true)) {
                self::invalid();
            }
            $readinessIds[$source] = $readinessId;
            $rosters[$source] = self::roster($capture->agents);

            $seen = [];
            foreach ($capture->contacts as $contact) {
                $row = self::contact($contact, $period, $display);
                if (isset($seen[$row['ID']])) {
                    self::invalid();
                }
                $seen[$row['ID']] = true;
                $rows[] = $row;
            }
        }

        return [
            'periodEnd' => (new DateTimeImmutable($period, new DateTimeZone('UTC')))->format('Y-m-t'),
            'rows' => $rows,
            'rosters' => $rosters,
            'readinessIds' => $readinessIds,
        ];
    }

    private static function capture(mixed $entry, string $period, string $runId, string $source): stdClass
    {
        if (!is_array($entry)) {
            self::invalid();
        }
        self::requireFields($entry, ['workbookSha256', 'captureJson', 'captureSha256']);
        // The workbook checksum is a provenance reference. The capture hash only checks the supplied bytes.
        if (!self::isHexId($entry['workbookSha256'], 64) || !self::isHexId($entry['captureSha256'], 64)
            || !is_string($entry['captureJson']) || !hash_equals($entry['captureSha256'], hash('sha256', $entry['captureJson']))) {
            self::invalid();
        }

        $capture = json_decode($entry['captureJson'], depth: 8, flags: JSON_THROW_ON_ERROR);
        if (!$capture instanceof stdClass) {
            self::invalid();
        }
        self::requireFields((array) $capture, ['version', 'source', 'period', 'runId', 'readiness', 'rosterBasis', 'contacts', 'agents', 'unassigned']);
        if ($capture->version !== 1 || $capture->source !== $source || $capture->period !== $period || $capture->runId !== $runId
            || !in_array($capture->rosterBasis, ['managed', 'fallback'], true)
            || !is_array($capture->contacts) || !is_array($capture->agents) || !is_array($capture->unassigned)) {
            self::invalid();
        }

        return $capture;
    }

    private static function readinessId(mixed $readiness, string $period, string $source): string
    {
        if (!$readiness instanceof stdClass) {
            self::invalid();
        }
        self::requireFields((array) $readiness, ['period', 'startedAt', 'finalAfter', 'cutoffPacific', 'isProvisional', 'source', 'runId']);
        if ($readiness->source !== $source || !self::isHexId($readiness->runId, 32) || !is_string($readiness->startedAt)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}$/D', $readiness->startedAt)) {
            self::invalid();
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $readiness->startedAt, new DateTimeZone('UTC'));
        if ($start === false) {
            self::invalid();
        }
        foreach (NsfReportReadiness::timing($period, $start) as $field => $expected) {
            if ($readiness->$field !== $expected) {
                self::invalid();
            }
        }

        return $readiness->runId;
    }

    private static function roster(array $agents): array
    {
        $roster = [];
        foreach ($agents as $agent) {
            if (!$agent instanceof stdClass || !self::isAgent($agent->agent ?? null) || !is_bool($agent->onRoster ?? null)
                || !is_string($agent->location ?? null) || !is_string($agent->company ?? null)) {
                self::invalid();
            }
            if ($agent->onRoster) {
                if (strcasecmp(trim($agent->agent), 'Unassigned Agents') === 0) {
                    self::invalid();
                }
                $roster[] = ['agent' => $agent->agent, 'location' => $agent->location, 'company' => $agent->company];
            }
        }

        return array_map(static fn (array $row): string => trim($row['agent']), Formatter::sortByLocationCompanyAgent($roster));
    }

    private static function contact(mixed $contact, string $period, string $display): array
    {
        if (!$contact instanceof stdClass) {
            self::invalid();
        }
        self::requireFields((array) $contact, ['contactId', 'agent', 'returnedDate', 'action', 'recoupDate', 'clearedDate', 'eligible']);
        if (!is_string($contact->contactId) || !preg_match('/^[1-9][0-9]*$/D', $contact->contactId)
            || !self::isAgent($contact->agent) || !is_bool($contact->eligible)
            || ($contact->action !== null && (!is_string($contact->action)
                || DefaultValueBinder::dataTypeForValue($contact->action) === DataType::TYPE_FORMULA))
            || !self::isDate($contact->returnedDate) || substr($contact->returnedDate, 0, 7) !== substr($period, 0, 7)) {
            self::invalid();
        }
        foreach ([$contact->recoupDate, $contact->clearedDate] as $date) {
            if ($date !== null && $date !== '' && !self::isDate($date)) {
                self::invalid();
            }
        }

        return ['ID' => $contact->contactId, 'AGENT' => $contact->agent, 'NSF_ACTION' => $contact->action,
            'VALID_COMMISSION' => $contact->eligible, 'SOURCE' => $display];
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function isAgent(mixed $value): bool
    {
        return is_string($value) && trim($value) !== ''
            && !in_array(DefaultValueBinder::dataTypeForValue($value), [DataType::TYPE_FORMULA, DataType::TYPE_NUMERIC], true);
    }

    private static function isHexId(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && preg_match('/^[a-f0-9]+$/D', $value);
    }

    private static function requireFields(array $value, array $fields): void
    {
        if (count($value) !== count($fields) || array_diff($fields, array_keys($value))) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('The NSF structured shadow export is invalid or outside the supported leadership scope.');
    }
}
