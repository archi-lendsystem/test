<?php

namespace Modules\ESign\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\ESign\Models\ESignDocument;

final class BorrowerSigned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public ESignDocument $document)
    {
    }
}
