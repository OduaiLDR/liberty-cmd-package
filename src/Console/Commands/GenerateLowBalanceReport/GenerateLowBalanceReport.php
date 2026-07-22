<?php

namespace Cmd\Reports\Console\Commands\GenerateLowBalanceReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Port of VBA GenerateLowBalanceAdvanceReport for LDR + PLAW.
 * One command → separate workbook + email per portal (NSF/Dropped pattern).
 *
 * Skipped (add later if needed):
 * - Selenium "Advance Funds" writes in DPP (Under 500 sheet)
 * - 1st-of-month client campaign emails on Shortfall
 * - LoadLowBalanceCIDs hardcoded from VBA; override via --advance-cids / LOW_BALANCE_ADVANCE_CIDS
 */
class GenerateLowBalanceReport extends Command
{
    protected $signature = 'Generate:low-balance-report
        {--advance-cids= : Comma-separated contact IDs marked Advance Required (both portals)}
        {--no-email : Build workbooks only, skip email}';

    protected $description = 'Generate LDR + PLAW Low Balance reports (Under 500 / 500+ / Shortfall) and email each separately.';

    /** @var list<array{env:string, source:string, company:string}> */
    private const PORTALS = [
        ['env' => 'ldr', 'source' => 'LDR', 'company' => 'LDR'],
        ['env' => 'plaw', 'source' => 'PLAW', 'company' => 'PLAW'],
    ];

