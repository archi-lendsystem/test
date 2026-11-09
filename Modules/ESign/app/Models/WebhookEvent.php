<?php

namespace Modules\ESign\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    protected $table = 'esign_webhook_events';

    protected $fillable = [
        'uuid',

        'provider_event_id',
        'event_type',
        'entity_type',

        'digio_doc_id',
        'signing_request_id',

        'payload_hash',

        'status',
        'error_message',

        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'signing_request_id' => 'integer',

        'received_at' => 'datetime',
        'processed_at' => 'datetime',

        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function signingRequest(): BelongsTo
    {
        return $this->belongsTo(
            SigningRequest::class,
            'signing_request_id'
        );
    }
}