<?php

declare(strict_types=1);

// Emits a connection-local #temp SQL fixture. Never connects to a database or reads .env.
// Pipe into a disposable LOCAL SQL Server sqlcmd session with -b. No persistent writes.
if ($argc !== 2 || $argv[1] !== '--emit') throw new RuntimeException('Use --emit for a local SQL Server #temp fixture.');
require_once dirname(__DIR__).'/src/Services/SmsProspectValues.php';
require_once dirname(__DIR__).'/src/Services/SmsCurrentDebtSql.php';

$request = '11223344-1122-3344-5566-778899aabbcc';
echo <<<'SQL'
SET NOCOUNT ON;
CREATE TABLE #TblProspectsExport(PK int PRIMARY KEY,Request_ID uniqueidentifier,Client varchar(150),Address varchar(200),Zip varchar(10),Debt_Amount decimal(12,2),Debt_Tier varchar(30),Debt_Source varchar(20),Debt_Source_PK bigint);
CREATE TABLE #TblTUData(PK int,Creation_Date date,ncoaAddr_frstNme varchar(100),ncoaAddr_lastNme varchar(100),ncoaAddr_addrLine1Txt varchar(255),ncoaAddr_zipCde varchar(10),derivedFields1_unsecdebtbalance decimal(18,2),Tier_Tag varchar(30));
CREATE TABLE #TblMailers(PK int,Client varchar(150),Address varchar(200),Zip varchar(10),Drop_Name varchar(50),Debt_Amount decimal(12,2),Debt_Tier varchar(30));
CREATE TABLE #TblMarketing(Drop_Name varchar(50),Send_Date date,Update_Date date);
CREATE TABLE #Expected(PK int PRIMARY KEY,Source varchar(20),Source_PK bigint,Debt decimal(12,2),Tier varchar(30));

