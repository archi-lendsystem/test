<?php

namespace Modules\ESign\Data;

use Carbon\CarbonImmutable;
use Modules\ESign\Enums\SessionStatus;

final class ProviderSessionData
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $documentId,
        public readonly string $signingUrl,
        public readonly SessionStatus $status,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly array $raw = [],
    ) {
    }
}
