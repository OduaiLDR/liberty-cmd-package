<?php

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsDropPlanner;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MailDropExportRepository extends SqlSrvRepository
{
    protected function csvRecordLimit(): int
    {
        return 1000000;
    }

    protected function marketingDrops(): Builder
    {
        return $this->table('TblMarketing', 'm')
            ->select(['m.PK', 'm.Drop_Name', 'm.Debt_Tier', 'm.Send_Date', 'm.SMS_Drops', 'm.SMS_Last_Export_Date'])
            ->whereNotNull('m.Drop_Name')
            ->orderByRaw('CASE WHEN m.Send_Date > ? THEN 1 ELSE 0 END ASC', [Carbon::today()->toDateString()])
            ->orderBy('m.SMS_Drops')
            ->orderByRaw('CASE WHEN m.SMS_Drops > 0 THEN m.SMS_Last_Export_Date END ASC')
            ->orderByDesc('m.Send_Date')->orderBy('m.Drop_Name')->orderBy('m.PK');
    }

    public function allDrops(int $page = 1): Collection
    {
        return $this->marketingDrops()
            ->selectRaw('CASE WHEN m.Send_Date <= ? THEN 1 ELSE 0 END AS SMS_Selectable', [Carbon::today()->toDateString()])
            ->selectRaw('NULL AS Amount_Dropped')->forPage($page, 25)->get();
    }

    public function selectDrops(int $target): Collection
    {
        $selected = collect();
        $total = 0;
        $this->marketingDrops()->where('m.Send_Date', '<=', Carbon::today()->toDateString())->chunk(25, function (Collection $drops) use ($target, &$selected, &$total): bool {
            foreach ($drops as $drop) {
                $drop->Amount_Dropped = $this->countPhonesForDrop($drop->Drop_Name);
                if ($drop->Amount_Dropped > 0) {
                    $selected->push($drop);
                    $total += $drop->Amount_Dropped;
                }
                if ($total >= $target) {
                    return false;
                }
            }
            return true;
        });
        $planned = (new SmsDropPlanner)->select($selected, $target);
        $this->assertUniqueSourceNames($planned, 'target');
        return $planned;
    }

    /** Freeze priority order before counting so another export cannot shift offset pages. */
    public function orderedSelectableDropIds(): Collection
    {
        return $this->marketingDrops()
            ->where('m.Send_Date', '<=', Carbon::today()->toDateString())
            ->pluck('m.PK');
    }

    /** @param array<int, int> $ids */
    public function countedDropsByIds(array $ids): Collection
    {
        $byId = $this->marketingDrops()->whereIn('m.PK', $ids)
            ->where('m.Send_Date', '<=', Carbon::today()->toDateString())->get()->keyBy('PK');
        if ($byId->count() !== count($ids)) {
            throw ValidationException::withMessages(['count_pks' => 'The drop list changed. Auto select again.']);
        }
        $drops = collect($ids)->map(fn (int $id): object => $byId->get($id));
        if ($drops->isEmpty()) return $drops;

        foreach ($drops as $drop) {
            $drop->Amount_Dropped = $this->countPhonesForDrop($drop->Drop_Name);
        }
        return $drops;
    }

    /** @param array<int, int> $ids */
    public function selectDropsByIds(array $ids): Collection
    {
        $drops = $this->marketingDrops()->where('m.Send_Date', '<=', Carbon::today()->toDateString())->whereIn('m.PK', $ids)->get()->keyBy('PK');
        if ($drops->count() !== count($ids)) {
            throw ValidationException::withMessages(['drop_pks' => 'One or more selected drops no longer exist. Refresh the list.']);
        }

        $selected = collect($ids)->map(function (int $id) use ($drops): object {
            $drop = $drops->get($id);
            $drop->Amount_Dropped = $this->countPhonesForDrop($drop->Drop_Name);
            if ($drop->Amount_Dropped < 1) {
                throw ValidationException::withMessages(['drop_pks' => "{$drop->Drop_Name} has no eligible phones. Deselect it and preview again."]);
            }
            return $drop;
        });
        $this->assertUniqueSourceNames($selected, 'drop_pks');
        return $selected;
    }

    /** @return array{path:string, count:int, names:array<string>, part_count:int, format:string} */
    public function prepareExport(int $target, string $requestId, ?array $dropPks = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sms-export-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the SMS export file.');
        }

        $parts = [$path];
        $archivePath = null;
        try {
            return $this->connection()->transaction(function () use ($target, $requestId, $dropPks, $path, &$parts, &$archivePath): array {
                $last = $this->table('TblSmsExports')->orderByDesc('PK')->lockForUpdate()->first();
                if ($this->table('TblSmsExports')->where('Request_ID', $requestId)->exists()) {
                    throw ValidationException::withMessages(['target' => 'This request was already exported. Refresh before starting another export.']);
                }
                $selected = $dropPks === null ? $this->selectDrops($target) : $this->selectDropsByIds($dropPks);
                $this->assertUniqueSourceNames($selected, 'target');
                if ((int) $selected->sum('Amount_Dropped') > 10000000) {
                    throw ValidationException::withMessages(['target' => 'Whole drops exceed the 10,000,000 phone export limit. Choose a smaller target or different drops.']);
                }
                if ((int) $selected->sum('Amount_Dropped') < $target) {
                    throw ValidationException::withMessages(['target' => 'There are not enough eligible phones to reach this target. Enter a smaller target.']);
                }
                if ($selected->contains(fn (object $drop): bool => trim((string) $drop->Debt_Tier) === '')) {
                    throw ValidationException::withMessages(['target' => 'A selected drop is missing its debt tier. Correct the marketing record first.']);
                }
                $limit = $this->csvRecordLimit();
                if ($limit < 1) {
                    throw new RuntimeException('The SMS CSV row limit must be positive.');
                }
                $groups = $selected->groupBy('Debt_Tier')->sortKeys(SORT_NATURAL);
                $next = (int) ($last->PK ?? 0);
                $exportedAt = Carbon::now();
                $total = 0;
                $names = [];
                $partRows = 0;
                $out = fopen($path, 'wb');
                if ($out === false) {
                    throw new RuntimeException('Unable to open the SMS export file.');
                }
                try {
                    $this->writeCsv($out, $this->csvHeader());
                    foreach ($groups as $tier => $drops) {
                        $id = ++$next;
                        $name = 'SMS'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
                        $tierCount = 0;
                        $sourceCounts = [];
                        foreach ($drops as $drop) {
                            $sourceCount = 0;
                            foreach ($this->rowsForDrop($drop->Drop_Name) as $row) {
                                if ($partRows >= $limit) {
                                    if (! class_exists(\ZipArchive::class)) {
                                        throw new RuntimeException('The server needs PHP ZipArchive to package multiple SMS CSV files.');
                                    }
                                    if (! fflush($out)) {
                                        throw new RuntimeException('Unable to finish writing an SMS CSV part.');
                                    }
                                    fclose($out);
                                    $out = null;
                                    $partPath = tempnam(sys_get_temp_dir(), 'sms-export-');
                                    if ($partPath === false) {
                                        throw new RuntimeException('Unable to create another SMS CSV part.');
                                    }
                                    $parts[] = $partPath;
                                    $out = fopen($partPath, 'wb');
                                    if ($out === false) {
                                        throw new RuntimeException('Unable to open another SMS CSV part.');
                                    }
                                    $this->writeCsv($out, $this->csvHeader());
                                    $partRows = 0;
                                }
                                $this->writeCsv($out, [
                                    $row->First_Name, $row->Address, $row->Debt_Amount,
                                    ...$row->Phones, $exportedAt->toDateString(),
                                ]);
                                $partRows++;
                                $sourceCount += count(array_filter($row->Phones, static fn (string $phone): bool => $phone !== ''));
                            }
                            if ($sourceCount === 0) {
                                throw ValidationException::withMessages(['target' => 'Phone eligibility changed during export. Refresh and try again.']);
                            }
                            $sourceCounts[(int) $drop->PK] = $sourceCount;
                            $tierCount += $sourceCount;
                        }

                        $this->table('TblSmsExports')->insert([
                            'PK' => $id, 'SMS_Drop_Name' => $name, 'Request_ID' => $requestId,
                            'Debt_Tier' => (string) $tier, 'Week_Start' => $exportedAt->copy()->startOfWeek()->toDateString(),
                            'Exported_At' => $exportedAt->toDateTimeString(), 'SMS_Count' => $tierCount,
                            'SMS_Cost' => '0.00',
                        ]);
                        foreach ($sourceCounts as $pk => $count) {
                            $this->table('TblSmsExportSources')->insert([
                                'SMS_Export_PK' => $id, 'Marketing_PK' => $pk, 'SMS_Count' => $count,
                            ]);
                            $this->table('TblMarketing')->where('PK', $pk)->increment('SMS_Drops', 1, [
                                'SMS_Last_Export_Date' => $exportedAt->toDateTimeString(),
                            ]);
                        }
                        $names[] = $name;
                        $total += $tierCount;
                    }
                    if ($total < $target) {
                        throw ValidationException::withMessages(['target' => 'Phone eligibility changed and the target is no longer met. Refresh and try again.']);
                    }
                    if (! fflush($out)) {
                        throw new RuntimeException('Unable to finish writing the SMS export.');
                    }
                } finally {
                    if (is_resource($out)) {
                        fclose($out);
                    }
                }

                if (count($parts) === 1) {
                    return ['path' => $path, 'count' => $total, 'names' => $names, 'part_count' => 1, 'format' => 'csv'];
                }
                if (! class_exists(\ZipArchive::class)) {
                    throw new RuntimeException('The server needs PHP ZipArchive to package multiple SMS CSV files.');
                }
                $archivePath = tempnam(sys_get_temp_dir(), 'sms-export-zip-');
                if ($archivePath === false) {
                    throw new RuntimeException('Unable to create the SMS archive.');
                }
                $this->archiveCsvParts($parts, $archivePath, $exportedAt);
                foreach ($parts as $part) {
                    unlink($part);
                }
                $partCount = count($parts);
                $parts = [];
                return ['path' => $archivePath, 'count' => $total, 'names' => $names, 'part_count' => $partCount, 'format' => 'zip'];
            });
        } catch (Throwable $exception) {
            foreach ($parts as $part) {
                if (is_file($part)) unlink($part);
            }
            if ($archivePath !== null && is_file($archivePath)) unlink($archivePath);
            throw $exception;
        }
    }

    /** @param array<int, string> $parts */
    protected function archiveCsvParts(array $parts, string $archivePath, Carbon $exportedAt): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open the SMS archive.');
        }
        $closeAttempted = false;
        try {
            foreach ($parts as $index => $part) {
                $filename = sprintf('sms_export_%s_part%03d.csv', $exportedAt->format('Ymd_His'), $index + 1);
                if (! $zip->addFile($part, $filename)) {
                    throw new RuntimeException('Unable to add an SMS CSV part to the archive.');
                }
            }
            $closeAttempted = true;
            if (! $zip->close()) {
                throw new RuntimeException('Unable to finish the SMS archive.');
            }
        } catch (Throwable $exception) {
            if (! $closeAttempted) $zip->close();
            throw $exception;
        }
    }

    protected function assertUniqueSourceNames(Collection $drops, string $field): void
    {
        $names = [];
        foreach ($drops as $drop) {
            $name = strtolower(trim((string) $drop->Drop_Name));
            if (isset($names[$name])) {
                throw ValidationException::withMessages([$field => 'Selected marketing records share a source drop name. Correct the duplicate records before exporting.']);
            }
            $names[$name] = true;
        }
        if ($this->table('TblMarketing')->whereIn('Drop_Name', $drops->pluck('Drop_Name')->all())
            ->groupBy('Drop_Name')->havingRaw('COUNT(*) > 1')->exists()) {
            throw ValidationException::withMessages([$field => 'A source drop name appears more than once in marketing records. Correct the duplicates before exporting.']);
        }
    }

    /** Target and SMS_Count count actual eligible phone numbers, not CSV lines. */
    protected function countPhonesForDrop(string $dropName): int
    {
        $count = 0;
        foreach ($this->rowsForDrop($dropName) as $row) {
            $count += count(array_filter($row->Phones, static fn (string $phone): bool => $phone !== ''));
        }
        return $count;
    }

    /** @return array<int, string> */
    protected function csvHeader(): array
    {
        return ['First name', 'address', 'debt load', 'phone1', 'phone2', 'phone3', 'phone4', 'phone5', 'send date'];
    }

    /**
     * Both source tables can contain a lead. E has one phone per row (often several
     * rows per External_ID); E2 has five slots and also has leads absent from E.
     * Sorting their union by identity lets us merge without dropping either source.
     */
    protected function sourceRowsForDrop(string $dropName): iterable
    {
        $debt = $this->table('TblMailersUnique', 'u')
            ->select('u.Debt_Amount')
            ->whereColumn('u.External_ID', 'e.External_ID')
            ->whereColumn('u.Drop_Name', 'e.Drop_Name')
            ->orderBy('u.PK')->limit(1);
        $one = $this->table('TblMailersUniqueEnriched', 'e')
            ->where('e.Drop_Name', $dropName)
            ->selectRaw('e.PK, e.External_ID, e.Client, e.Address, e.Phone AS phone1, NULL AS phone2, NULL AS phone3, NULL AS phone4, NULL AS phone5, 0 AS source_rank')
            ->selectSub($debt, 'Debt_Amount');
        $five = $this->table('TblMailersUniqueEnriched2', 'e2')
            ->where('e2.Drop_Name', $dropName)
            ->selectRaw('e2.PK, e2.External_ID, e2.Client, e2.Address, e2.phone1, e2.phone2, e2.phone3, e2.phone4, e2.phone5, 1 AS source_rank, e2.Debt_Amount');
        $union = $one->unionAll($five);
        $identity = 'LOWER(TRIM(External_ID))';
        return $this->connection()->query()->fromSub($union, 'sources')
            ->orderByRaw($identity)->orderBy('source_rank')->orderBy('PK')->cursor();
    }

    protected function rowsForDrop(string $dropName): iterable
    {
        $pending = [];
        $batch = [];
        $identity = null;
        foreach ($this->sourceRowsForDrop($dropName) as $source) {
            $key = strtolower(trim((string) ($source->External_ID ?? '')));
            if ($key === '') {
                throw ValidationException::withMessages(['target' => "{$dropName} contains a lead without an External_ID. Correct the source before exporting."]);
            }
            if ($identity !== null && $key !== $identity) {
                $lead = $this->mergeLead($pending, $dropName);
                if ($lead !== null) $batch[] = $lead;
                if (count($batch) >= 250) {
                    yield from $this->eligibleRowsForLeads($batch);
                    $batch = [];
                }
                $pending = [];
            }
            $identity = $key;
            $pending[] = $source;
        }
        if ($pending !== []) {
            $lead = $this->mergeLead($pending, $dropName);
            if ($lead !== null) $batch[] = $lead;
        }
        if ($batch !== []) yield from $this->eligibleRowsForLeads($batch);
    }

    /** @param array<int, object> $sources */
    protected function mergeLead(array $sources, string $dropName): ?object
    {
        $address = null;
        $preferred = null;
        $debt = null;
        $namesBySource = [];
        $phones = [];
        foreach ($sources as $source) {
            $sourceAddress = strtoupper(trim((string) ($source->Address ?? '')));
            if ($address !== null && $address !== $sourceAddress) {
                throw ValidationException::withMessages(['target' => "{$dropName} has one External_ID with conflicting addresses. Reconcile the lead before exporting."]);
            }
            $address = $sourceAddress;
            $rank = (int) $source->source_rank;
            $sourceName = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($source->Client ?? ''))));
            if (isset($namesBySource[$rank]) && $namesBySource[$rank] !== $sourceName) {
                throw ValidationException::withMessages(['target' => "{$dropName} has one External_ID with conflicting names in the same source. Reconcile the lead before exporting."]);
            }
            $namesBySource[$rank] = $sourceName;
            if ($preferred === null || (int) $source->source_rank > (int) $preferred->source_rank) $preferred = $source;
            if ($source->Debt_Amount !== null && $source->Debt_Amount !== '') {
                if ($debt !== null && $this->formatDebtLoad($debt) !== $this->formatDebtLoad($source->Debt_Amount)) {
                    throw ValidationException::withMessages(['target' => "{$dropName} has one External_ID with conflicting debt amounts. Reconcile the lead before exporting."]);
                }
                $debt = $source->Debt_Amount;
            }
            foreach (['phone1', 'phone2', 'phone3', 'phone4', 'phone5'] as $column) {
                $phone = $this->normalizePhone($source->{$column} ?? null);
                if ($phone !== null) $phones[$phone] = true;
            }
        }
        if ($phones === []) return null;
        return (object) [
            'First_Name' => strtoupper(explode(' ', trim((string) $preferred->Client))[0]),
            'Address' => $address,
            'Debt_Amount' => $this->formatDebtLoad($debt),
            'Phones' => array_map('strval', array_keys($phones)),
        ];
    }

    /** @param array<int, object> $leads */
    protected function eligibleRowsForLeads(array $leads): iterable
    {
        $numbers = [];
        foreach ($leads as $lead) foreach ($lead->Phones as $phone) $numbers[$phone] = true;
        $contacted = [];
        // SyncPhoneNumbers represents contacted people, so any matching number
        // excludes the whole merged lead, not just one phone slot.
        foreach (array_chunk(array_keys($numbers), 900) as $chunk) {
            $lookups = [];
            foreach ($chunk as $phone) { $lookups[] = $phone; $lookups[] = '1'.$phone; }
            foreach ($this->table('TblPhoneNumbers')->whereIn('Phone', $lookups)->pluck('Phone') as $phone) {
                $contacted[strlen((string) $phone) === 11 ? substr((string) $phone, 1) : (string) $phone] = true;
            }
        }
        foreach ($leads as $lead) {
            if (array_intersect_key(array_fill_keys($lead->Phones, true), $contacted) !== []) continue;
            foreach (array_chunk($lead->Phones, 5) as $part) {
                yield (object) [
                    'First_Name' => $lead->First_Name,
                    'Address' => $lead->Address,
                    'Debt_Amount' => $lead->Debt_Amount,
                    'Phones' => array_pad($part, 5, ''),
                ];
            }
        }
    }

    protected function normalizePhone(mixed $value): ?string
    {
        $phone = str_replace([' ', '-', '(', ')', '+', '.'], '', (string) ($value ?? ''));
        if (strlen($phone) === 11 && $phone[0] === '1') $phone = substr($phone, 1);
        return preg_match('/^[0-9]{10}$/D', $phone) ? $phone : null;
    }

    protected function formatDebtLoad(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $matches)) return $text;
        $integer = ltrim($matches[2], '0');
        $fraction = rtrim($matches[3] ?? '', '0');
        return $matches[1].($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);
    }

    /** @param resource $out @param array<int, mixed> $values */
    protected function writeCsv(mixed $out, array $values): void
    {
        if ($values === $this->csvHeader()) {
            if (fwrite($out, implode(',', $values)."\r\n") === false) {
                throw new RuntimeException('Unable to write the SMS export header.');
            }
            return;
        }
        $values = array_map(function (mixed $value): string {
            $text = (string) ($value ?? '');
            return preg_match('/^[=+@\\-\\t\\r\\n]/', $text) ? "'".$text : $text;
        }, $values);
        if (fputcsv($out, $values, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to write the SMS export.');
        }
    }
}
