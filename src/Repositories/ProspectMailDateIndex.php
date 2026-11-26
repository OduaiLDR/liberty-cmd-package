<?php

declare(strict_types=1);

namespace Cmd\Reports\Repositories;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use LogicException;

final class ProspectMailDateIndex
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public static function history(ConnectionInterface $db, Carbon $asOf, bool $boundedRefresh = false): Builder
    {
        $query = $db->table('TblMailers as m');
        if ($boundedRefresh && $db->getDriverName() === 'sqlsrv') {
            // The live plans scan TblMailers. Each refresh page has a bounded set of identities to seek.
            $query->fromRaw('TblMailers as m WITH (FORCESEEK)');
        }

        return $query->join('TblMarketing as d', 'd.Drop_Name', '=', 'm.Drop_Name')
            ->whereColumn('m.Client', 'p.Client')
            ->whereColumn('m.Address', 'p.Address')
            ->where('m.Client', '<>', '')
            ->where('m.Address', '<>', '')
            ->whereRaw('m.SMS_Zip5 = SUBSTRING(p.Zip, 1, 5)')
            ->where('d.Send_Date', '<=', $asOf->toDateString());
    }

    public function installed(): bool
    {
        return $this->db->getSchemaBuilder()->hasTable('TblProspectMailDates');
    }

    public function publishedBuild(Carbon $asOf): ?int
    {
        if (! $this->installed()) {
            return null;
        }

        return $this->publishedBuildFor($this->sources($asOf)['version']);
    }

    /** @return array{build: int, cursor: int, published: bool} */
    public function refresh(Carbon $asOf, int $batchSize, int $batches, bool $rebuild = false): array
    {
        $sources = $this->sources($asOf);
        $version = $sources['version'];
        $now = now('UTC');
        $retired = $this->builds()->whereNull('Retired_At');
        if ($rebuild) {
            $this->builds()->where('Reusable', true)->update(['Reusable' => false]);
        } else {
            $retired->where('Source_Version', '<>', $version);
        }
        $retired->update(['Retired_At' => $now]);
        $this->prune($now);

        $published = $this->publishedBuildFor($version);
        if ($published !== null) {
            return ['build' => $published, 'cursor' => $sources['prospect_max'], 'published' => true];
        }

        $build = $this->builds()->where('Source_Version', $version)->whereNull('Retired_At')->first();
        $previousId = $build->Previous_Build_ID ?? null;
        if ($build === null && ! $rebuild) {
            $previous = $this->builds()->whereNotNull('Published_At')->where('Reusable', true)->latest('id')->first();
            if ($previous !== null && $this->changesSince($previous, $sources['snapshot']) !== null) {
                $previousId = (int) $previous->id;
            }
        }
        $cursor = (int) ($build->Cursor_PK ?? 0);
        $buildId = (int) ($build->id ?? $this->builds()->insertGetId([
            'Source_Version' => $version,
            'Source_Snapshot' => json_encode($sources['snapshot'], JSON_THROW_ON_ERROR),
            'Previous_Build_ID' => $previousId,
            'Prospect_Max_PK' => $sources['prospect_max'],
        ]));
        $high = (int) ($build->Prospect_Max_PK ?? $sources['prospect_max']);
        $mailerHigh = $sources['snapshot']['mailer_max'];
        $previous = $previousId === null ? null : $this->builds()->where('id', $previousId)->first();
        $changes = $previous === null ? null : $this->changesSince($previous, $sources['snapshot']);
        if ($previousId !== null && $changes === null) {
            throw new LogicException('The previous prospect mail-date build is unavailable. Restart the refresh with --rebuild.');
        }

        for ($batch = 0; $cursor < $high && $batch < $batches; $batch++) {
            $last = $this->db->table('TblProspects')->where('PK', '>', $cursor)->where('PK', '<=', $high)
                ->orderBy('PK')->limit($batchSize)->pluck('PK')->last();
            $next = $last === null ? $high : (int) $last;
            $this->db->transaction(function () use ($asOf, $buildId, $cursor, $next, $mailerHigh, $changes): void {
                $this->db->table('TblProspectMailDates')->insertUsing(
                    ['Build_ID', 'Prospect_PK', 'Mailed_On'],
                    $this->batchDates($asOf, $buildId, $cursor, $next, $mailerHigh, $changes),
                );
                $this->builds()->where('id', $buildId)->update(['Cursor_PK' => $next]);
            });
            $cursor = $next;
        }

        if ($cursor < $high) {
            return ['build' => $buildId, 'cursor' => $cursor, 'published' => false];
        }

        $current = $this->sources($asOf)['version'] === $version;
        $this->builds()->where('id', $buildId)->update([
            $current ? 'Published_At' : 'Retired_At' => now('UTC'),
        ]);

        return ['build' => $buildId, 'cursor' => $cursor, 'published' => $current];
    }

    private function batchDates(Carbon $asOf, int $buildId, int $after, int $through, int $mailerHigh, ?array $changes): Builder
    {
        $history = self::history($this->db, $asOf, boundedRefresh: true)->where('m.PK', '<=', $mailerHigh)
            ->selectRaw('MAX(d.Send_Date)');
        $dates = $this->db->table('TblProspects as p')->selectRaw('? as Build_ID, p.PK as Prospect_PK', [$buildId])
            ->where('p.PK', '>', $after)->where('p.PK', '<=', $through);

        if ($changes === null || $after >= $changes['prospect_max']) {
            $dates->selectSub($history, 'Mailed_On');

            return $this->db->query()->fromSub($dates, 'dates')->whereNotNull('Mailed_On');
        }

        $dates->leftJoin('TblProspectMailDates as previous', static function (JoinClause $join) use ($changes): void {
            $join->on('previous.Prospect_PK', '=', 'p.PK')->where('previous.Build_ID', $changes['build']);
        })->addSelect('previous.Mailed_On as Previous_Date');

        if ($through <= $changes['prospect_max'] && ! $changes['has_new_mailers'] && $changes['new_drops'] === []) {
            return $dates->whereNotNull('previous.Mailed_On');
        }

        $history->where(static function (Builder $delta) use ($changes): void {
            $delta->where('p.PK', '>', $changes['prospect_max'])
                ->orWhere('m.PK', '>', $changes['mailer_max']);
            if ($changes['new_drops'] !== []) {
                $delta->orWhereIn('m.Drop_Name', $changes['new_drops']);
            }
        });
        $dates->selectSub($history, 'New_Date');

        return $this->db->query()->fromSub($dates, 'dates')
            ->select('Build_ID', 'Prospect_PK')
            ->selectRaw('CASE WHEN Previous_Date IS NULL OR New_Date > Previous_Date THEN New_Date ELSE Previous_Date END AS Mailed_On')
            ->where(static fn (Builder $date) => $date->whereNotNull('New_Date')->orWhereNotNull('Previous_Date'));
    }

    /** In-place drop corrections can lower a date and therefore require a full build. */
    private function changesSince(object $previous, array $snapshot): ?array
    {
        $old = json_decode($previous->Source_Snapshot, true, flags: JSON_THROW_ON_ERROR);
        if ($old['objects'] !== $snapshot['objects']
            || $old['prospect_max'] > $snapshot['prospect_max'] || $old['mailer_max'] > $snapshot['mailer_max']
            || array_diff_key($old['drops'], $snapshot['drops']) !== []) {
            return null;
        }

        $newDrops = array_values(array_unique(array_diff_key($snapshot['drops'], $old['drops'])));
        if (count($newDrops) > 1000) {
            return null;
        }

        return [
            'build' => (int) $previous->id,
            'prospect_max' => $old['prospect_max'],
            'mailer_max' => $old['mailer_max'],
            'has_new_mailers' => $old['mailer_max'] < $snapshot['mailer_max'],
            'new_drops' => $newDrops,
        ];
    }

    private function publishedBuildFor(string $version): ?int
    {
        $build = $this->builds()->where('Source_Version', $version)
            ->where('Reusable', true)->whereNotNull('Published_At')->whereNull('Retired_At')->max('id');

        return $build === null ? null : (int) $build;
    }

    /** @return array{version: string, prospect_max: int, snapshot: array} */
    private function sources(Carbon $asOf): array
    {
        $marks = $this->db->query()
            ->selectSub($this->db->table('TblProspects')->selectRaw('MAX(PK)'), 'prospects_max')
            ->selectSub($this->db->table('TblMailers')->selectRaw('MAX(PK)'), 'mailers_max');
        if ($this->db->getDriverName() === 'sqlsrv') {
            $marks->selectRaw("OBJECT_ID('TblProspects') AS prospects_object, OBJECT_ID('TblMailers') AS mailers_object");
        }
        $sourceMarks = $marks->get()->sole();
        $drops = $this->db->table('TblMarketing')->where('Send_Date', '<=', $asOf->toDateString())
            ->whereNotNull('Drop_Name')
            ->distinct()->orderBy('Drop_Name')->orderBy('Send_Date')->get(['Drop_Name', 'Send_Date']);
        $activeDrops = [];
        foreach ($drops as $drop) {
            $key = json_encode([$drop->Drop_Name, $drop->Send_Date], JSON_THROW_ON_ERROR);
            $activeDrops[$key] = $drop->Drop_Name;
        }
        $snapshot = [
            'objects' => [$sourceMarks->prospects_object ?? null, $sourceMarks->mailers_object ?? null],
            'prospect_max' => (int) $sourceMarks->prospects_max,
            'mailer_max' => (int) $sourceMarks->mailers_max,
            'drops' => $activeDrops,
        ];

        return [
            'version' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'prospect_max' => $snapshot['prospect_max'],
            'snapshot' => $snapshot,
        ];
    }

    private function builds(): Builder
    {
        return $this->db->table('TblProspectMailDateBuilds');
    }

    private function prune(Carbon $now): void
    {
        // Keep a retired build until any export that started with it has finished.
        $inUse = $this->builds()->select('Previous_Build_ID')->whereNotNull('Previous_Build_ID')
            ->whereNull('Published_At')->whereNull('Retired_At');
        $latest = $this->builds()->whereNotNull('Published_At')->where('Reusable', true)->max('id');
        $buildId = $this->builds()->where('Retired_At', '<=', $now->copy()->subHours(3))
            ->where('id', '<>', (int) $latest)->whereNotIn('id', $inUse)->orderBy('id')->value('id');
        if ($buildId === null) {
            return;
        }
        $dates = $this->db->table('TblProspectMailDates')->where('Build_ID', $buildId);
        for ($batch = 0; $batch < 32; $batch++) {
            $page = (clone $dates)->select('Prospect_PK')->orderBy('Prospect_PK')->limit(2000);
            if ((clone $dates)->whereIn('Prospect_PK', $page)->delete() === 0) {
                $this->builds()->where('id', $buildId)->delete();

                return;
            }
        }
    }
}
