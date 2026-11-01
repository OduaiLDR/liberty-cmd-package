<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsRunOptions;

test('lean history is an explicit new-run version and leaves old selector markers readable', function (): void {
    expect(SmsRunOptions::TIER_SELECTION_POLICIES)->toBe([
        SmsRunOptions::TIER_SELECTION_POLICY,
        SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
        SmsRunOptions::LEAN_WINDOWED_TIER_SELECTION_POLICY,
    ])->and(SmsRunOptions::usesLeanDebtSources(SmsRunOptions::TIER_SELECTION_POLICY))->toBeFalse()
        ->and(SmsRunOptions::usesLeanDebtSources(SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY))->toBeFalse()
        ->and(SmsRunOptions::usesLeanDebtSources(SmsRunOptions::LEAN_WINDOWED_TIER_SELECTION_POLICY))->toBeTrue();
});

test('runtime schema gate requires unique non-null TU and mailer PK lookup identities', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/src/Services/SmsLeanHistorySchema.php');
    expect($source)->toContain("private const SOURCES = ['TblTUData', 'TblMailers']", "c.name AS column_name,ty.name AS type_name,c.is_nullable",
        "i.is_unique=1", "array_values(\$index['keys']) === ['PK']", "(int) \$row->has_filter === 0")
        ->not->toContain('CREATE INDEX', 'ALTER TABLE', 'DROP INDEX');
});

test('operator preflight is metadata and aggregate read only with explicit database and policy guards', function (): void {
    $sql = file_get_contents(dirname(__DIR__, 2).'/database/sms-performance/lean-history-preflight.sql');
    expect($sql)->toContain("DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')",
        "N'current-source-tier-windowed-lean-v3' AS Policy_Version", "i.is_unique=1",
        "WHEN x.Debt_Source='Tier' AND x.Debt_Source_PK IS NULL THEN 'tier_without_second_id'",
        'Missing_TU_Lookups', 'Missing_Mailer_Lookups', 'Tier_With_Forbidden_Second_ID',
        "FROM dbo.TblSMSHistory h", 'Approved_History_Records', 'Approved_Missing_TU_Lookups',
        'Approved_Missing_Mailer_Lookups', 'Approved_Tier_With_Forbidden_Second_ID')
        ->not->toMatch('/\b(?:INSERT|UPDATE|DELETE|MERGE|ALTER|CREATE|DROP|TRUNCATE)\b/i');
});
