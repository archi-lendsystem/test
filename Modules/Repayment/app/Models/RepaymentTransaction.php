<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Repayment\Enums\PaymentMode;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;

class RepaymentTransaction extends Model
{
    use HasUuids;

    /**
     * Database table represented by this model.
     */
    protected $table = 'repayment_transactions';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'repayment_account_id',

        'transaction_number',
        'idempotency_key',
        'transaction_type',

        'amount',
        'allocated_amount',
        'unallocated_amount',

        'payment_mode',
        'transaction_reference',

        'received_date',
        'value_date',
        'posted_at',

        'status',

        'reversal_of_transaction_id',

        'remarks',
        'metadata',

        'created_by',
        'approved_by',
    ];

    /**
     * Default values used before persistence.
     */
    protected $attributes = [
        'transaction_type' => 'PAYMENT',

        'allocated_amount' => 0,
        'unallocated_amount' => 0,

        'status' => 'PENDING',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',
            'reversal_of_transaction_id' => 'integer',

            'transaction_type' => RepaymentTransactionType::class,
            'status' => RepaymentTransactionStatus::class,
            'payment_mode' => PaymentMode::class,

            'amount' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
            'unallocated_amount' => 'decimal:2',

            'received_date' => 'date',
            'value_date' => 'date',
            'posted_at' => 'datetime',

            'metadata' => 'array',

            'created_by' => 'integer',
            'approved_by' => 'integer',
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
     * Parent repayment account.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentAccount::class,
            'repayment_account_id'
        );
    }

    /**
     * Component-wise installment allocations.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(
            RepaymentAllocation::class,
            'repayment_transaction_id'
        );
    }

    /**
     * Receipt generated for this transaction.
     *
     * Database constraint allows maximum one receipt
     * per transaction.
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(
            RepaymentReceipt::class,
            'repayment_transaction_id'
        );
    }

    /**
     * Original transaction referenced by a reversal.
     *
     * Normal payment transactions mein ye null rahega.
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'reversal_of_transaction_id'
        );
    }

    /**
     * Reversal transaction created against this transaction.
     *
     * Unique database constraint ke according maximum
     * one reversal transaction ho sakti hai.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(
            self::class,
            'reversal_of_transaction_id'
        );
    }

    /**
     * Status history records related to this transaction.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(
            RepaymentStatusHistory::class,
            'repayment_transaction_id'
        );
    }

    /**
     * Query pending transactions.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where(
            'status',
            RepaymentTransactionStatus::PENDING->value
        );
    }

    /**
     * Query successfully posted transactions.
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where(
            'status',
            RepaymentTransactionStatus::POSTED->value
        );
    }

    /**
     * Query normal customer payments.
     */
    public function scopePayments(Builder $query): Builder
    {
        return $query->where(
            'transaction_type',
            RepaymentTransactionType::PAYMENT->value
        );
    }

    /**
     * Query reversal transactions.
     */
    public function scopeReversals(Builder $query): Builder
    {
        return $query->where(
            'transaction_type',
            RepaymentTransactionType::REVERSAL->value
        );
    }

    /**
     * Query transactions by their received date.
     */
    public function scopeReceivedOn(
        Builder $query,
        string $date
    ): Builder {
        return $query->whereDate('received_date', $date);
    }

    /**
     * Check whether this transaction can enter
     * the posting process.
     */
    public function canBePosted(): bool
    {
        return $this->status->canBePosted();
    }

    /**
     * Check whether transaction has been posted.
     */
    public function isPosted(): bool
    {
        return $this->status->isPosted();
    }

    /**
     * Check whether this record is a reversal transaction.
     */
    public function isReversal(): bool
    {
        return $this->transaction_type
            === RepaymentTransactionType::REVERSAL;
    }

    /**
     * Check basic reversal eligibility.
     *
     * Duplicate-reversal prevention will also be checked
     * inside the reversal service with a database lock.
     */
    public function canBeReversed(): bool
    {
        return $this->transaction_type
                === RepaymentTransactionType::PAYMENT
            && $this->status
                === RepaymentTransactionStatus::POSTED;
    }

    /**
     * Check whether any transaction amount remains
     * unallocated as customer advance.
     */
    public function hasUnallocatedAmount(): bool
    {
        return bccomp(
            (string) $this->unallocated_amount,
            '0.00',
            2
        ) === 1;
    }

    /**
     * Check whether the complete transaction amount
     * has been allocated to installments.
     */
    public function isFullyAllocated(): bool
    {
        return bccomp(
            (string) $this->allocated_amount,
            (string) $this->amount,
            2
        ) === 0;
    }

    /**
     * Calculate amount that still needs allocation.
     *
     * This calculation does not mutate the model.
     */
    public function remainingToAllocate(): string
    {
        $remaining = bcsub(
            (string) $this->amount,
            (string) $this->allocated_amount,
            2
        );

        return bccomp($remaining, '0.00', 2) === -1
            ? '0.00'
            : $remaining;
    }
}