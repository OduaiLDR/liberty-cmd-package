<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

use Cmd\Reports\Pmod\Enums\PmodCompany;
use InvalidArgumentException;

final class PmodCompanyResolver
{
    public static function fromTenantContext(?string $explicitCompany = null): PmodCompany
    {
        if ($explicitCompany !== null && $explicitCompany !== '') {
            $normalized = strtoupper(trim($explicitCompany));
            
            $company = PmodCompany::tryFrom($normalized);
            if ($company instanceof PmodCompany) {
                return $company;
            }

            if (str_contains($normalized, 'PLAW') || str_contains($normalized, 'PROLAW') || str_contains($normalized, 'PARAMOUNT')) {
                return PmodCompany::PLAW;
            }

            if (str_contains($normalized, 'LDR') || str_contains($normalized, 'LLG') || str_contains($normalized, 'LIBERTY')) {
                return PmodCompany::LDR;
            }
        }

        if (function_exists('tenant')) {
            $candidates = [
                (string) tenant('alias'),
                (string) tenant('dpp_alias'),
            ];

            foreach ($candidates as $candidate) {
                $normalized = strtoupper(trim($candidate));
                if ($normalized === '') {
                    continue;
                }

                if (str_contains($normalized, 'PLAW') || str_contains($normalized, 'PROLAW') || str_contains($normalized, 'PARAMOUNT')) {
                    return PmodCompany::PLAW;
                }

                if (str_contains($normalized, 'LDR') || str_contains($normalized, 'LLG')) {
                    return PmodCompany::LDR;
                }
            }
        }

        throw new InvalidArgumentException('Unable to resolve PMOD company from tenant context. Provide explicit tenant_id (ldr, plaw).');
    }
}
