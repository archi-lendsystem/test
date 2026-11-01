<?php

namespace Modules\BLAmortization\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class BLAmortizationGenerated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $amortizationId,
        public readonly string $leadUuid,
        public readonly ?int $actorId = null
    ) {}
}
