<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Repositories\SmsProspectLoadRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Loads TblProspects from TblMailersUniqueEnriched, newest drop first, then marks the phones in
 * earlier sent SMS files as texted. Start with --dry-run; the real run is safe to stop and repeat.
 */
class LoadSmsProspects extends Command
{
    protected $signature = 'sms:load-prospects
        {--dry-run : Count only; change nothing}
        {--drop=* : Only these drops (default: every enriched drop)}
        {--limit=0 : Stop after this many drops (0 = all)}
        {--texted=* : A sent SMS export CSV; every phone in it counts as texted on the row\'s "send date"}
        {--skip-drops : Only mark the --texted files}';

    protected $description = 'Fill TblProspects from TblMailersUniqueEnriched and mark earlier SMS sends as texted.';

    public function handle(SmsProspectLoadRepository $load): int
    {
        if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasTable('TblProspects')) {
            $this->error('TblProspects does not exist yet. Run the SMS prospects migration first.');
            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) $this->warn('[DRY RUN] Nothing will be changed. "new" counts people not in TblProspects yet, so across drops it can count someone twice.');
        $paths = (array) $this->option('texted');
        foreach ($paths as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                $this->error("Cannot read {$path}.");
                return self::FAILURE;
            }
        }

        $totals = ['drops' => 0, 'people' => 0, 'added' => 0, 'filled' => 0, 'texted' => 0];
        try {
            if (! $this->option('skip-drops')) {
                $drops = $load->drops((array) $this->option('drop'));
                $limit = max(0, (int) $this->option('limit'));
                if ($limit > 0) $drops = array_slice($drops, 0, $limit);
                $this->info('Drops to load: '.number_format(count($drops)).' (newest first).');
                $started = microtime(true);
                foreach ($drops as $i => $drop) {
                    $result = $load->loadDrop((string) $drop->Drop_Name, $drop->Send_Date, $dryRun);
                    $totals['drops']++;
                    foreach (['people', 'added', 'filled'] as $key) $totals[$key] += (int) $result[$key];
                    $this->line(sprintf('%5d/%d  %-16s %s  %s%8s new  %6s filled  (%ds)', $i + 1, count($drops),
                        $drop->Drop_Name, $drop->Send_Date ?? 'no date   ',
                        $result['people'] === null ? '' : sprintf('%8s people  ', number_format($result['people'])),
                        number_format($result['added']), number_format($result['filled']), (int) (microtime(true) - $started)));
                }
            }
            foreach ($paths as $path) {
                [$phones, $marked] = $this->markTextedFile($load, $path, $dryRun);
                $totals['texted'] += $marked;
                $this->line(sprintf('%s: %s phones, %s prospects %s.', basename($path), number_format($phones),
                    number_format($marked), $dryRun ? 'would be marked as texted (about)' : 'marked as texted'));
            }
        } catch (\Throwable $e) {
            $this->error('sms:load-prospects stopped: '.$e->getMessage());
            Log::error('sms:load-prospects failed', ['exception' => $e]);
            return self::FAILURE;
        }

        $this->info(sprintf('%s %s drops, %s%s new, %s filled, %s marked as texted.', $dryRun ? '[DRY RUN]' : 'Done:',
            number_format($totals['drops']), $dryRun ? number_format($totals['people']).' people, ' : '', number_format($totals['added']),
            number_format($totals['filled']), number_format($totals['texted'])));
        if (! $dryRun) Log::info('sms:load-prospects finished', $totals);

        return self::SUCCESS;
    }

    /**
     * Streams a sent export file (header: First name, address, debt load, phone1..phone5, send date)
     * and marks its phones as texted on each row's send date, 1,000 at a time.
     *
     * @return array{0:int, 1:int} phones read, prospects marked
     */
    private function markTextedFile(SmsProspectLoadRepository $load, string $path, bool $dryRun): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException("Cannot read {$path}.");
        $phones = 0;
        $marked = 0;
        $pending = [];
        $flush = function (string $date) use (&$pending, &$marked, $load, $dryRun): void {
            if (($pending[$date] ?? []) === []) return;
            // Keys carry a 'p' prefix so PHP keeps them as strings, not integers.
            $marked += $load->markTexted(array_map(fn ($key) => substr($key, 1), array_keys($pending[$date])), $date, $dryRun);
            $pending[$date] = [];
        };
        try {
            $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\r\n\u{FEFF}")), fgetcsv($handle, null, ',', '"', '') ?: []);
            $phoneColumns = array_keys(array_filter($header, fn ($h) => preg_match('/^phone[1-5]$/', $h)));
            $dateColumn = array_search('send date', $header, true);
            if ($phoneColumns === [] || $dateColumn === false) throw new RuntimeException("{$path} has no phone1..phone5 or send date columns.");
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $date = trim((string) ($row[$dateColumn] ?? ''));
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
                foreach ($phoneColumns as $column) {
                    $digits = preg_replace('/\D/', '', (string) ($row[$column] ?? ''));
                    if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
                    if (strlen($digits) !== 10) continue;
                    $phones++;
                    $pending[$date]['p'.$digits] = true;
                    if (count($pending[$date]) >= 1000) $flush($date);
                }
            }
            foreach (array_keys($pending) as $date) $flush($date);
        } finally {
            fclose($handle);
        }

        return [$phones, $marked];
    }
}
