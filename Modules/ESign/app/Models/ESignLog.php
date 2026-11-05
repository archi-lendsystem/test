<?php

namespace Modules\ESign\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ESignLog extends Model
{
    use HasFactory;

    protected $table = 'esign_logs';

    protected $fillable = [
        'esign_session_id',
        'correlation_id',
        'direction',
        'action',
        'method',
        'endpoint',
        'request_payload',
        'response_payload',
        'status_code',
        'duration_ms',
        'error',
    ];

    protected $casts = [
        'request_payload' => 'encrypted:array',
        'response_payload' => 'encrypted:array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ESignSession::class, 'esign_session_id');
    }
}
