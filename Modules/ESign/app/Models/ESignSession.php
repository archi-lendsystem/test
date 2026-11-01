<?php

namespace Modules\ESign\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ESign\Enums\SessionStatus;
use Modules\ESign\Enums\SignerType;

class ESignSession extends Model
{
    use HasFactory;

    protected $table = 'esign_sessions';

    protected $fillable = [
        'uuid',
        'esign_document_id',
        'signer_type',
        'attempt_no',
        'template_id',
        'signer_details',
        'provider',
        'provider_request_id',
        'provider_doc_id',
        'reference_key',
        'esign_url',
        'status',
        'failure_reason',
        'expires_at',
        'signed_at',
        'last_synced_at',
        'metadata',
    ];

    protected $casts = [
        'signer_type' => SignerType::class,
        'status' => SessionStatus::class,
        'signer_details' => 'encrypted:array',
        'esign_url' => 'encrypted',
        'expires_at' => 'immutable_datetime',
        'signed_at' => 'immutable_datetime',
        'last_synced_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'signer_details',
        'esign_url',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ESignDocument::class, 'esign_document_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ESignLog::class, 'esign_session_id');
    }

    public function hasUsableUrl(): bool
    {
        return $this->status->isActive()
            && $this->esign_url !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
