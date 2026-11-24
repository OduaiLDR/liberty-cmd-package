<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;

final class TcpaLitigatorScrubber
{
    private const ENDPOINT = 'https://api.tcpalitigatorlist.com/scrub/phones/';

    private int $nextRequestAt = 0;

    public function __construct(private Factory $http) {}

    /** @return array{rows: Collection, matched: int, invalid_phone: int} */
    public function filter(Collection $rows): array
    {
        $phonesByRow = $rows->map(static fn (object $row): ?string => self::normalize($row->phone));
        $phones = $phonesByRow->filter()->uniqueStrict()->values()->all();
        $decisions = $phones === [] ? [] : $this->decisions($phones);

        $eligible = $rows->filter(static function (object $row, int $index) use ($phonesByRow, $decisions): bool {
            $phone = $phonesByRow[$index];

            return $phone !== null && $decisions[$phone];
        });
        $invalid = $phonesByRow->filter(static fn (?string $phone): bool => $phone === null)->count();

        return ['rows' => $eligible, 'matched' => $rows->count() - $eligible->count() - $invalid, 'invalid_phone' => $invalid];
    }

    /** @param list<string> $phones @return array<string, bool> */
    private function decisions(array $phones): array
    {
        $username = config('prospect-list-builder.tcpa_scrub.username');
        $password = config('prospect-list-builder.tcpa_scrub.password');
        if (! is_string($username) || trim($username) === '' || ! is_string($password) || trim($password) === '' || count($phones) > 3_000) {
            throw new TcpaScrubUnavailable('TCPA scrub credentials or batch size are invalid.');
        }

        // The public API allows five bulk requests per second. Space requests within this export.
        $remaining = $this->nextRequestAt - hrtime(true);
        if ($remaining > 0) {
            usleep((int) ceil($remaining / 1_000));
        }
        $this->nextRequestAt = hrtime(true) + 200_000_000;

        try {
            $response = $this->http->asForm()->acceptJson()->withBasicAuth($username, $password)
                ->withoutRedirecting()->connectTimeout(3)->timeout(25)->post(self::ENDPOINT, [
                    'phones' => json_encode($phones, JSON_THROW_ON_ERROR),
                    'type' => 'tcpa',
                    'small_list' => 'true',
                    'sub_user' => 'list-builder',
                ]);
        } catch (ConnectionException) {
            throw new TcpaScrubUnavailable('The TCPA scrub provider could not be reached.');
        }

        $results = $response->json('results');
        if (! $response->successful() || $response->json('error') !== null || ! is_array($results)) {
            throw new TcpaScrubUnavailable('The TCPA scrub provider rejected the batch.');
        }

        $expected = array_fill_keys($phones, true);
        $decisions = [];
        foreach ($results as $result) {
            $phone = is_array($result) ? self::normalize($result['phone_number'] ?? null) : null;
            $clean = is_array($result) ? ($result['clean'] ?? null) : null;
            if ($phone === null || ! isset($expected[$phone]) || isset($decisions[$phone]) ||
                ! in_array($clean, [0, '0', false, 1, '1', true], true)) {
                throw new TcpaScrubUnavailable('The TCPA scrub provider returned an invalid decision.');
            }

            $isClean = in_array($clean, [1, '1', true], true);
            if ($isClean && in_array($result['is_bad_number'] ?? false, [1, '1', true, 'true'], true)) {
                throw new TcpaScrubUnavailable('The TCPA scrub provider returned conflicting decisions.');
            }
            $decisions[$phone] = $isClean;
        }
        if (count($decisions) !== count($expected)) {
            throw new TcpaScrubUnavailable('The TCPA scrub provider did not verify every number.');
        }

        return $decisions;
    }

    private static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value, ' ');
        if (preg_match('/\A\+?[0-9(). -]+\z/', $value) !== 1) {
            return null;
        }
        $digits = str_replace(['+', '(', ')', '.', ' ', '-'], '', $value);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        return strlen($digits) === 10 && ! str_starts_with($value, '+') ? $digits : null;
    }
}
