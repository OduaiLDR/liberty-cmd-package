<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Structured NSF evidence for comparison with the workbook produced by the same calculation. */
final class NsfStructuredCapture
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function fromCalculation(
        string $runId,
        string $captureId,
        array $readiness,
        array $dataRows,
        array $pricedRows,
        array $unassigned,
        callable $isOnRoster,
        bool $managedRoster,
    ): array {
        return [
            'version' => 1,
            'source' => $readiness['source'],
            'period' => $readiness['period'],
            'runId' => $runId,
            'captureId' => $captureId,
            'readiness' => $readiness,
            'rosterBasis' => $managedRoster ? 'managed' : 'fallback',
            'contacts' => array_map(static fn (array $row): array => [
                'contactId' => (string) $row['ID'],
                'agent' => self::sourceText($row['AGENT']),
                'returnedDate' => self::sourceText($row['NSF_RETURNED_DATE']),
                'action' => self::sourceText($row['NSF_ACTION']),
                'recoupDate' => self::sourceText($row['NSF_RECOUP_DATE']),
                'clearedDate' => self::sourceText($row['CLEARED_DATE']),
                'eligible' => $row['valid_commission'],
            ], $dataRows),
            'agents' => array_map(static fn (array $row): array => [
                'agent' => $row['agent'],
                'assignments' => $row['assignments'],
                'actions' => $row['actions'],
                'ratio' => $row['ratio'],
                'actionsTier' => $row['actions_tier'],
                'clearedTier' => $row['cleared_tier'],
                'rateCents' => (int) round($row['rate'] * 100),
                'clears' => $row['clears'],
                'commissionCents' => (int) round($row['commission'] * 100),
                'location' => $row['location'],
                'company' => $row['company'],
                'onRoster' => $isOnRoster($row['agent']),
            ], $pricedRows),
            'unassigned' => array_map(static fn (array $row): array => [
                'agent' => $row['agent'],
                'commissionCents' => (int) round($row['amount'] * 100),
            ], $unassigned),
        ];
    }

    private static function sourceText(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** Serialize the artifacts already verified against the archive or durable completion receipt. */
    public static function export(array $artifacts, string $period, string $runId, bool $queryOnly): string
    {
        $readinessIds = [$runId];
        $invocationIds = [];
        $sources = [];
        foreach (['ldr', 'plaw'] as $source) {
            $key = 'nsf_' . $source;
            $artifact = $artifacts[$key] ?? [];
            if (($artifact['key'] ?? null) !== $key || ($artifact['period'] ?? null) !== $period
                || ($artifact['identity'] ?? null) !== $runId || !is_array($artifact['context'] ?? null)
                || !is_string($artifact['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'])) {
                throw new \RuntimeException('The NSF artifact does not match the selected capture.');
            }
            $context = $artifact['context'];
            $capture = $context['nsfStructuredCapture'] ?? null;
            if (($context['queryOnly'] ?? false) !== $queryOnly || !is_array($capture)
                || ($capture['version'] ?? null) !== 1 || ($capture['source'] ?? null) !== $source
                || ($capture['period'] ?? null) !== $period || ($capture['runId'] ?? null) !== $runId
                || (array_key_exists('captureId', $capture) && !is_string($capture['captureId']))
                || !in_array($capture['rosterBasis'] ?? null, ['managed', 'fallback'], true)) {
                throw new \RuntimeException('The NSF run has no structured evidence in the requested capture mode.');
            }
            foreach (['contacts', 'agents', 'unassigned'] as $field) {
                if (!is_array($capture[$field] ?? null) || !array_is_list($capture[$field])) {
                    throw new \RuntimeException('The NSF structured source has incomplete rows.');
                }
            }
            $readiness = $capture['readiness'] ?? null;
            $readinessId = $readiness['runId'] ?? null;
            if (!is_array($readiness) || ($readiness['source'] ?? null) !== $source || ($readiness['period'] ?? null) !== $period
                || !is_string($readinessId) || !preg_match('/^[a-f0-9]{32}$/D', $readinessId)
                || in_array($readinessId, $readinessIds, true) || !self::matchesReadinessTiming($readiness, $period)) {
                throw new \RuntimeException('The NSF structured source has invalid readiness evidence.');
            }
            $readinessIds[] = $readinessId;
            $invocationIds[] = $capture['captureId'] ?? null;
            $captureJson = json_encode($capture, self::JSON_FLAGS);
            $sources[$source] = [
                'workbookSha256' => $artifact['sha256'],
                'captureJson' => $captureJson,
                'captureSha256' => hash('sha256', $captureJson),
            ];
        }

        self::assertSharedInvocation($invocationIds, $readinessIds, $queryOnly);
        return json_encode(['version' => 1, 'runId' => $runId, 'period' => $period, 'sources' => $sources], self::JSON_FLAGS);
    }

    /** Historical payable captures predate the invocation ID. A comparison export must prove its pair. */
    public static function assertSharedInvocation(array $invocationIds, array $reservedIds, bool $required): void
    {
        [$ldr, $plaw] = $invocationIds;
        if (!$required && $ldr === null && $plaw === null) {
            return;
        }
        if (!is_string($ldr) || !preg_match('/^[a-f0-9]{32}$/D', $ldr) || $ldr !== $plaw
            || in_array($ldr, $reservedIds, true)) {
            throw new \InvalidArgumentException('The NSF sources do not belong to the same structured capture invocation.');
        }
    }

    private static function matchesReadinessTiming(array $readiness, string $period): bool
    {
        if (!is_string($readiness['startedAt'] ?? null)) {
            return false;
        }
        $startedAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $readiness['startedAt'], new \DateTimeZone('UTC'));
        if ($startedAt === false) {
            return false;
        }
        foreach (NsfReportReadiness::timing($period, $startedAt) as $field => $value) {
            if (($readiness[$field] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
