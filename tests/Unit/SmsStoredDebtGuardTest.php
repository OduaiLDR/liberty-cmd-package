<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsStoredDebt;
use Illuminate\Database\Connection;

// Plan review M2, Nov 11 2026: stored values point at source rows by PK, so a swapped or reloaded source table, or TU rows
// the refresh hasn't handled yet, must stop a run instead of sending a wrong or missing debt. Made-up marks only.

function storedDebtDb(array $marks, string $driver = 'sqlsrv'): Connection
{
    $db = Mockery::mock(Connection::class);
    $db->shouldReceive('getDriverName')->andReturn($driver);
    $db->shouldReceive('selectOne')->andReturnUsing(function (string $sql) use ($marks): object {
        foreach ($marks as $table => $mark) {
            if (str_contains($sql, "dbo.{$table}")) return (object) $mark;
        }
        throw new RuntimeException('unexpected query');
    });

    return $db;
}

function storedDebtState(array $state): string
{
    $path = tempnam(sys_get_temp_dir(), 'sms-stored-debt-state-');
    file_put_contents($path, json_encode($state));

    return $path;
}

$saved = ['TblTUData' => ['object_id' => 101, 'max_pk' => 5000], 'TblMailers' => ['object_id' => 202, 'max_pk' => 900]];

test('the same tables with new mailers pass, and new TU rows pass once the refresh has handled them', function () use ($saved): void {
    expect(SmsStoredDebt::sourceChange($saved, $saved))->toBeNull();
    $grown = ['TblTUData' => ['object_id' => 101, 'max_pk' => 7000], 'TblMailers' => ['object_id' => 202, 'max_pk' => 950]];
    expect(SmsStoredDebt::sourceChange($saved, $grown))->toBeNull();
    $path = storedDebtState(['tu_watermark' => 7000, 'sources' => $saved]);
    SmsStoredDebt::assertCurrent(storedDebtDb($grown), $path);
    unlink($path);
    expect(true)->toBeTrue();
});

test('a swapped or reloaded source table stops runs with the table named', function () use ($saved): void {
    expect(SmsStoredDebt::sourceChange($saved, ['TblTUData' => ['object_id' => 999, 'max_pk' => 5000], 'TblMailers' => $saved['TblMailers']]))
        ->toContain('TblTUData was replaced');
    expect(SmsStoredDebt::sourceChange($saved, ['TblTUData' => $saved['TblTUData'], 'TblMailers' => ['object_id' => 202, 'max_pk' => 899]]))
        ->toContain('TblMailers was reloaded');
    $path = storedDebtState(['tu_watermark' => 5000, 'sources' => $saved]);
    expect(fn () => SmsStoredDebt::assertCurrent(storedDebtDb(['TblTUData' => ['object_id' => 555, 'max_pk' => 5000], 'TblMailers' => $saved['TblMailers']]), $path))
        ->toThrow(RuntimeException::class, 'TblTUData was replaced');
    unlink($path);
});

test('TU rows the refresh has not handled yet stop a run with how many and what to do', function () use ($saved): void {
    $path = storedDebtState(['tu_watermark' => 5000, 'sources' => $saved]);
    $live = ['TblTUData' => ['object_id' => 101, 'max_pk' => 655000], 'TblMailers' => $saved['TblMailers']];
    expect(fn () => SmsStoredDebt::assertCurrent(storedDebtDb($live), $path))
        ->toThrow(RuntimeException::class, 'still catching up with 650,000 new TU rows');
    unlink($path);
});

test('an older state with no saved marks is judged only on the TU watermark', function () use ($saved): void {
    $path = storedDebtState(['tu_watermark' => 5000]);
    SmsStoredDebt::assertCurrent(storedDebtDb(['TblTUData' => ['object_id' => 999, 'max_pk' => 5000], 'TblMailers' => ['object_id' => 1, 'max_pk' => 1]]), $path);
    unlink($path);
    expect(true)->toBeTrue();
});

