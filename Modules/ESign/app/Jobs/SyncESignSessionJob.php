<?php

namespace Modules\ESign\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ESign\Models\ESignSession;
use Modules\ESign\Services\ESignWorkflow;

final class SyncESignSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $sessionId)
    {
    }

    public function handle(ESignWorkflow $workflow): void
    {
        $session = ESignSession::query()->find($this->sessionId);

        if ($session !== null && $session->status->isActive()) {
            $workflow->syncSession($session);
        }
    }
}
