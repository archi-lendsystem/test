<?php

namespace Modules\PaydayPartnerCases\Services\Integrations;

use Modules\PaydayPartnerCases\Services\Contracts\PartnerIntegrationInterface;

abstract class AbstractPartnerIntegrationService implements PartnerIntegrationInterface
{
    protected string $apiUrl = '';
    protected string $clientId = '';
    protected string $clientSecret = '';
    protected int $timeout = 30;

    protected function headers(): array
    {
        return [
            'Accept'          => 'application/json',
            'Content-Type'    => 'application/json',
            'X-Client-Id'     => $this->clientId,
            'X-Client-Secret' => $this->clientSecret,
        ];
    }

    protected function sanitizedHeaders(): array
    {
        return [
            'Accept'          => 'application/json',
            'Content-Type'    => 'application/json',
            'X-Client-Id'     => $this->clientId,
            'X-Client-Secret' => '********',
        ];
    }
}