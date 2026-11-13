<?php

namespace Modules\PaydayPartnerCases\Services\Contracts;

interface PartnerIntegrationInterface
{
    public function fetchCases(array $filters = []): array;
    public function fetchCase(string $partnerCaseId): array;
    public function createCase(array $payload): array;
    public function updateCase(string $partnerCaseId, array $payload): array;
}