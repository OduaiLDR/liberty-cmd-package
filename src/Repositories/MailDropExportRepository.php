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
    protected function marketingDrops(): Builder
    {
        return $this->table('TblMarketing', 'm')
            ->select(['m.PK', 'm.Drop_Name', 'm.Debt_Tier', 'm.Send_Date', 'm.SMS_Drops', 'm.SMS_Last_Export_Date'])
            ->whereNotNull('m.Drop_Name')
            ->orderByRaw('CASE WHEN m.Send_Date > ? THEN 1 ELSE 0 END ASC', [Carbon::today()->toDateString()])
            ->orderBy('m.SMS_Drops')
            ->orderByRaw('CASE WHEN m.SMS_Drops > 0 THEN m.SMS_Last_Export_Date END ASC')
            ->orderByDesc('m.Send_Date')->orderBy('m.Drop_Name');
    }

    public function allDrops(int $page = 1): Collection
    {
        $counts = $this->eligiblePhones()->whereColumn('e.Drop_Name', 'm.Drop_Name')->selectRaw('COUNT(*)');
        return $this->marketingDrops()
            ->selectRaw('CASE WHEN m.Send_Date <= ? THEN 1 ELSE 0 END AS SMS_Selectable', [Carbon::today()->toDateString()])
            ->selectSub($counts, 'Amount_Dropped')->forPage($page, 25)->get();
    }

    public function selectDrops(int $target): Collection
    {
        $selected = collect();
        $total = 0;
        $this->marketingDrops()->where('m.Send_Date', '<=', Carbon::today()->toDateString())->chunk(25, function (Collection $drops) use ($target, &$selected, &$total): bool {
            foreach ($drops as $drop) {
                $drop->Amount_Dropped = $this->eligiblePhones()->where('e.Drop_Name', $drop->Drop_Name)->count();
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

    /** @param array<int, int> $ids */
    public function selectDropsByIds(array $ids): Collection
    {
        $drops = $this->marketingDrops()->where('m.Send_Date', '<=', Carbon::today()->toDateString())->whereIn('m.PK', $ids)->get()->keyBy('PK');
        if ($drops->count() !== count($ids)) {
            throw ValidationException::withMessages(['drop_pks' => 'One or more selected drops no longer exist. Refresh the list.']);
        }

        $selected = collect($ids)->map(function (int $id) use ($drops): object {
            $drop = $drops->get($id);
            $drop->Amount_Dropped = $this->eligiblePhones()->where('e.Drop_Name', $drop->Drop_Name)->count();
            if ($drop->Amount_Dropped < 1) {
                throw ValidationException::withMessages(['drop_pks' => "{$drop->Drop_Name} has no eligible phones. Deselect it and preview again."]);
            }
            return $drop;
        });
        $this->assertUniqueSourceNames($selected, 'drop_pks');
        return $selected;
    }

    /** @return array{path:string, count:int, names:array<string>} */
    public function prepareExport(int $target, string $requestId, ?array $dropPks = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sms-export-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the SMS export file.');
        }

        try {
            return $this->connection()->transaction(function () use ($target, $requestId, $dropPks, $path): array {
                $last = $this->table('TblSmsExports')->orderByDesc('PK')->lockForUpdate()->first();
                if ($this->table('TblSmsExports')->where('Request_ID', $requestId)->exists()) {
                    throw ValidationException::withMessages(['target' => 'This request was already exported. Refresh before starting another export.']);
                }
                $selected = $dropPks === null ? $this->selectDrops($target) : $this->selectDropsByIds($dropPks);
                $this->assertUniqueSourceNames($selected, 'target');
                if ((int) $selected->sum('Amount_Dropped') < $target) {
                    throw ValidationException::withMessages(['target' => 'There are not enough eligible phones to reach this target. Enter a smaller target.']);
                }
                if ($selected->contains(fn (object $drop): bool => trim((string) $drop->Debt_Tier) === '')) {
                    throw ValidationException::withMessages(['target' => 'A selected drop is missing its debt tier. Correct the marketing record first.']);
                }
                $groups = $selected->groupBy('Debt_Tier')->sortKeys(SORT_NATURAL);
                $next = (int) ($last->PK ?? 0);
                $exportedAt = Carbon::now();
                $total = 0;
                $names = [];
                $out = fopen($path, 'wb');
                if ($out === false) {
                    throw new RuntimeException('Unable to open the SMS export file.');
                }
                try {
                    $this->writeCsv($out, ['SMS Drop', 'Debt Tier', 'Source Drop', 'First Name', 'Address', 'Debt', 'Phone', 'Send Date']);
                    foreach ($groups as $tier => $drops) {
                        $id = ++$next;
                        $name = 'SMS'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
                        $tierCount = 0;
                        $sourceCounts = [];
                        foreach ($drops as $drop) {
                            $sourceCount = 0;
                            foreach ($this->rowsForDrop($drop->Drop_Name) as $row) {
                                $this->writeCsv($out, [
                                    $name, $tier, $drop->Drop_Name,
                                    explode(' ', trim((string) $row->Client))[0],
                                    $row->Address, $row->Debt_Amount, $row->SMS_Phone, $drop->Send_Date,
                                ]);
                                $sourceCount++;
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
                    fclose($out);
                }

                return ['path' => $path, 'count' => $total, 'names' => $names];
            });
        } catch (Throwable $exception) {
            unlink($path);
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

    protected function eligiblePhones(): Builder
    {
        $phone = $this->normalizedPhoneExpression();
        $sqlServer = $this->connection()->getDriverName() === 'sqlsrv';
        $length = $sqlServer ? 'LEN' : 'LENGTH';
        $prefixed = $sqlServer ? "('1' + {$phone})" : "('1' || {$phone})";

        return $this->table('TblMailersUniqueEnriched', 'e')
            ->whereRaw("{$length}({$phone}) = 10")
            ->whereRaw($sqlServer ? "{$phone} NOT LIKE '%[^0-9]%'" : "{$phone} NOT GLOB '*[^0-9]*'")
            ->whereNotExists(function (Builder $query) use ($phone, $prefixed): void {
                $query->selectRaw('1')->from('TblPhoneNumbers as p')
                    ->whereRaw("(p.Phone = {$phone} OR p.Phone = {$prefixed})");
            });
    }

    protected function rowsForDrop(string $dropName): iterable
    {
        $debt = $this->table('TblMailersUnique', 'u')
            ->select('u.Debt_Amount')
            ->whereColumn('u.External_ID', 'e.External_ID')
            ->whereColumn('u.Drop_Name', 'e.Drop_Name')
            ->orderBy('u.PK')->limit(1);

        return $this->eligiblePhones()->where('e.Drop_Name', $dropName)
            ->select(['e.PK', 'e.Client', 'e.Address'])
            ->selectRaw($this->normalizedPhoneExpression().' AS SMS_Phone')
            ->selectSub($debt, 'Debt_Amount')
            ->lazyById(10000, 'e.PK', 'PK');
    }

    protected function normalizedPhoneExpression(): string
    {
        $phone = "COALESCE(e.Phone, '')";
        foreach ([' ', '-', '(', ')', '+', '.'] as $character) {
            $phone = "REPLACE({$phone}, '{$character}', '')";
        }
        $length = $this->connection()->getDriverName() === 'sqlsrv' ? 'LEN' : 'LENGTH';
        return "CASE WHEN {$length}({$phone}) = 11 AND SUBSTRING({$phone}, 1, 1) = '1' THEN SUBSTRING({$phone}, 2, 10) ELSE {$phone} END";
    }

    /** @param resource $out @param array<int, mixed> $values */
    protected function writeCsv(mixed $out, array $values): void
    {
        $values = array_map(function (mixed $value): string {
            $text = (string) ($value ?? '');
            return preg_match('/^[=+@\\-\\t\\r\\n]/', $text) ? "'".$text : $text;
        }, $values);
        if (fputcsv($out, $values, ',', '"', '') === false) {
            throw new RuntimeException('Unable to write the SMS export.');
        }
    }
}
