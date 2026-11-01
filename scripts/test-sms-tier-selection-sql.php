<?php

declare(strict_types=1);

// Emits connection-local #temp fixtures only. No .env, database, application or network access.
// Execute only in LOCAL tempdb using sqlcmd -b. This does not establish production performance.
if ($argc !== 2 || $argv[1] !== '--emit') throw new RuntimeException('Use --emit for the local SQL Server temporary-table fixture.');
require_once dirname(__DIR__).'/src/Services/SmsProspectValues.php';
require_once dirname(__DIR__).'/src/Services/SmsRunOptions.php';
require_once dirname(__DIR__).'/src/Services/SmsCurrentDebtSql.php';
require_once dirname(__DIR__).'/src/Services/SmsTierSelectionSql.php';

echo <<<'SQL'
SET NOCOUNT ON;
SET XACT_ABORT OFF;
CREATE TABLE #TblProspects(PK int PRIMARY KEY,Phone varchar(10),Client varchar(150),Address varchar(200),City varchar(100),State char(2),Zip varchar(10),Email varchar(254),Creation_Date date,Last_SMS_Date date,Contacted bit,Opt_Out bit,Invalid_Phone bit);
CREATE TABLE #TblTUData(PK int PRIMARY KEY,Creation_Date date,ncoaAddr_frstNme varchar(100),ncoaAddr_lastNme varchar(100),ncoaAddr_addrLine1Txt varchar(255),ncoaAddr_zipCde varchar(10),derivedFields1_unsecdebtbalance decimal(18,2),Tier_Tag varchar(30));
CREATE TABLE #TblMailers(PK int PRIMARY KEY,Client varchar(150),Address varchar(200),Zip varchar(10),Drop_Name varchar(50),Debt_Amount decimal(12,2),Debt_Tier varchar(30));
CREATE TABLE #TblMarketing(Drop_Name varchar(50),Send_Date date);
CREATE TABLE #TblSMSSuppressions(Phone varchar(10),Opt_Out bit,Invalid_Phone bit);
CREATE TABLE #TblPhoneNumbers(Phone varchar(11));
CREATE TABLE #TblProspectsExport(PK int IDENTITY PRIMARY KEY,Request_ID uniqueidentifier,Prospect_PK int,Phone varchar(10),Debt_Tier varchar(30),Debt_Amount decimal(12,2),Debt_Source varchar(20),Debt_Source_PK bigint,Client varchar(150),Address varchar(200),City varchar(100),State char(2),Zip varchar(10),Email varchar(254),Was_Previously_Texted bit,Last_SMS_Date_At_Selection date,UNIQUE(Request_ID,Prospect_PK));
CREATE TABLE #Expected(Request_ID uniqueidentifier,Prospect_PK int,Position int,Debt_Source varchar(20),Source_PK int,Debt decimal(12,2),Tier varchar(30),PRIMARY KEY(Request_ID,Prospect_PK));

