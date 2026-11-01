<?php

namespace Modules\ESign\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CustomerLending\Models\CustomerLending;
use Modules\ESign\Enums\DocumentStatus;
use Modules\ESign\Enums\SignerType;

class ESignDocument extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'esign_documents';

    protected $fillable = [
        'uuid',
        'customer_lending_id',
        'lead_uuid',
        'document_type',
        'version',
        'original_document_disk',
        'original_document_path',
        'borrower_signed_document_disk',
        'borrower_signed_document_path',
        'final_signed_document_disk',
        'final_signed_document_path',
        'status',
        'last_error',
        'borrower_signed_at',
        'lender_signed_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'metadata' => 'array',
        'borrower_signed_at' => 'immutable_datetime',
        'lender_signed_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customerLending(): BelongsTo
    {
        return $this->belongsTo(CustomerLending::class, 'customer_lending_id', 'customer_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ESignSession::class, 'esign_document_id');
    }

    public function borrowerSession(): HasOne
    {
        return $this->hasOne(ESignSession::class, 'esign_document_id')
            ->where('signer_type', SignerType::Borrower->value)
            ->latestOfMany('id');
    }

    public function lenderSession(): HasOne
    {
        return $this->hasOne(ESignSession::class, 'esign_document_id')
            ->where('signer_type', SignerType::Lender->value)
            ->latestOfMany('id');
    }

    public function isCompleted(): bool
    {
        return $this->status === DocumentStatus::Completed;
    }

    public function signingSourceDisk(SignerType $signerType): string
    {
        if ($signerType === SignerType::Lender) {
            return (string) $this->borrower_signed_document_disk;
        }

        return $this->original_document_disk;
    }

    public function signingSourcePath(SignerType $signerType): string
    {
        if ($signerType === SignerType::Lender) {
            return (string) $this->borrower_signed_document_path;
        }

        return $this->original_document_path;
    }
}
