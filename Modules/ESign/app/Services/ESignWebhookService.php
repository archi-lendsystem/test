<?php

namespace Modules\ESign\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\ESign\Models\SigningRequest;
use Modules\ESign\Models\WebhookEvent;
use Throwable;

class ESignWebhookService
{
    public function verifyChecksum(
        string $rawPayload,
        ?string $checksum
    ): bool {
        $secret = trim(
            (string) config('esign.webhook_secret', '')
        );

        $checksum = trim(
            (string) $checksum
        );

        /*
         * Fail closed.
         *
         * Webhook without configured secret or checksum
         * will never be accepted.
         */
        if ($secret === '' || $checksum === '') {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $rawPayload,
            $secret
        );

        return hash_equals(
            strtolower($expected),
            strtolower($checksum)
        );
    }

    public function process(
        string $rawPayload
    ): array {
        $payloadHash = hash(
            'sha256',
            $rawPayload
        );

        $data = json_decode(
            $rawPayload,
            true
        );

        if (! is_array($data)) {
            throw new RuntimeException(
                'Invalid Digio webhook JSON.'
            );
        }

        $eventType = strtolower(
            trim(
                (string) (
                    $data['event']
                    ?? ''
                )
            )
        );

        /*
         * Digio webhook notification ID.
         * This is our provider idempotency identifier.
         */
        $providerEventId = trim(
            (string) (
                $data['id']
                ?? ''
            )
        );

        $document = data_get(
            $data,
            'payload.document',
            []
        );

        if (! is_array($document)) {
            $document = [];
        }

        $digioDocumentId = trim(
            (string) (
                $document['id']
                ?? ''
            )
        );

        if (
            $providerEventId === ''
            || $eventType === ''
        ) {
            throw new RuntimeException(
                'Incomplete Digio webhook event.'
            );
        }

        $supportedEvents = [
            'doc.signed',
            'doc.sign.failed',
            'doc.sign.rejected',
        ];

        /*
         * Reserve webhook event first.
         */
        $webhookEvent = $this->reserveEvent(
            providerEventId: $providerEventId,
            eventType: $eventType,
            entityType: $this->extractEntityType($data),
            digioDocumentId:
                $digioDocumentId !== ''
                    ? $digioDocumentId
                    : null,
            payloadHash: $payloadHash
        );

        /*
         * Already completed duplicate event.
         */
        if (
            in_array(
                $webhookEvent->status,
                [
                    'processed',
                    'ignored',
                ],
                true
            )
        ) {
            return [
                'duplicate' => true,
                'status' => $webhookEvent->status,
                'event' => $eventType,
            ];
        }

        /*
         * Events we don't use should be acknowledged
         * instead of continuously causing provider retries.
         */
        if (
            ! in_array(
                $eventType,
                $supportedEvents,
                true
            )
        ) {
            $webhookEvent->update([
                'status' => 'ignored',
                'processed_at' => now(),
                'error_message' => null,
            ]);

            return [
                'duplicate' => false,
                'status' => 'ignored',
                'event' => $eventType,
            ];
        }

        if ($digioDocumentId === '') {
            $this->markFailed(
                $webhookEvent,
                'Digio document id missing from webhook.'
            );

            throw new RuntimeException(
                'Digio document id missing from webhook.'
            );
        }

        /*
         * Atomic claim.
         *
         * If same webhook reaches two PHP workers simultaneously,
         * only one should process it.
         */
        $claimed = WebhookEvent::query()
            ->whereKey($webhookEvent->id)
            ->whereIn(
                'status',
                [
                    'received',
                    'failed',
                ]
            )
            ->update([
                'status' => 'processing',
                'error_message' => null,
            ]);

        if ($claimed === 0) {
            return [
                'duplicate' => true,
                'status' => 'processing',
                'event' => $eventType,
            ];
        }

        try {
            $signingRequest =
                SigningRequest::query()
                    ->where(
                        'digio_doc_id',
                        $digioDocumentId
                    )
                    ->orWhere(
                        'digio_request_id',
                        $digioDocumentId
                    )
                    ->first();

            if (! $signingRequest) {
                $webhookEvent->update([
                    'status' => 'ignored',

                    'error_message' =>
                        'Signing request not found.',

                    'processed_at' => now(),
                ]);

                return [
                    'duplicate' => false,
                    'status' => 'ignored',
                    'event' => $eventType,
                ];
            }

            $webhookEvent->update([
                'signing_request_id' =>
                    $signingRequest->id,
            ]);

            match ($eventType) {
                'doc.signed' =>
                    $this->handleSigned(
                        $signingRequest,
                        $document,
                        $providerEventId
                    ),

                'doc.sign.failed' =>
                    $this->handleFailed(
                        $signingRequest,
                        $document,
                        $providerEventId,
                        'failed'
                    ),

                'doc.sign.rejected' =>
                    $this->handleFailed(
                        $signingRequest,
                        $document,
                        $providerEventId,
                        'rejected'
                    ),

                default => null,
            };

            $webhookEvent->update([
                'status' => 'processed',
                'error_message' => null,
                'processed_at' => now(),
            ]);

            return [
                'duplicate' => false,
                'status' => 'processed',
                'event' => $eventType,

                'signing_request_uuid' =>
                    $signingRequest->uuid,
            ];

        } catch (Throwable $exception) {
            $this->markFailed(
                $webhookEvent,
                'Webhook processing failed.'
            );

            Log::error(
                'Digio webhook processing failed.',
                [
                    'webhook_event_uuid' =>
                        $webhookEvent->uuid,

                    'provider_event_id' =>
                        $providerEventId,

                    'event_type' =>
                        $eventType,

                    'exception_class' =>
                        $exception::class,
                ]
            );

            throw $exception;
        }
    }