    /** Hardcoded from VBA LoadLowBalanceCIDs (same list in lowbalanceldr.md + lowbalanceplaw.md). */
    /** @var list<string> */
    private const DEFAULT_ADVANCE_CIDS = [
        '770711975', '705699716', '701643455', '702797690', '704227316', '706810586',
        '706824992', '719831402', '725263691', '492409830', '560971015', '617589301',
        '655301699', '687228938', '548132263', '551099683', '569955748', '573559453',
        '617479981', '641741006', '648054212', '661468889', '676105139', '640084328',
        '661498148', '712144856', '566843161', '696477851', '703211588', '721556402',
        '727067531', '578824561', '663619178', '737499320', '738344783', '691271189',
        '716647325', '739820417', '693711119', '703154867', '703192742', '703212938',
        '706007648', '714514595', '506795671', '539904945', '542153353', '648828854',
        '681671006', '694650131', '705701510', '726761858', '728990210', '514500238',
        '544279642', '547314934', '574210354', '642075407', '659576900', '659793452',
        '662000081', '667253990', '678048656', '679716176', '573395506', '676348700',
        '625859759', '648920306', '654205784', '654315767', '690739595', '617369875',
        '650885483', '663686297', '676300400', '727108802', '687238388', '730893542',
        '730949258', '732338828', '732851453', '746310515', '747550394', '775917074',
        '693162857', '694657733', '701005286', '714969749', '634394246', '738378374',
        '756659882', '765769793', '696331127', '700531412', '700698383', '710001794',
        '712144088', '718757036', '506027047', '607648708', '669348854', '514591015',
        '525375268', '557482780', '560695708', '600593374', '605142109', '610012063',
        '628521667', '630629082', '655963091', '657506348', '672674120', '689716913',
        '678076928', '693578264', '691679630', '693538493', '715525442', '709129598',
        '635755979', '651513944', '661529522', '674990069', '655240391', '663095390',
        '689852249', '730911851', '731626961', '732862775', '737680010', '737910908',
        '763414778', '700126103', '708885140', '715962674', '727163381', '693069218',
        '713158526', '714956285', '502736104', '515078356', '566160496', '640116749',
        '668578403', '677556518', '716653640', '720622097', '729206147', '495793110',
        '525422353', '555616360', '570556309', '596465875', '606149710', '627904084',
        '630624819', '639452279', '642056075', '646875377', '681747692', '682688132',
        '573451189', '776686946', '630638904', '793879532', '693430640', '528720793',
        '539603431', '656381147', '656547440', '664252868', '684036620', '686109422',
        '690683615', '709616939', '550569073', '636597179', '668008007', '689984165',
        '737408438', '737500877', '737828999', '738665963', '743359724', '700646753',
        '727134578', '744460277', '709082801', '710481761', '713870447', '722725643',
        '547738549', '573651658', '596691142', '704905385', '705926903', '718406777',
        '506964886', '554008453', '570616711', '585015163', '612340795', '618546577',
        '659756171', '662021831', '665523044', '668571686', '669136067', '673590329',
        '673963532', '687261155', '687331670', '498164374', '640053836', '705773072',
        '711970955', '636113405', '718275911', '555354226', '645017324', '654207740',
        '655324298', '663276107', '676444433', '695164640', '636629438', '667810430',
        '651045860', '678138044', '746032604', '767566583', '767968463', '692914418',
        '693504560', '694170377', '695712620', '707816735', '753637949', '771490883',
        '775754453', '783037472', '704245424', '705988664', '707764991', '709137365',
        '719734409', '667445510', '667752614', '668194022', '701625881', '710170997',
        '538422322', '626364292', '643764101', '649353035', '653069510', '661755053',
        '663752072', '679817495', '687354317', '657490919', '661563248', '624402193',
        '704064977', '791070830', '718396754', '721891469', '572601901', '706690178',
        '715562939', '721879739', '644992451', '665629160', '675789041', '680174399',
        '725105660', '654387773', '687307883', '633580862', '661686797', '736522442',
        '737803034', '750654548', '781529447', '691666634', '693716603', '700766519',
        '761853902', '696451100', '701618936', '702560150', '705854810', '510844441',
        '518375206', '570367552', '575533609', '589004458', '618556219', '704279309',
        '709187453', '526722442', '547480963', '552591214', '575495410', '585811183',
        '623196580', '634365890', '640642721', '664246844', '674320445', '654320426',
        '678428192', '693718058', '702019340', '693559625', '514955434', '640257365',
        '653456429', '656564240', '673834256', '675716138', '676111007', '678045467',
        '690715574', '727106525', '557687260', '697224359', '496906494', '636077204',
        '642200564', '655988567', '650778359', '729354614', '732346793', '732780890',
        '738004166', '742629710', '751379675', '777807434', '692915372', '693023450',
        '693210587', '718456427', '648861689', '740653982', '763817198', '695080706',
        '700547237', '702538145', '719730545', '723059081', '541927522', '548205037',
        '555911593', '631486706', '653668610', '672752327', '716734277', '728572109',
        '556621900', '606705604', '607868320', '620626399', '630674658', '659222048',
        '661664618', '663958319', '678510170', '680018696', '610740328', '656561078',
        '657627662', '764977787', '693893963', '695233157', '702645245', '715508666',
        '727085183', '642134849', '672482159', '710035535', '561955570', '572241106',
        '609110917', '629511594', '665505143', '665909792', '667551524', '688978073',
        '669705506', '686496953', '712193243', '626610823', '578587309', '693855842',
        '588044821', '638564516', '560468140', '558029815', '649493381', '737552699',
        '525307585', '651351068', '651554756', '515262574', '658529150', '490720285',
        '506009458', '569788273', '557489209', '537386152', '706692281', '539914167',
        '703170596', '856657306', '617990065', '560586463',
    ];

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');

        $this->info('[INFO] Low Balance report (LDR + PLAW): starting.');

        $sqlConnector = null;
        if (! $this->option('no-email')) {
            try {
                $sqlConnector = $this->initializeSqlServerConnector();
            } catch (\Throwable $e) {
                $this->error('Failed to initialize SQL Server: ' . $e->getMessage());
                Log::error('GenerateLowBalanceReport: sql init failed', ['exception' => $e]);

                return Command::FAILURE;
            }
        } else {
            $this->warn('[WARN] --no-email set; workbooks kept under storage/app.');
        }

