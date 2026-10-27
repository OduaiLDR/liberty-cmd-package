<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsCurrentDebtSql;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsTierSelectionSql;
use Cmd\Reports\Services\SmsTierWindowSelectionSql;

test('tier selection reuses the exact source validity and monetary expressions before its only quantity limit', function (): void {
    $eligible = 'SELECT PK,Phone,Client,Address,City,State,Zip,Email,Creation_Date,Last_SMS_Date FROM TblProspects WHERE State=?';
    $sql = SmsTierSelectionSql::select($eligible, ['T9','T2']);
    expect($sql)->toContain($eligible, SmsCurrentDebtSql::VALID_PREDICATE, SmsCurrentDebtSql::ROUNDED_DEBT_SQL,
        'r.Tier IN (?,?)', 'FROM Wanted w JOIN Resolved r', 'w.Snapshot_Zip', 'Was_Previously_Texted, Last_SMS_Date_At_Selection')
        ->not->toContain('p.Tier', 'p.Debt_Tier', 'NOLOCK', 'UPDATE TblProspects', "r.Tier IN ('T9','T2')");
    expect(substr_count($sql, 'TOP (?)'))->toBe(1)->and(substr_count($sql,'?'))->toBe(5);
    expect(strpos($sql, 'State=?'))->toBeLessThan(strpos($sql, 'SELECT TOP (?) ?'));
    expect(strpos($sql, 'SELECT TOP (?) ?'))->toBeLessThan(strpos($sql, 'r.Tier IN (?,?)'));
    expect($sql)->toContain('CASE WHEN w.Last_SMS_Date IS NULL THEN 0 ELSE 1 END ASC',
        'CASE WHEN w.Last_SMS_Date IS NULL THEN w.Creation_Date END DESC', 'w.Last_SMS_Date ASC, w.Export_PK ASC');
});

test('all-tier selection still requires explicit requested tiers and binds every value', function (): void {
    $sql = SmsTierSelectionSql::select('SELECT * FROM TblProspects', SmsRunOptions::TIERS);
    expect(substr_count($sql, '?'))->toBe(11)->and(substr_count($sql,'INSERT INTO TblProspectsExport'))->toBe(1);
    // This is one logical SQL statement, not a claim about physical CTE evaluation
    // count, production indexes or execution-plan performance.
    expect($sql)->not->toContain('DELETE ', 'TRUNCATE ', 'BEGIN TRAN', 'sp_getapplock');
});

test('windowed selection resolves only the connection-local ordered page without a global join hint', function (): void {
    $sql = SmsTierWindowSelectionSql::insert(['T9','T2']);
    expect($sql)->toContain('FROM #SmsTierWanted', SmsCurrentDebtSql::VALID_PREDICATE,
        SmsCurrentDebtSql::ROUNDED_DEBT_SQL, 't.SMS_Zip5=w.Zip', 'm.SMS_Zip5=w.Zip',
        'ORDER BY w.Window_Position ASC', 'OPTION (RECOMPILE)')
        ->not->toContain('OPTION (HASH JOIN)', 'LEFT(t.ncoaAddr_zipCde,5)', 'LEFT(m.Zip,5)', 'TblProspects p', 'UPDATE TblProspects');
    expect(substr_count($sql, '?'))->toBe(4)
        ->and(strpos($sql, 'SELECT TOP (?) ?'))->toBeLessThan(strpos($sql, 'r.Tier IN (?,?)'));
});

test('lean v3 writes no second source id for Tier while old selectors retain their frozen shape', function (): void {
    $eligible = 'SELECT PK,Phone,Client,Address,City,State,Zip,Email,Creation_Date,Last_SMS_Date FROM TblProspects';
    $old = SmsTierSelectionSql::select($eligible, ['T2']);
    $lean = SmsTierSelectionSql::select($eligible, ['T2'], 'Send_Date', true);
    $oldWindow = SmsTierWindowSelectionSql::insert(['T2']);
    $leanWindow = SmsTierWindowSelectionSql::insert(['T2'], 'Send_Date', true);

    foreach ([$lean, $leanWindow] as $sql) {
        expect($sql)->toContain("r.Source, CASE WHEN r.Source='Tier' THEN NULL ELSE r.Source_PK END")
            ->and(substr_count($sql, 'TOP (?)'))->toBe(1);
    }
    foreach ([$old, $oldWindow] as $sql) {
        expect($sql)->toContain('r.Source, r.Source_PK')->not->toContain("CASE WHEN r.Source='Tier'");
    }
});

test('invalid tier values lists and obsolete clocks fail before generating selection SQL', function (array $tiers): void {
    expect(fn () => SmsTierSelectionSql::select('SELECT * FROM TblProspects',$tiers))->toThrow(RuntimeException::class);
    expect(fn () => SmsTierWindowSelectionSql::insert($tiers))->toThrow(RuntimeException::class);
})->with([
    [[]], [['T2','T2']], [['T0']], [['t2']], [['Tier2']], [['T2; DROP TABLE x']], [[2]], [[null]], [['tier'=>'T2']], [[1=>'T2']],
]);

test('the shared resolver keeps the original update wrapper and rejects unapproved clocks', function (): void {
    $sql = SmsCurrentDebtSql::resolve();
    expect($sql)->toContain('FROM TblProspectsExport x WHERE x.Request_ID=? AND x.Debt_Source IS NULL',
        'UPDATE x SET Debt_Amount='.SmsCurrentDebtSql::ROUNDED_DEBT_SQL, 'WHERE '.SmsCurrentDebtSql::VALID_PREDICATE);
    expect(fn () => SmsCurrentDebtSql::withResolved(''))->toThrow(RuntimeException::class);
    expect(fn () => SmsTierSelectionSql::select('', ['T2']))->toThrow(RuntimeException::class);
    expect(fn () => SmsTierSelectionSql::select('SELECT * FROM TblProspects',['T2'],'Update_Date'))->toThrow(RuntimeException::class);
});
