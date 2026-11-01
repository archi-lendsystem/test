<?php

namespace Modules\ESign\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ESignWebhookEvent extends Model
{
    use HasFactory;

    protected $table = 'esign_webhook_events';

    protected $fillable = [
        'provider',
        'provider_event_id',
        'payload_hash',
        'payload',
        'status',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'encrypted:array',
        'processed_at' => 'immutable_datetime',
    ];
}