        $formatter = new Formatter();
        $advanceCids = $this->resolveAdvanceCids();
        $failed = 0;

        foreach (self::PORTALS as $portal) {
            try {
                $this->generateForPortal($portal, $formatter, $advanceCids, $sqlConnector);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$portal['source']} failed: " . $e->getMessage());
                Log::error('GenerateLowBalanceReport: portal failed', [
                    'portal' => $portal['source'],
                    'exception' => $e,
                ]);
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array{env:string, source:string, company:string}  $portal
     * @param  array<string, true>  $advanceCids
     */
    private function generateForPortal(
        array $portal,
        Formatter $formatter,
        array $advanceCids,
        ?DBConnector $sqlConnector
    ): void {
        $source = $portal['source'];
        $this->info("[INFO] === {$source} ===");

        $snowflake = DBConnector::fromEnvironment($portal['env']);
        $rows = $this->buildRows($snowflake);
        $this->info("[INFO] {$source} low-balance contacts: " . count($rows));

        foreach ($rows as &$row) {
            $row['advance_required'] = isset($advanceCids[(string) $row['contact_id']]);
        }
        unset($row);

        $sheets = $this->splitSheets($rows);
        $this->info(sprintf(
            '[INFO] %s sheets — Under 500: %d | 500+: %d | Shortfall: %d',
            $source,
            count($sheets['under_500']),
            count($sheets['over_500']),
            count($sheets['shortfall'])
        ));

        $result = $formatter->buildWorkbook($sheets, $source);
        $path = $result['path'];
        $this->info("[INFO] {$source} workbook: {$path}");

        if ($sqlConnector === null) {
            return;
        }

        $sent = $formatter->sendReport(
            $sqlConnector,
            $result['path'],
            $result['filename'],
            $source,
            $portal['company'],
            $this
        );

        if (! $sent) {
            throw new \RuntimeException("{$source} email failed. Workbook kept at: {$path}");
        }

        if (is_file($path)) {
            @unlink($path);
        }

        $this->info("[INFO] {$source} email sent.");
    }

    /**
     * @return list<array{
     *   contact_id:int|string,
     *   current_balance:float,
     *   low_balance:float,
     *   low_balance_date:string,
     *   nsf_count:int,
     *   advance_required?:bool
     * }>
     */
    private function buildRows(DBConnector $snowflake): array
    {
        $today = Carbon::today();
        $horizon = $today->copy()->addDays(14)->toDateString();
        $nsfSince = $today->copy()->subMonthsNoOverflow(5)->toDateString();

        $balances = $this->fetchLatestBalances($snowflake);
        $this->info('[INFO] Latest balances: ' . count($balances));

        $txns = $this->fetchOpenTransactions($snowflake, $horizon);
        $this->info('[INFO] Open transactions (process < today+14): ' . count($txns));

        $nsfCounts = $this->fetchNsfCounts($snowflake, $nsfSince);

        $byContact = [];
        foreach ($txns as $t) {
            $cid = (string) ($t['CONTACT_ID'] ?? '');
            if ($cid === '') {
                continue;
            }

            $type = strtoupper(trim((string) ($t['TRANS_TYPE'] ?? '')));
            $amount = (float) ($t['AMOUNT'] ?? 0);
            $processDate = $this->parseDate($t['PROCESS_DATE'] ?? null);
            if ($processDate === null) {
                continue;
            }

            if ($type === 'D') {
                $processDate = $this->addBusinessDays($processDate, 3);
            }

            $byContact[$cid][] = [
                'type' => $type,
                'amount' => $amount,
                'process_date' => $processDate,
                'sort_order' => $type === 'D' ? 1 : 2,
            ];
        }

        $rows = [];
        foreach ($balances as $cid => $currentBalance) {
            $cid = (string) $cid;
            $txList = $byContact[$cid] ?? [];
            if ($txList === []) {
                continue;
            }

            usort($txList, static function (array $a, array $b): int {
                $d = $a['process_date']->timestamp <=> $b['process_date']->timestamp;
                if ($d !== 0) {
                    return $d;
                }

                return $a['sort_order'] <=> $b['sort_order'];
            });

            $hasSettlement = false;
            foreach ($txList as $t) {
                if ($t['type'] === 'S') {
                    $hasSettlement = true;
                    break;
                }
            }
            if (! $hasSettlement) {
                continue;
            }

            $running = (float) $currentBalance;
            $wentNegative = false;
            $finalRunning = $running;
            $finalDate = null;

            foreach ($txList as $t) {
                $sign = in_array($t['type'], ['D', 'SA', 'T'], true) ? 1.0 : -1.0;
                $running = round($running + ($sign * $t['amount']), 2);
                $finalRunning = $running;
                $finalDate = $t['process_date']->toDateString();
                if ($running < 0) {
                    $wentNegative = true;
                }
            }

            if (! $wentNegative || $finalRunning >= 0 || $finalDate === null) {
                continue;
            }

            $rows[] = [
                'contact_id' => $cid,
                'current_balance' => (float) $currentBalance,
                'low_balance' => (float) $finalRunning,
                'low_balance_date' => $finalDate,
                'nsf_count' => (int) ($nsfCounts[$cid] ?? 0),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['low_balance'] <=> $a['low_balance']);

        return $rows;
    }

    /** @return array<string, float> */
    private function fetchLatestBalances(DBConnector $snowflake): array
    {
        $sql = <<<'SQL'
SELECT CONTACT_ID, CURRENT_BALANCE
FROM (
    SELECT
        b.CONTACT_ID,
        b."CURRENT" AS CURRENT_BALANCE,
        ROW_NUMBER() OVER (PARTITION BY b.CONTACT_ID ORDER BY b.STAMP DESC) AS N
    FROM CONTACT_BALANCES AS b
    LEFT JOIN CONTACTS AS c ON b.CONTACT_ID = c.ID
    WHERE c.ENROLLED = 1
      AND c.DROPPED = 0
      AND c.GRADUATED = 0
      AND c.DEL = 0
)
WHERE N = 1
SQL;

        $out = [];
        foreach ($snowflake->query($sql)['data'] ?? [] as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid !== '') {
                $out[$cid] = (float) ($row['CURRENT_BALANCE'] ?? 0);
            }
        }

        return $out;
    }

    private function fetchOpenTransactions(DBConnector $snowflake, string $beforeDate): array
    {
        $before = $this->esc($beforeDate);
        $sql = "
SELECT
    t.CONTACT_ID,
    t.TRANS_TYPE,
    t.AMOUNT,
    TO_VARCHAR(t.PROCESS_DATE::date, 'YYYY-MM-DD') AS PROCESS_DATE
FROM TRANSACTIONS t
INNER JOIN CONTACTS c ON c.ID = t.CONTACT_ID
WHERE t.PROCESS_DATE < '{$before}'
  AND t.CLEARED_DATE IS NULL
  AND t.RETURNED_DATE IS NULL
  AND t.CANCELLED = 0
  AND t.STATUS NOT IN (3, 9, 13, 15, 20, 76)
  AND c.ENROLLED = 1
  AND c.DROPPED = 0
  AND c.GRADUATED = 0
  AND c.DEL = 0
";

        return $snowflake->query($sql)['data'] ?? [];
    }

    /** @return array<string, int> */
    private function fetchNsfCounts(DBConnector $snowflake, string $since): array
    {
        $sinceEsc = $this->esc($since);
        $sql = "
SELECT t.CONTACT_ID, COUNT(*) AS NSF_COUNT
FROM TRANSACTIONS t
INNER JOIN CONTACTS c ON c.ID = t.CONTACT_ID
WHERE t.TRANS_TYPE = 'D'
  AND t.RETURNED_DATE >= '{$sinceEsc}'
  AND c.ENROLLED = 1
  AND c.DROPPED = 0
  AND c.GRADUATED = 0
  AND c.DEL = 0
GROUP BY t.CONTACT_ID
";

        $out = [];
        foreach ($snowflake->query($sql)['data'] ?? [] as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid !== '') {
                $out[$cid] = (int) ($row['NSF_COUNT'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * @param  list<array{contact_id:int|string,current_balance:float,low_balance:float,low_balance_date:string,nsf_count:int,advance_required?:bool}>  $rows
     * @return array{under_500:list<array>,over_500:list<array>,shortfall:list<array>}
     */
    private function splitSheets(array $rows): array
    {
        $under = [];
        $over = [];
        $shortfall = [];

        foreach ($rows as $row) {
            $low = (float) $row['low_balance'];
            $advance = (bool) ($row['advance_required'] ?? false);
            $nsf = (int) $row['nsf_count'];

            if ($advance && $low > -500 && $low < 0 && $nsf === 0) {
                $under[] = $row;
                continue;
            }
            if ($advance && $low <= -500 && $nsf === 0) {
                $over[] = $row;
                continue;
            }
            if ($low < 0 && ! ($advance && $nsf === 0)) {
                $shortfall[] = $row;
            }
        }

        return [
            'under_500' => $under,
            'over_500' => $over,
            'shortfall' => $shortfall,
        ];
    }

    /** @return array<string, true> */
    private function resolveAdvanceCids(): array
    {
        $raw = (string) ($this->option('advance-cids') ?: env('LOW_BALANCE_ADVANCE_CIDS', ''));
        $set = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $set[$part] = true;
            }
        }

        if ($set === []) {
            foreach (self::DEFAULT_ADVANCE_CIDS as $cid) {
                $set[(string) $cid] = true;
            }
            $this->info('[INFO] Advance CID marks: ' . count($set) . ' (hardcoded VBA LoadLowBalanceCIDs)');
        } else {
            $this->info('[INFO] Advance CID marks: ' . count($set) . ' (CLI/env override)');
        }

        return $set;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $d = $date->copy();
        $added = 0;
        while ($added < $days) {
            $d->addDay();
            if ($d->isWeekend() || $this->isHoliday($d)) {
                continue;
            }
            $added++;
        }

        return $d;
    }

    private function isHoliday(Carbon $date): bool
    {
        $y = $date->year;
        $md = $date->format('m-d');
        if (in_array($md, ['01-01', '06-19', '07-04', '11-11', '12-25'], true)) {
            return true;
        }

        foreach ([
            $this->nthWeekdayOfMonth($y, 1, Carbon::MONDAY, 3),
            $this->nthWeekdayOfMonth($y, 2, Carbon::MONDAY, 3),
            $this->lastWeekdayOfMonth($y, 5, Carbon::MONDAY),
            $this->nthWeekdayOfMonth($y, 9, Carbon::MONDAY, 1),
            $this->nthWeekdayOfMonth($y, 10, Carbon::MONDAY, 2),
            $this->nthWeekdayOfMonth($y, 11, Carbon::THURSDAY, 4),
        ] as $h) {
            if ($h->isSameDay($date)) {
                return true;
            }
        }

        return false;
    }

    private function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $nth): Carbon
    {
        $d = Carbon::create($year, $month, 1)->startOfDay();
        while ($d->dayOfWeek !== $weekday) {
            $d->addDay();
        }
        $d->addWeeks($nth - 1);

        return $d;
    }

    private function lastWeekdayOfMonth(int $year, int $month, int $weekday): Carbon
    {
        $d = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
        while ($d->dayOfWeek !== $weekday) {
            $d->subDay();
        }

        return $d;
    }

    protected function initializeSqlServerConnector(): DBConnector
    {
        $candidates = ['ldr', 'plaw', 'production', 'sandbox'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                $connector = DBConnector::fromEnvironment($env);
                $connector->initializeSqlServer();

                return $connector;
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize SQL Server connector. Tried: ' . implode('; ', $errors));
    }

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