SQL;
$quote = static fn ($v) => $v === null ? 'NULL' : "'".str_replace("'", "''", (string) $v)."'";
$insert = static function ($table, $values) use ($quote): void {
    echo 'INSERT #'.$table.' VALUES('.implode(',', array_map($quote, $values)).");\n";
};
$tu = static function ($id, $pk, $date, $amount = '10500.01', $tier = 'Tier2') use ($insert): void {
    $insert('TblTUData', [$pk, $date, 'Synthetic', 'Person'.$id, $id.' Test St', '00123-1234', $amount, $tier]);
};
$mailer = static function ($id, $pk, $date, $amount = '13500.01', $tier = 'T3') use ($insert): void {
    $drop = 'fixture-'.$pk;
    $insert('TblMailers', [$pk, 'Synthetic Person'.$id, $id.' Test St', '00123', $drop, $amount, $tier]);
    $insert('TblMarketing', [$drop, $date, $date]);
};
foreach (range(1, 50) as $id) $insert('TblProspectsExport', [$id, $request, 'Synthetic Person'.$id, $id.' Test St', '00123', null, null, null, null]);
// TU wins even when the mailer date is newer; cross-source PK magnitudes are irrelevant.
$tu(1, 900000, '2026-10-01'); $mailer(1, 1, '2026-10-20');
$tu(2, 1, '2026-10-20'); $mailer(2, 900000, '2026-10-01');
$tu(3, 3, '2026-10-20', '10000');
$mailer(4, 4, '2026-10-20', '10000.01');
$tu(5, 5, '2026-10-20'); $mailer(5, 5, '2026-10-20'); // TU priority, not cross-source consensus
$tu(6, 6, '2026-10-20'); $mailer(6, 6, null); // irrelevant mailer uncertainty cannot block valid TU
$tu(7, 7, '2026-10-01'); $tu(7, 8, '2026-10-20', '0'); // no fallback to older positive
// 8 has no source at all.
$tu(9, 9, '2026-10-20', '10500.01', 'Tier2'); $mailer(9, 9, '2026-10-20', '10500.01', 'T2');
$tu(10, 10, '2026-10-20', '10000', 'unknown');
$tu(11, 11, '2026-10-20', '9999999999.99'); // rounding overflow
$tu(12, 12, '2026-10-01'); $mailer(12, 12, '2026-10-20', '0');
$mailer(13, 1000, '2026-10-01'); $mailer(13, 13, '2026-10-20', '20000', 'T4');
$tu(14, 14, '2026-10-20');
echo "UPDATE #TblProspectsExport SET Debt_Source='TblTUData',Debt_Source_PK=42,Debt_Amount=7000,Debt_Tier='T1' WHERE PK=14;\n";
$tu(15, 15, '2026-10-20', '0.01', 'Tier9');
$mailer(16, 16, '2026-10-20'); $insert('TblMarketing', ['fixture-16', '2026-10-19', '2026-10-19']);
$tu(17, 17, null); $mailer(17, 17, '2026-10-20');
$tu(18, 18, '2026-10-20', '10000', 'T1'); $tu(18, 19, '2026-10-20', '20000', 'T2');
$tu(19, 20, '2026-10-20', null); $mailer(19, 20, '2026-10-20', '10000', 'T2');
$tu(20, 21, '2026-10-20', '10000', 'T1'); $mailer(20, 21, '2026-10-20', '10000', 'T2');
$tu(21, 101, '2026-10-01', '10000', 'T1'); $tu(21, 102, '2026-10-20', '20000', 'bad');
$mailer(21, 101, '2026-10-21', '30000', 'T3'); // invalid latest TU is not absent
$mailer(22, 102, '2026-10-01', '10000', 'T1'); $mailer(22, 103, '2026-10-20', '0', 'T9');
$mailer(23, 900001, '2026-10-01', '10000', 'T1'); $mailer(23, 104, '2026-10-20', '20000', 'T4');
$mailer(24, 105, '2026-10-20', '10000', 'T1'); $mailer(24, 106, '2026-10-20', '20000', 'T2');
$mailer(25, 107, '2026-10-20', '13500', 'Tier2'); $mailer(25, 108, '2026-10-20', '13500', 'T2');
$mailer(26, 109, '2026-10-01', '10000', 'bad'); $mailer(26, 110, '2026-10-20', '0', 'T1');
$mailer(27, 111, null, '10000', 'T1'); $mailer(27, 112, '2026-10-20', '20000', 'T2');
$mailer(28, 900002, '2026-10-01', '0', 'T1'); $mailer(28, 113, '2026-10-20', null, 'Tier8');
$mailer(29, 114, '2026-10-01', '0', 'T1'); $mailer(29, 115, '2026-10-20', '0', 'bad');
$mailer(30, 116, null, '0', 'T1');
foreach (range(1, 9) as $tier) $mailer(30 + $tier, 200 + $tier, '2026-10-20', '0', 'T'.$tier);
$mailer(40, 240, '2026-10-01', '10000', 'T1'); $mailer(40, 241, '2026-10-20', '20000', 'T2');
echo "UPDATE #TblMarketing SET Update_Date='2026-10-21' WHERE Drop_Name='fixture-240';\n";
$mailer(41, 242, '2026-10-20', '0', 'T2'); $mailer(41, 243, '2026-10-20', null, 'T3');
$mailer(42, 244, '2026-10-20', '0', 'T2'); $mailer(42, 245, '2026-10-20', null, 'Tier2');
$tu(43, 246, '2026-10-20', '10000', 'T1'); $tu(43, 247, '2026-10-20', '10000', null);
$tu(44, 248, null, '10000', 'T1'); $tu(44, 249, '2026-10-20', '10000', 'T1');
$mailer(45, 250, null, '0', 'T9'); $mailer(45, 251, '2026-10-01', '10000', 'T1');
$tu(46, 252, '2026-10-20'); $mailer(46, 252, '2026-10-01', '10000', 'T1');
echo "UPDATE #TblTUData SET ncoaAddr_frstNme='Wrong' WHERE PK=252;\n";
$tu(47, 253, '2026-10-20'); $mailer(47, 253, '2026-10-01', '10000', 'T1');
echo "UPDATE #TblTUData SET ncoaAddr_addrLine1Txt='Different address' WHERE PK=253;\n";
$tu(48, 254, '2026-10-20'); $mailer(48, 254, '2026-10-01', '10000', 'T1');
echo "UPDATE #TblTUData SET ncoaAddr_zipCde='99999' WHERE PK=254;\n";
$mailer(49, 255, '2026-10-20', '10000', 'T1');
echo "UPDATE #TblMailers SET Client='Different Person' WHERE PK=255;\n";
$tu(50, 256, '2026-10-20');
echo "UPDATE #TblProspectsExport SET Request_ID='ffffffff-ffff-ffff-ffff-ffffffffffff' WHERE PK=50;\n";