SQL;
$quote = static fn ($value): string => $value === null ? 'NULL' : "'".str_replace("'", "''", (string) $value)."'";
$insert = static function (string $table, array $values) use ($quote): void {
    echo 'INSERT #'.$table.' VALUES('.implode(',',array_map($quote,$values)).");\n";
};
$person = static function (int $id, ?string $created = '2026-10-18', ?string $last = null, ?string $phone = null, string $zip = '00123', string $state = 'CA', int $contacted = 0, int $optOut = 0, int $invalid = 0) use ($insert): void {
    $insert('TblProspects',[$id,$phone ?? '0202555'.sprintf('%03d',$id),'Synthetic Person'.$id,$id.' Fixture St','Fixture',$state,$zip,'fixture'.$id.'@example.invalid',$created,$last,$contacted,$optOut,$invalid]);
};
$tu = static function (int $id, int $pk, ?string $date = '2026-10-20', ?string $debt = '13500.01', ?string $tier = 'Tier2') use ($insert): void {
    $insert('TblTUData',[$pk,$date,'Synthetic','Person'.$id,$id.' Fixture St','00123-4321',$debt,$tier]);
};
$mail = static function (int $id, int $pk, ?string $date = '2026-10-20', ?string $debt = '13500.01', ?string $tier = 'T2') use ($insert): void {
    $drop='fixture-'.$pk;
    $insert('TblMailers',[$pk,'Synthetic Person'.$id,$id.' Fixture St','00123',$drop,$debt,$tier]);
    $insert('TblMarketing',[$drop,$date]);
};
$person(1,'2026-10-22'); $tu(1,1,tier:'T1'); // wrong tier must not consume TOP slots
$person(2,'2026-10-21'); $tu(2,2); $mail(2,200,'2026-10-22','85000','T9'); // TU wins
$person(3,'2026-10-22'); $tu(3,3,'2026-10-01'); $tu(3,300,'2026-10-21','0'); $mail(3,301); // invalid latest TU holds
$person(4,'2026-10-20'); $mail(4,4); $mail(4,400,'2026-10-21','0','T9'); // positive mailer wins
$person(5,'2026-10-19'); $mail(5,5,debt:'0'); // source-tier fallback 13500 -> 14000
$person(6,'2026-10-22'); $tu(6,6); $tu(6,600,debt:'17000',tier:'T3'); // conflicting TU tie
$person(7,'2026-10-22'); $tu(7,7,null); $mail(7,700); // null TU date prevents fallback
$person(8,'2026-10-21',phone:'0202555002'); $tu(8,8); // distinct PK sharing one phone
$person(9,'2026-10-22'); // unresolved source
$person(10,null); $tu(10,10); // null creation never-texted, before previous records
$person(11,'2020-01-01','2026-09-01'); $tu(11,11);
$person(12,'2026-10-22','2026-09-01'); $tu(12,12); // previous creation date must NOT reorder tied Last_SMS_Date
$person(13,'2026-10-22','2026-10-01'); $tu(13,13);
$person(14,'2026-10-22'); $mail(14,14); $mail(14,1400,debt:'17000',tier:'T3');
$person(15,'2026-10-22'); $mail(15,15,null);
$person(16,'2026-10-22'); $tu(16,16); $insert('TblSMSSuppressions',['0202555016',1,0]);
$person(17,'2026-10-22'); $tu(17,17); $insert('TblPhoneNumbers',['10202555017']);
$person(18,'2026-10-22',contacted:1); $tu(18,18);
$person(19,'2026-10-22',optOut:1); $tu(19,19);
$person(20,'2026-10-22',invalid:1); $tu(20,20);
$person(21,'2026-10-22',phone:'bad5555021'); $tu(21,21);
$person(22,'2026-10-17'); $tu(22,22,debt:'1000',tier:'T9'); // tier is source tier, not inferred from debt
$person(23,'2026-10-22'); $tu(23,23,debt:'9999999999.99');
$person(24,'2026-10-22'); $tu(24,24,tier:'unknown');
$person(25,zip:'00123-9999'); $tu(25,25);
$person(26); $tu(26,26); $tu(26,2600,tier:'T2'); // agreeing latest tie uses PK only for provenance
$person(27); $mail(27,27); $mail(27,2700,null,'0','T9'); // irrelevant unknown fallback date
$person(28,'2026-10-22'); $tu(28,28,null); $tu(28,2800); // unknown TU date in chosen stage holds
$person(29); $tu(29,29); $mail(29,2900); echo "UPDATE #TblTUData SET ncoaAddr_frstNme='Different' WHERE PK=29;\n";
$person(30); $tu(30,30); $mail(30,3000); echo "UPDATE #TblTUData SET ncoaAddr_zipCde='99999' WHERE PK=30;\n";
$person(31,'2026-10-22'); $mail(31,31,debt:'0',tier:'unknown');
$person(32,'2026-10-22',state:'NY'); $tu(32,32); // eligible binding precedes quantity/request/tier bindings
$person(41,'2026-10-22'); $tu(41,41,debt:'-1');
$person(42,'2026-10-22'); $mail(42,42,debt:'0',tier:'T1'); $mail(42,4200,debt:null,tier:'T2');
$person(43,'2026-10-22'); $tu(43,43,'2026-10-01'); $tu(43,4300,'2026-10-21',tier:null);
foreach (['TblProspects','TblTUData','TblMailers','TblMarketing','TblSMSSuppressions','TblPhoneNumbers'] as $table) echo "SELECT * INTO #Before{$table} FROM #{$table};\n";

