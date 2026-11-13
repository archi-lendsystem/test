<?php

namespace Modules\PaydayPartnerCases\Services\Resolvers;

use Modules\PaydayLoanPartner\Models\PaydayLoanPartner;
use Modules\PaydayPartnerCases\Services\Contracts\PartnerIntegrationInterface;
use Modules\PaydayPartnerCases\Services\Integrations\LoanTrustIntegrationService;

class PartnerIntegrationResolver
{
   
 public function resolve(int|PaydayLoanPartner $partner): PartnerIntegrationInterface
    {
        if (is_int($partner)) {
            $partner = PaydayLoanPartner::findOrFail($partner);
        }

        $integrationKey = strtolower(
            data_get($partner->extra_config, 'integration_key')
            ?: $partner->payday_partner_name
            ?: $partner->payday_partner_code
        );

        return match (true) {
            str_contains($integrationKey, 'loantrust'),
            str_contains($integrationKey, 'loanbeta') => new LoanTrustIntegrationService(
                $this->loanTrustCredentials($partner)
            ),

            default => new LoanTrustIntegrationService(
                $this->loanTrustCredentials($partner)
            ),
        };
    }

   private function loanTrustCredentials(PaydayLoanPartner $partner): array
    {
        $extraConfig = $partner->extra_config ?? [];
        $outbound = $extraConfig['outbound'] ?? [];

        return [
            'api_url' => $outbound['api_url']
                ?? config('services.loantrust.api_url')
                ?? $partner->api_url,

            'client_id' => $outbound['client_id']
                ?? config('services.loantrust.client_id'),

            'client_secret' => $outbound['client_secret']
                ?? config('services.loantrust.client_secret'),
        ];
    }
}