test('no state file or another driver refuses nothing, and an unreadable state file stops the run', function () use ($saved): void {
    SmsStoredDebt::assertCurrent(storedDebtDb($saved), sys_get_temp_dir().'/no-such-sms-state-'.bin2hex(random_bytes(4)).'.json');
    SmsStoredDebt::assertCurrent(storedDebtDb([], 'sqlite'), storedDebtState(['tu_watermark' => 1]));
    $broken = tempnam(sys_get_temp_dir(), 'sms-stored-debt-state-');
    file_put_contents($broken, '{not json');
    expect(fn () => SmsStoredDebt::assertCurrent(storedDebtDb($saved), $broken))->toThrow(RuntimeException::class, 'unreadable');
    unlink($broken);
});

test('the marks query names each source table and reads its OBJECT_ID and highest PK', function () use ($saved): void {
    $marks = SmsStoredDebt::sourceMarks(storedDebtDb($saved));
    expect($marks)->toBe($saved);
});

// Nov 19, 2026: the same answer stops the click and tells the page why Generate is off.
test('the page is told what a new run waits for, with how many, and nothing when it can go', function () use ($saved): void {
    $path = storedDebtState(['tu_watermark' => 5000, 'sources' => $saved, 'prospects_watermark' => 100]);
    expect(SmsStoredDebt::waiting(storedDebtDb($saved + ['TblProspects' => ['newest' => 100]]), $path))->toBeNull();
    // Newer prospects, all of them filled or not textable.
    expect(SmsStoredDebt::waiting(storedDebtDb($saved + ['TblProspects' => ['newest' => 180, 'people' => 0]]), $path))->toBeNull();
    $waiting = SmsStoredDebt::waiting(storedDebtDb($saved + ['TblProspects' => ['newest' => 173978, 'people' => 157686]]), $path);
    expect($waiting['reason'])->toBe('new_people')->and($waiting['people'])->toBe(157686)
        ->and($waiting['message'])->toContain('157,686 new people from TU are still being given their debt and tier');
    expect(fn () => SmsStoredDebt::assertCurrent(storedDebtDb($saved + ['TblProspects' => ['newest' => 173978, 'people' => 157686]]), $path))
        ->toThrow(RuntimeException::class, '157,686 new people from TU');
    unlink($path);

    $path = storedDebtState(['tu_watermark' => 5000, 'sources' => $saved, 'prospects_watermark' => 100]);
    $tu = SmsStoredDebt::waiting(storedDebtDb(['TblTUData' => ['object_id' => 101, 'max_pk' => 655000], 'TblMailers' => $saved['TblMailers']]), $path);
    expect($tu['reason'])->toBe('new_tu_rows')->and($tu['tu_rows'])->toBe(650000)->and($tu)->not->toHaveKey('people');
    $swapped = SmsStoredDebt::waiting(storedDebtDb(['TblTUData' => ['object_id' => 555, 'max_pk' => 5000], 'TblMailers' => $saved['TblMailers']]), $path);
    expect($swapped['reason'])->toBe('source_changed')->and($swapped['message'])->toContain('TblTUData was replaced');
    unlink($path);

    $broken = tempnam(sys_get_temp_dir(), 'sms-stored-debt-state-');
    file_put_contents($broken, '{not json');
    expect(SmsStoredDebt::waiting(storedDebtDb($saved), $broken)['reason'])->toBe('unreadable');
    unlink($broken);
    expect(SmsStoredDebt::waiting(storedDebtDb([], 'sqlite'), storedDebtState(['tu_watermark' => 1, 'prospects_watermark' => 1])))->toBeNull();
});

test('a state saved before the mark existed never reads the prospects table', function () use ($saved): void {
    $path = storedDebtState(['tu_watermark' => 5000, 'sources' => $saved]);
    // The made-up database throws on any query it does not know, so this passes only if TblProspects is not read.
    expect(SmsStoredDebt::waiting(storedDebtDb($saved), $path))->toBeNull();
    unlink($path);
});