$eligible = <<<'SQL'
SELECT p.PK,p.Phone,p.Client,p.Address,p.City,p.State,p.Zip,p.Email,p.Creation_Date,p.Last_SMS_Date
FROM TblProspects p WHERE p.State=? AND p.Contacted=0 AND p.Opt_Out=0 AND p.Invalid_Phone=0
AND DATALENGTH(p.Phone)=10 AND p.Phone NOT LIKE '%[^0-9]%'
AND NOT EXISTS(SELECT 1 FROM TblSMSSuppressions s WHERE s.Phone=p.Phone AND (s.Opt_Out=1 OR s.Invalid_Phone=1))
AND NOT EXISTS(SELECT 1 FROM TblPhoneNumbers c WHERE CASE WHEN LEN(c.Phone)=11 AND LEFT(c.Phone,1)='1' THEN SUBSTRING(c.Phone,2,10) ELSE c.Phone END=p.Phone)
SQL;
$render = static function (array $tiers, int $target, string $request) use ($eligible,$quote): string {
    $sql=\Cmd\Reports\Services\SmsTierSelectionSql::select($eligible,$tiers);
    foreach (['TblProspectsExport','TblProspects','TblTUData','TblMailers','TblMarketing','TblSMSSuppressions','TblPhoneNumbers'] as $table) $sql=preg_replace("/(?<!['#])\\b{$table}\\b/",'#'.$table,$sql);
    $bindings=['CA',$target,$request,...$tiers]; $offset=0;
    $sql=preg_replace_callback('/\?/',static function () use (&$offset,$bindings,$quote): string {
        if (! array_key_exists($offset,$bindings)) throw new RuntimeException('Fixture binding count mismatch.');
        $value = $bindings[$offset];
        // Match the repository's integer PDO binding for TOP, not a SQL string.
        return $offset++ === 1 ? (string) $value : $quote($value);
    },$sql);
    if ($offset!==count($bindings)) throw new RuntimeException('Fixture unused bindings.');
    return $sql.";\n";
};
$requests=[];
foreach (range(1,5) as $i) $requests[$i]='12345678-1234-4234-8234-'.sprintf('%012d',$i);
$cases=[1=>[['T2'],3,[2,8,4]],2=>[['T2'],100,[2,8,4,5,25,26,27,29,30,10,11,12,13]],
    3=>[['T9','T1'],100,[1,22]],4=>[['T8'],5,[]],5=>[\Cmd\Reports\Services\SmsRunOptions::TIERS,5,[1,2,8,4,5]]];
$source=[1=>['TblTUData',1,'14000','T1'],2=>['TblTUData',2,'14000','T2'],8=>['TblTUData',8,'14000','T2'],
    4=>['TblMailers',4,'14000','T2'],5=>['Tier',5,'14000','T2'],25=>['TblTUData',25,'14000','T2'],
    26=>['TblTUData',2600,'14000','T2'],27=>['TblMailers',27,'14000','T2'],29=>['TblMailers',2900,'14000','T2'],30=>['TblMailers',3000,'14000','T2'],
    10=>['TblTUData',10,'14000','T2'],11=>['TblTUData',11,'14000','T2'],12=>['TblTUData',12,'14000','T2'],13=>['TblTUData',13,'14000','T2'],22=>['TblTUData',22,'1000','T9']];
