<?php

namespace Modules\ESign\Console;

use Illuminate\Console\Command;
use Modules\ESign\Models\ESignLog;
use Modules\ESign\Models\ESignWebhookEvent;

final class PruneESignAuditCommand extends Command
{
    protected $signature = 'esign:prune-audit';

    protected $description = 'Delete expired API logs and processed webhook events';

    public function handle(): int
    {
        $logs = ESignLog::query()
            ->where('created_at', '<', now()->subDays((int) config('esign.retention.api_logs_days', 90)))
            ->delete();
        $events = ESignWebhookEvent::query()
            ->where('status', 'processed')
            ->where('created_at', '<', now()->subDays((int) config('esign.retention.webhook_events_days', 180)))
            ->delete();

        $this->info("Deleted {$logs} API log(s) and {$events} webhook event(s).");

        return self::SUCCESS;
    }
}
