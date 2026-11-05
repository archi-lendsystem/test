<?php

namespace Modules\Pan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Pan extends Model
{
    use HasFactory;
    protected $table = 'pans';

    protected $fillable = [
        'lead_uuid',
        'pan_number',
        'pan_last4',
        'pan_hash',
        'status',
        'request_id',
        'reference_key',
        'idempotency_key',
        'http_status',
        'request_payload',
        'response_payload',
        'error_payload',
        'attempt_count',
        'requested_at',
        'completed_at',
        'ip_address',
    ];

    protected $hidden = [
        'pan_number',
        'request_payload',
        'response_payload',
        'error_payload',
    ];

    protected function casts(): array
    {
        return [
            'pan_number' => 'encrypted',
            'request_payload' => 'encrypted:array',
            'response_payload' => 'encrypted:array',
            'error_payload' => 'encrypted:array',
            'http_status' => 'integer',
            'attempt_count' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }
}