foreach ($cases as $case=>[$tiers,$target,$members]) {
    echo $render($tiers,$target,$requests[$case]);
    foreach ($members as $index=>$id) $insert('Expected',[$requests[$case],$id,$index+1,...$source[$id]]);
}
echo <<<'SQL'
IF EXISTS(SELECT Request_ID,Prospect_PK,Debt_Source,Debt_Source_PK,Debt_Amount,Debt_Tier FROM #TblProspectsExport
 EXCEPT SELECT Request_ID,Prospect_PK,Debt_Source,Source_PK,Debt,Tier FROM #Expected)
 OR EXISTS(SELECT Request_ID,Prospect_PK,Debt_Source,Source_PK,Debt,Tier FROM #Expected
 EXCEPT SELECT Request_ID,Prospect_PK,Debt_Source,Debt_Source_PK,Debt_Amount,Debt_Tier FROM #TblProspectsExport)
 THROW 51010,'Tier-filtered membership or priced provenance mismatch.',1;
IF EXISTS(SELECT 1 FROM (SELECT Request_ID,Prospect_PK,ROW_NUMBER() OVER(PARTITION BY Request_ID ORDER BY PK) AS Position FROM #TblProspectsExport) x
 JOIN #Expected e ON e.Request_ID=x.Request_ID AND e.Prospect_PK=x.Prospect_PK WHERE x.Position<>e.Position)
 THROW 51011,'Tier-filtered record priority mismatch.',1;
IF EXISTS(SELECT 1 FROM #TblProspectsExport x JOIN #TblProspects p ON p.PK=x.Prospect_PK
 WHERE x.Phone<>p.Phone OR x.Client<>p.Client OR x.Address<>p.Address OR x.Zip<>p.Zip OR x.City<>p.City OR x.State<>p.State OR x.Email<>p.Email
 OR x.Was_Previously_Texted<>CASE WHEN p.Last_SMS_Date IS NULL THEN 0 ELSE 1 END
 OR ISNULL(x.Last_SMS_Date_At_Selection,'19000101')<>ISNULL(p.Last_SMS_Date,'19000101'))
 THROW 51012,'Frozen identity/contact/composition snapshot mismatch.',1;

SQL;
foreach (['TblProspects','TblTUData','TblMailers','TblMarketing','TblSMSSuppressions','TblPhoneNumbers'] as $table) echo <<<SQL
IF EXISTS(SELECT * FROM #{$table} EXCEPT SELECT * FROM #Before{$table})
 OR EXISTS(SELECT * FROM #Before{$table} EXCEPT SELECT * FROM #{$table})
 OR (SELECT COUNT(*) FROM #{$table})<>(SELECT COUNT(*) FROM #Before{$table})
 THROW 51013,'Selector modified source/eligibility tables.',1;

SQL;
// There is deliberately no hidden replay/append policy inside the SQL builder.
echo "BEGIN TRY\n".$render(['T2'],3,$requests[1]);
echo <<<'SQL'
 THROW 51014,'Caller-owned replay guard was bypassed.',1;
END TRY
BEGIN CATCH
 IF ERROR_NUMBER() NOT IN (2601,2627) THROW;
END CATCH;
IF (SELECT COUNT(*) FROM #TblProspectsExport)<>(SELECT COUNT(*) FROM #Expected)
 THROW 51015,'Failed duplicate replay left partial membership.',1;
SELECT * INTO #FrozenSelection FROM #TblProspectsExport;
UPDATE #TblTUData SET derivedFields1_unsecdebtbalance=55000,Tier_Tag='T9' WHERE PK=2;

SQL;
echo 'IF NOT EXISTS(SELECT 1 FROM #TblProspectsExport WHERE Request_ID='.$quote($requests[1]).")\nBEGIN\n".$render(['T2'],3,$requests[1])."END;\n";
echo <<<'SQL'
IF EXISTS(SELECT * FROM #TblProspectsExport EXCEPT SELECT * FROM #FrozenSelection)
 OR EXISTS(SELECT * FROM #FrozenSelection EXCEPT SELECT * FROM #TblProspectsExport)
 THROW 51016,'Caller-guarded retry repriced frozen membership.',1;
SELECT 'PASS: tier-before-quantity SQL membership, ordering, source holds, snapshots and caller-owned replay' AS Result,
 (SELECT COUNT(*) FROM #TblProspects) AS Candidates, COUNT(*) AS Selected_Across_Five_Runs FROM #TblProspectsExport;
SQL;
