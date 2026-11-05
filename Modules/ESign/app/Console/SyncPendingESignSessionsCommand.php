<?php

namespace Modules\ESign\Console;

use Illuminate\Console\Command;
use Modules\ESign\Enums\DocumentStatus;
use Modules\ESign\Enums\SessionStatus;
use Modules\ESign\Jobs\SyncESignSessionJob;
use Modules\ESign\Models\ESignDocument;
use Modules\ESign\Models\ESignSession;
use Modules\ESign\Services\ESignWorkflow;

final class SyncPendingESignSessionsCommand extends Command
{
    protected $signature = 'esign:sync-pending {--sync : Run immediately instead of queueing}';

    protected $description = 'Synchronize stale active eSign sessions with the provider';

    public function handle(ESignWorkflow $workflow): int
    {
        $cutoff = now()->subMinutes((int) config('esign.polling.stale_after_minutes', 5));
        $batch = (int) config('esign.polling.batch_size', 100);
        $count = 0;

        ESignSession::query()
            ->whereIn('status', [
                SessionStatus::Initiating->value,
                SessionStatus::Pending->value,
                SessionStatus::Viewed->value,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($batch)
            ->pluck('id')
            ->each(function (int $id) use (&$count): void {
                $job = new SyncESignSessionJob($id);

                if ($this->option('sync')) {
                    dispatch_sync($job);
                } else {
                    dispatch($job)->onQueue((string) config('esign.queue', 'default'));
                }

                $count++;
            });

        $lenderStarts = 0;

        if ((bool) config('esign.bl.auto_start_lender', true)) {
            ESignDocument::query()
                ->where('status', DocumentStatus::BorrowerSigned->value)
                ->whereNotNull('lead_uuid')
                ->whereDoesntHave('sessions', function ($query): void {
                    $query->where('signer_type', 'lender')
                        ->whereIn('status', ['initiating', 'pending', 'viewed', 'signed']);
                })
                ->orderBy('id')
                ->limit($batch)
                ->get()
                ->each(function (ESignDocument $document) use ($workflow, &$lenderStarts): void {
                    try {
                        $workflow->startLenderSigning($document);
                        $lenderStarts++;
                    } catch (\Throwable) {
                        // The workflow already records enough state for the next retry/manual action.
                    }
                });
        }

        $this->info("Queued {$count} eSign session(s) for synchronization; started {$lenderStarts} lender session(s).");

        return self::SUCCESS;
    }
}
