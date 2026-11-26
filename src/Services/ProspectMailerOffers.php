<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class ProspectMailerOffers
{
    public static function allowsOrigin(string $origin): bool
    {
        $origins = config('prospect-list-builder.mailer_offer_origins', []);
        $https = preg_match('~\Ahttps://[a-z0-9.-]+(?::443)?\z~i', $origin) === 1;
        $local = preg_match('~\Ahttp://(?:[a-z0-9-]+\.localhost|localhost|127\.0\.0\.1)(?::[0-9]{1,5})?\z~i', $origin) === 1
            && app()->isLocal();

        return ($https || $local) && is_array($origins) && in_array($origin, $origins, true);
    }

    /**
     * @param list<string> $references
     * @return array<int|string, string>
     */
    public function forReferences(string $origin, string $tenant, array $references): array
    {
        $references = array_values(array_unique(array_filter(array_map('trim', $references), static fn (string $reference): bool =>
            $reference !== '' && strlen($reference) <= 100
        )));
        if ($references === []) {
            return [];
        }
        $token = config('prospect-list-builder.token');
        if (! self::allowsOrigin($origin) || ! is_string($token) || $token === '') {
            throw new ProspectMailerOffersUnavailable();
        }

        try {
            $response = Http::acceptJson()
                ->withToken(hash_hmac('sha256', 'prospect-mailer-offers:'.$tenant, $token))
                ->withHeaders(['X-Prospect-Tenant' => $tenant])
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(15)
                ->retry([200, 500], when: static fn (\Exception $error): bool => $error instanceof ConnectionException
                    || ($error instanceof RequestException && $error->response->serverError()), throw: false)
                ->post($origin.'/api/v3/prospect-lists/mailer-offers', ['references' => $references]);
        } catch (ConnectionException) {
            throw new ProspectMailerOffersUnavailable();
        }

        $offers = $response->json('offers');
        if (! $response->successful() || ! is_array($offers) || ! array_is_list($offers)) {
            throw new ProspectMailerOffersUnavailable();
        }

        $requested = array_fill_keys($references, true);
        $amounts = [];
        foreach ($offers as $offer) {
            $reference = $offer['reference'] ?? null;
            $amount = $offer['amount'] ?? null;
            if (! is_string($reference) || ! isset($requested[$reference]) || isset($amounts[$reference])
                || ! is_string($amount) || preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/', $amount) !== 1) {
                throw new ProspectMailerOffersUnavailable();
            }
            $amounts[$reference] = $amount;
        }

        return $amounts;
    }
}
