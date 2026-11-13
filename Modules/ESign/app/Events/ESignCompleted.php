<?php

namespace Modules\ESign\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\ESign\Models\SigningRequest;

class ESignCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SigningRequest $signingRequest
    ) {
    }
}