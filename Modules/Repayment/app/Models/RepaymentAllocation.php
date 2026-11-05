<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Repayment\Enums\AllocationComponent;

class RepaymentAllocation extends Model
{
    use HasUuids;

    /**
     * Database table represented by this model.
     */
    protected $table = 'repayment_allocations';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'repayment_transaction_id',
        'repayment_installment_id',
        'component',
        'amount',
        'allocation_order',
        'metadata',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'repayment_transaction_id' => 'integer',
            'repayment_installment_id' => 'integer',

            'component' => AllocationComponent::class,

            'amount' => 'decimal:2',
            'allocation_order' => 'integer',

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
     * Installment receiving this allocation.
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentInstallment::class,
            'repayment_installment_id'
        );
    }

    /**
     * Return allocations in waterfall execution order.
     */
    public function scopeAllocationOrder(
        Builder $query
    ): Builder {
        return $query->orderBy('allocation_order');
    }

    /**
     * Query interest allocations only.
     */
    public function scopeInterest(
        Builder $query
    ): Builder {
        return $query->where(
            'component',
            AllocationComponent::INTEREST->value
        );
    }

    /**
     * Query principal allocations only.
     */
    public function scopePrincipal(
        Builder $query
    ): Builder {
        return $query->where(
            'component',
            AllocationComponent::PRINCIPAL->value
        );
    }

    /**
     * Check whether this allocation belongs to interest.
     */
    public function isInterest(): bool
    {
        return $this->component->isInterest();
    }

    /**
     * Check whether this allocation belongs to principal.
     */
    public function isPrincipal(): bool
    {
        return $this->component->isPrincipal();
    }

    /**
     * Check whether allocation amount is greater than zero.
     */
    public function hasAmount(): bool
    {
        return bccomp(
            (string) $this->amount,
            '0.00',
            2
        ) === 1;
    }
}