$sql = \Cmd\Reports\Services\SmsCurrentDebtSql::resolve('Send_Date');
foreach (['TblProspectsExport', 'TblTUData', 'TblMailers', 'TblMarketing'] as $table) {
    $sql = preg_replace("/(?<!['#])\\b{$table}\\b/", '#'.$table, $sql);
}
echo str_replace('?', $quote($request), $sql).";\n";
$expected = [1 => ['TblTUData', 900000, '11000', 'T2'], 2 => ['TblTUData', 1, '11000', 'T2'],
    3 => ['TblTUData', 3, '10000', 'T2'], 4 => ['TblMailers', 4, '11000', 'T3'],
    5 => ['TblTUData', 5, '11000', 'T2'], 6 => ['TblTUData', 6, '11000', 'T2'],
    9 => ['TblTUData', 9, '11000', 'T2'], 12 => ['TblTUData', 12, '11000', 'T2'],
    13 => ['TblMailers', 13, '20000', 'T4'],
    14 => ['TblTUData', 42, '7000', 'T1'], 15 => ['TblTUData', 15, '1000', 'T9'],
    20 => ['TblTUData', 21, '10000', 'T1'], 22 => ['TblMailers', 102, '10000', 'T1'],
    23 => ['TblMailers', 104, '20000', 'T4'], 25 => ['TblMailers', 108, '14000', 'T2'],
    28 => ['Tier', 113, '73000', 'T8'], 40 => ['TblMailers', 241, '20000', 'T2'],
    42 => ['Tier', 245, '14000', 'T2'], 45 => ['TblMailers', 251, '10000', 'T1'],
    46 => ['TblMailers', 252, '10000', 'T1'], 47 => ['TblMailers', 253, '10000', 'T1'],
    48 => ['TblMailers', 254, '10000', 'T1']];
$fallback = [10000, 14000, 17000, 23000, 31000, 43000, 58000, 73000, 85000];
foreach ($fallback as $offset => $debt) $expected[31 + $offset] = ['Tier', 201 + $offset, (string) $debt, 'T'.($offset + 1)];
foreach (range(1, 50) as $id) $insert('Expected', [$id, ...($expected[$id] ?? [null, null, null, null])]);
$assert = <<<'SQL'
IF EXISTS(SELECT PK,Debt_Source,Debt_Source_PK,Debt_Amount,Debt_Tier FROM #TblProspectsExport
 EXCEPT SELECT PK,Source,Source_PK,Debt,Tier FROM #Expected)
 OR EXISTS(SELECT PK,Source,Source_PK,Debt,Tier FROM #Expected
 EXCEPT SELECT PK,Debt_Source,Debt_Source_PK,Debt_Amount,Debt_Tier FROM #TblProspectsExport)
 BEGIN
 SELECT PK,Debt_Source,Debt_Source_PK,Debt_Amount,Debt_Tier FROM #TblProspectsExport
 EXCEPT SELECT PK,Source,Source_PK,Debt,Tier FROM #Expected;
 THROW 51000,'Ordered-source debt fixture failed.',1;
 END;
SQL;
echo $assert;
echo "\nUPDATE #TblTUData SET derivedFields1_unsecdebtbalance=50000,Tier_Tag='T9' WHERE PK=900000;\n";
echo "UPDATE #TblMailers SET Debt_Amount=50000,Debt_Tier='T9' WHERE PK=13;\n";
echo str_replace('?', $quote($request), $sql).";\n";
echo $assert;
echo "\nSELECT 'PASS: ordered-source SQL cases, held exceptions and frozen replay' AS Result,COUNT(*) AS Cases FROM #Expected;\n";
