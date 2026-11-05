<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;

class RepaymentStatusHistory extends Model
{
    use HasUuids;

    /**
     * Database table represented by this model.
     */
    protected $table = 'repayment_status_histories';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'repayment_account_id',
        'repayment_installment_id',
        'repayment_transaction_id',

        'status_type',
        'old_status',
        'new_status',
        'source',

        'reason',
        'metadata',

        'changed_by',
        'changed_at',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',
            'repayment_installment_id' => 'integer',
            'repayment_transaction_id' => 'integer',

            'status_type' => StatusHistoryType::class,
            'source' => StatusHistorySource::class,

            'metadata' => 'array',

            'changed_by' => 'integer',
            'changed_at' => 'datetime',
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
     * Optional installment affected by the status change.
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentInstallment::class,
            'repayment_installment_id'
        );
    }

    /**
     * Optional transaction responsible for the status change.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentTransaction::class,
            'repayment_transaction_id'
        );
    }

    /**
     * Query account-level status history.
     */
    public function scopeAccountStatus(
        Builder $query
    ): Builder {
        return $query->where(
            'status_type',
            StatusHistoryType::ACCOUNT_STATUS->value
        );
    }

    /**
     * Query installment payment-status history.
     */
    public function scopePaymentStatus(
        Builder $query
    ): Builder {
        return $query->where(
            'status_type',
            StatusHistoryType::PAYMENT_STATUS->value
        );
    }

    /**
     * Query installment due-status history.
     *
     * DPD calculations are not included.
     */
    public function scopeDueStatus(
        Builder $query
    ): Builder {
        return $query->where(
            'status_type',
            StatusHistoryType::DUE_STATUS->value
        );
    }

    /**
     * Query history created by a particular source.
     */
    public function scopeFromSource(
        Builder $query,
        StatusHistorySource $source
    ): Builder {
        return $query->where(
            'source',
            $source->value
        );
    }

    /**
     * Return history in chronological order.
     */
    public function scopeChronological(
        Builder $query
    ): Builder {
        return $query
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    /**
     * Return latest history first.
     */
    public function scopeLatestFirst(
        Builder $query
    ): Builder {
        return $query
            ->orderByDesc('changed_at')
            ->orderByDesc('id');
    }

    /**
     * Check whether this is an account-level change.
     */
    public function isAccountLevel(): bool
    {
        return $this->status_type->isAccountLevel();
    }

    /**
     * Check whether this is an installment-level change.
     */
    public function isInstallmentLevel(): bool
    {
        return $this->status_type->isInstallmentLevel();
    }

    /**
     * Check whether this history record should have
     * an installment reference.
     */
    public function requiresInstallment(): bool
    {
        return $this->status_type->requiresInstallment();
    }

    /**
     * Check whether source is transaction-related.
     */
    public function requiresTransaction(): bool
    {
        return $this->source->requiresTransaction();
    }

    /**
     * Check whether status value actually changed.
     *
     * Initial status history can have null old_status.
     */
    public function hasStatusChanged(): bool
    {
        return $this->old_status === null
            || $this->old_status !== $this->new_status;
    }

    /**
     * Human-readable transition.
     */
    public function transitionLabel(): string
    {
        $oldStatus = $this->old_status ?? 'NONE';

        return sprintf(
            '%s → %s',
            $oldStatus,
            $this->new_status
        );
    }
}