    private function handleSigned(
        SigningRequest $signingRequest,
        array $document,
        string $providerEventId
    ): void {
        /*
         * Never downgrade completed request.
         */
        if ($signingRequest->status === 'completed') {
            $signingRequest->update([
                'webhook_event_id' =>
                    $providerEventId,

                'last_synced_at' =>
                    now(),
            ]);

            return;
        }

        $providerStatus = strtolower(
            trim(
                (string) (
                    $document['agreement_status']
                    ?? 'completed'
                )
            )
        );

        $signingRequest->update([
            'provider_status' =>
                $providerStatus !== ''
                    ? $providerStatus
                    : 'completed',

            /*
             * Important:
             *
             * Digio says signed, but local signed PDF has
             * not yet been downloaded/stored.
             *
             * Step 7 will make it completed only after
             * the PDF is safely persisted.
             */
            'status' => 'in_progress',

            'webhook_event_id' =>
                $providerEventId,

            'error_message' => null,
            'failed_at' => null,
            'last_synced_at' => now(),
        ]);
    }

    private function handleFailed(
        SigningRequest $signingRequest,
        array $document,
        string $providerEventId,
        string $fallbackStatus
    ): void {
        /*
         * Stale failure webhook must never downgrade
         * an already completed document.
         */
        if ($signingRequest->status === 'completed') {
            $signingRequest->update([
                'webhook_event_id' =>
                    $providerEventId,

                'last_synced_at' =>
                    now(),
            ]);

            return;
        }

        $providerStatus = strtolower(
            trim(
                (string) (
                    $document['agreement_status']
                    ?? $fallbackStatus
                )
            )
        );

        $signingRequest->update([
            'status' => 'failed',

            'provider_status' =>
                $providerStatus !== ''
                    ? $providerStatus
                    : $fallbackStatus,

            'webhook_event_id' =>
                $providerEventId,

            /*
             * Don't store arbitrary provider payload/reasons.
             */
            'error_message' =>
                $fallbackStatus === 'rejected'
                    ? 'Signing request rejected.'
                    : 'Signing request failed.',

            'failed_at' =>
                $signingRequest->failed_at
                ?? now(),

            'last_synced_at' =>
                now(),
        ]);
    }

    private function reserveEvent(
        string $providerEventId,
        string $eventType,
        ?string $entityType,
        ?string $digioDocumentId,
        string $payloadHash
    ): WebhookEvent {
        $existing =
            WebhookEvent::query()
                ->where(
                    'provider_event_id',
                    $providerEventId
                )
                ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return WebhookEvent::query()
                ->create([
                    'uuid' =>
                        (string) Str::uuid(),

                    'provider_event_id' =>
                        $providerEventId,

                    'event_type' =>
                        $eventType,

                    'entity_type' =>
                        $entityType,

                    'digio_doc_id' =>
                        $digioDocumentId,

                    'payload_hash' =>
                        $payloadHash,

                    'status' =>
                        'received',

                    'received_at' =>
                        now(),
                ]);

        } catch (QueryException $exception) {
            /*
             * Unique provider_event_id handles concurrency.
             */
            $existing =
                WebhookEvent::query()
                    ->where(
                        'provider_event_id',
                        $providerEventId
                    )
                    ->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function extractEntityType(
        array $data
    ): ?string {
        $entities = $data['entities']
            ?? null;

        if (
            is_array($entities)
            && isset($entities[0])
        ) {
            return Str::limit(
                (string) $entities[0],
                50,
                ''
            );
        }

        return null;
    }

    private function markFailed(
        WebhookEvent $event,
        string $message
    ): void {
        $event->update([
            'status' => 'failed',

            'error_message' =>
                Str::limit(
                    $message,
                    1000,
                    ''
                ),
        ]);
    }
}