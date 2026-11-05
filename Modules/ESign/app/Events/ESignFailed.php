<?php

namespace Modules\ESign\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\ESign\Models\ESignSession;

final class ESignFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public ESignSession $session)
    {
    }
}
