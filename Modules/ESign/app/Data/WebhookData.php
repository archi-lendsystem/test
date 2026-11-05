<?php

namespace Modules\ESign\Data;

use Carbon\CarbonImmutable;
use Modules\ESign\Enums\SessionStatus;

final class WebhookData
{
    public function __construct(
        public readonly ?string $eventId,
        public readonly ?string $referenceKey,
        public readonly ?string $requestId,
        public readonly ?string $documentId,
        public readonly SessionStatus $status,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly array $payload,
    ) {
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'reference_key' => $this->referenceKey,
            'request_id' => $this->requestId,
            'document_id' => $this->documentId,
            'status' => $this->status->value,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'payload' => $this->payload,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            eventId: $data['event_id'] ?? null,
            referenceKey: $data['reference_key'] ?? null,
            requestId: $data['request_id'] ?? null,
            documentId: $data['document_id'] ?? null,
            status: SessionStatus::from($data['status']),
            occurredAt: empty($data['occurred_at']) ? null : CarbonImmutable::parse($data['occurred_at']),
            payload: (array) ($data['payload'] ?? []),
        );
    }
}
