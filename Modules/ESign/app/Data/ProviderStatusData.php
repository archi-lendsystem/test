<?php

namespace Modules\ESign\Data;

use Carbon\CarbonImmutable;
use Modules\ESign\Enums\SessionStatus;

final class ProviderStatusData
{
    public function __construct(
        public readonly SessionStatus $status,
        public readonly ?string $requestId = null,
        public readonly ?string $documentId = null,
        public readonly ?CarbonImmutable $signedAt = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
    ) {
    }
}
