<?php

namespace Modules\ESign\Services;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\ESign\Contracts\ESignProvider;
use Modules\ESign\Data\WebhookData;
use Modules\ESign\Exceptions\ESignDomainException;
use Modules\ESign\Jobs\ProcessWebhookJob;
use Modules\ESign\Models\ESignSession;
use Modules\ESign\Models\ESignWebhookEvent;
use Throwable;

final class WebhookService
{
    public function __construct(
        private readonly ESignProvider $provider,
        private readonly ESignWorkflow $workflow,
        private readonly LogSanitizer $sanitizer,
    ) {
    }

    public function receive(Request $request): ESignWebhookEvent
    {
        if (! $this->provider->verifyWebhook($request)) {
            throw new ESignDomainException('Webhook signature is invalid.', 401);
        }

        $data = $this->provider->parseWebhook($request);
        $provider = (string) config('esign.provider.name', 'http');
        $hash = hash('sha256', $request->getContent());

        try {
            $event = ESignWebhookEvent::query()->firstOrCreate(
                ['provider' => $provider, 'payload_hash' => $hash],
                [
                    'provider_event_id' => $data->eventId,
                    'payload' => $this->sanitizer->sanitize($data->toArray()),
                    'status' => 'received',
                ],
            );
        } catch (QueryException) {
            $event = ESignWebhookEvent::query()
                ->where('provider', $provider)
                ->where(function ($query) use ($hash, $data): void {
                    $query->where('payload_hash', $hash);
                    if ($data->eventId !== null) {
                        $query->orWhere('provider_event_id', $data->eventId);
                    }
                })
                ->firstOrFail();
        }

        if ($event->wasRecentlyCreated) {
            ProcessWebhookJob::dispatch($event->getKey())
                ->onQueue((string) config('esign.queue', 'default'))
                ->afterCommit();
        }

        return $event;
    }

    public function process(int $eventId): void
    {
        $event = DB::transaction(function () use ($eventId): ?ESignWebhookEvent {
            $locked = ESignWebhookEvent::query()->lockForUpdate()->findOrFail($eventId);

            if ($locked->status === 'processed') {
                return null;
            }

            $locked->update(['status' => 'processing', 'error' => null]);

            return $locked;
        }, 3);

        if ($event === null) {
            return;
        }

        try {
            $data = WebhookData::fromArray($event->payload);
            $session = $this->findSession($data);
            $this->workflow->applyWebhook($session, $data);
            $event->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
        } catch (Throwable $exception) {
            $event->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw $exception;
        }
    }

    private function findSession(WebhookData $data): ESignSession
    {
        $query = ESignSession::query();

        if ($data->referenceKey !== null) {
            $session = (clone $query)->where('reference_key', $data->referenceKey)->first();
            if ($session !== null) {
                return $session;
            }
        }

        if ($data->requestId !== null) {
            $session = (clone $query)->where('provider_request_id', $data->requestId)->first();
            if ($session !== null) {
                return $session;
            }
        }

        if ($data->documentId !== null) {
            $session = (clone $query)->where('provider_doc_id', $data->documentId)->latest('id')->first();
            if ($session !== null) {
                return $session;
            }
        }

        throw new ESignDomainException('Webhook does not match an eSign session.', 422);
    }
}
