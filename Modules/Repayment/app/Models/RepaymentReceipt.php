<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Repayment\Enums\ReceiptStatus;

class RepaymentReceipt extends Model
{
    use HasUuids;

    /**
     * Database table represented by this model.
     */
    protected $table = 'repayment_receipts';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'repayment_transaction_id',

        'receipt_number',
        'receipt_date',
        'status',

        'storage_disk',
        'file_path',
        'file_hash',

        'generated_by',
        'generated_at',

        'voided_by',
        'voided_at',
        'void_reason',

        'metadata',
    ];

    /**
     * Default values used before persistence.
     */
    protected $attributes = [
        'status' => 'ISSUED',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'repayment_transaction_id' => 'integer',

            'receipt_date' => 'date',
            'status' => ReceiptStatus::class,

            'generated_by' => 'integer',
            'generated_at' => 'datetime',

            'voided_by' => 'integer',
            'voided_at' => 'datetime',

            'metadata' => 'array',
        ];
    }

    /**
     * Generate UUID for the uuid column while retaining
     * numeric auto-incrementing ID as primary key.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Use UUID for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Parent repayment transaction.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentTransaction::class,
            'repayment_transaction_id'
        );
    }

    /**
     * Query issued receipts.
     */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->where(
            'status',
            ReceiptStatus::ISSUED->value
        );
    }

    /**
     * Query void receipts.
     */
    public function scopeVoid(Builder $query): Builder
    {
        return $query->where(
            'status',
            ReceiptStatus::VOID->value
        );
    }

    /**
     * Query receipts created on a specific date.
     */
    public function scopeReceiptDate(
        Builder $query,
        string $date
    ): Builder {
        return $query->whereDate('receipt_date', $date);
    }

    /**
     * Check whether receipt is currently valid.
     */
    public function isValid(): bool
    {
        return $this->status->isValid();
    }

    /**
     * Check whether receipt can be voided.
     */
    public function canBeVoided(): bool
    {
        return $this->status->canBeVoided();
    }

    /**
     * Check whether receipt has been voided.
     */
    public function isVoid(): bool
    {
        return $this->status->isVoid();
    }

    /**
     * Check whether generated receipt file information exists.
     */
    public function hasFile(): bool
    {
        return filled($this->storage_disk)
            && filled($this->file_path);
    }

    /**
     * Check whether receipt file hash is available
     * for integrity verification.
     */
    public function hasFileHash(): bool
    {
        return filled($this->file_hash);
    }

    /**
     * Build the storage path reference.
     *
     * This does not check whether the physical file exists.
     */
    public function storageReference(): ?string
    {
        if (! $this->hasFile()) {
            return null;
        }

        return sprintf(
            '%s://%s',
            $this->storage_disk,
            ltrim($this->file_path, '/')
        );
    }
}