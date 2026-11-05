<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Repayment\Enums\InstallmentDueStatus;
use Modules\Repayment\Enums\InstallmentPaymentStatus;

class RepaymentInstallment extends Model
{
    use HasUuids;

    /**
     * Database table represented by this model.
     */
    protected $table = 'repayment_installments';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'repayment_account_id',
        'amortization_id',
        'schedule_version',
        'installment_number',
        'due_date',

        'opening_principal',
        'principal_due',
        'interest_due',
        'installment_amount',
        'closing_principal',

        'principal_paid',
        'interest_paid',
        'outstanding_amount',

        'payment_status',
        'due_status',

        'last_payment_at',
        'fully_paid_at',
        'schedule_snapshot',
    ];

    /**
     * Default values used before persistence.
     */
    protected $attributes = [
        'schedule_version' => 1,

        'principal_paid' => 0,
        'interest_paid' => 0,
        'outstanding_amount' => 0,

        'payment_status' => 'UNPAID',
        'due_status' => 'UPCOMING',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',
            'amortization_id' => 'integer',
            'schedule_version' => 'integer',
            'installment_number' => 'integer',

            'due_date' => 'date',

            'opening_principal' => 'decimal:2',
            'principal_due' => 'decimal:2',
            'interest_due' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'closing_principal' => 'decimal:2',

            'principal_paid' => 'decimal:2',
            'interest_paid' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',

            'payment_status' => InstallmentPaymentStatus::class,
            'due_status' => InstallmentDueStatus::class,

            'last_payment_at' => 'datetime',
            'fully_paid_at' => 'datetime',

            'schedule_snapshot' => 'array',
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
     * Component-wise payment allocations.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(
            RepaymentAllocation::class,
            'repayment_installment_id'
        );
    }

    /**
     * Installment status-change audit history.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(
            RepaymentStatusHistory::class,
            'repayment_installment_id'
        );
    }

    /**
     * Query installments having financial outstanding.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('outstanding_amount', '>', 0);
    }

    /**
     * Query installments that are not fully paid.
     */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('payment_status', [
            InstallmentPaymentStatus::UNPAID->value,
            InstallmentPaymentStatus::PARTIAL->value,
        ]);
    }

    /**
     * Query fully paid installments.
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where(
            'payment_status',
            InstallmentPaymentStatus::PAID->value
        );
    }

    /**
     * Query overdue installments.
     *
     * This does not calculate DPD.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where(
                'due_status',
                InstallmentDueStatus::OVERDUE->value
            )
            ->where('outstanding_amount', '>', 0);
    }

    /**
     * Default payment-allocation order.
     *
     * Oldest installment will be processed first.
     */
    public function scopeAllocationOrder(Builder $query): Builder
    {
        return $query
            ->orderBy('due_date')
            ->orderBy('installment_number');
    }

    /**
     * Remaining principal component.
     */
    public function principalOutstanding(): string
    {
        return $this->nonNegativeDifference(
            (string) $this->principal_due,
            (string) $this->principal_paid
        );
    }

    /**
     * Remaining interest component.
     */
    public function interestOutstanding(): string
    {
        return $this->nonNegativeDifference(
            (string) $this->interest_due,
            (string) $this->interest_paid
        );
    }

    /**
     * Check whether any installment amount remains unpaid.
     */
    public function hasOutstanding(): bool
    {
        return bccomp(
            (string) $this->outstanding_amount,
            '0.00',
            2
        ) === 1;
    }

    /**
     * Check whether installment accepts payment allocation.
     */
    public function allowsAllocation(): bool
    {
        return $this->payment_status->allowsAllocation()
            && $this->hasOutstanding();
    }

    /**
     * Check whether installment is fully paid.
     */
    public function isPaid(): bool
    {
        return $this->payment_status->isPaid();
    }

    /**
     * Check whether installment is currently overdue.
     *
     * No overdue-day count is calculated here.
     */
    public function isOverdue(): bool
    {
        return $this->due_status->isOverdue()
            && $this->hasOutstanding();
    }

    /**
     * Calculate a non-negative monetary difference.
     */
    private function nonNegativeDifference(
        string $due,
        string $paid
    ): string {
        $difference = bcsub($due, $paid, 2);

        return bccomp($difference, '0.00', 2) === -1
            ? '0.00'
            : $difference;
    }

    // access added

    protected function outstandingAmount(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value === null || $value === '') {
                    return '0.00';
                }

                return number_format(
                    round(
                        (float) $value,
                        0,
                        PHP_ROUND_HALF_UP
                    ),
                    2,
                    '.',
                    ''
                );
            }
        );
    }
}




// namespace Modules\Repayment\Models;

// use Illuminate\Database\Eloquent\Builder;
// use Illuminate\Database\Eloquent\Concerns\HasUuids;
// use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\Relations\BelongsTo;
// use Illuminate\Database\Eloquent\Relations\HasMany;
// use Modules\Repayment\Enums\InstallmentDueStatus;
// use Modules\Repayment\Enums\InstallmentPaymentStatus;
// use Illuminate\Database\Eloquent\Casts\Attribute;

// class RepaymentInstallment extends Model
// {
//     use HasUuids;

//     /**
//      * Database table represented by this model.
//      */
//     protected $table = 'repayment_installments';

//     /**
//      * Mass-assignable attributes.
//      */
//     protected $fillable = [
//         'repayment_account_id',
//         'amortization_id',
//         'schedule_version',
//         'installment_number',
//         'due_date',

//         'opening_principal',
//         'principal_due',
//         'interest_due',
//         'installment_amount',
//         'closing_principal',

//         'principal_paid',
//         'interest_paid',
//         'outstanding_amount',

//         'payment_status',
//         'due_status',

//         'last_payment_at',
//         'fully_paid_at',
//         'schedule_snapshot',
//     ];

//     /**
//      * Default values used before persistence.
//      */
//     protected $attributes = [
//         'schedule_version' => 1,

//         'principal_paid' => 0,
//         'interest_paid' => 0,
//         'outstanding_amount' => 0,

//         'payment_status' => 'UNPAID',
//         'due_status' => 'UPCOMING',
//     ];

//     /**
//      * Attribute casts.
//      */
//     protected function casts(): array
//     {
//         return [
//             'repayment_account_id' => 'integer',
//             'amortization_id' => 'integer',
//             'schedule_version' => 'integer',
//             'installment_number' => 'integer',

//             'due_date' => 'date',

//             'opening_principal' => 'decimal:2',
//             'principal_due' => 'decimal:2',
//             'interest_due' => 'decimal:2',
//             'installment_amount' => 'decimal:2',
//             'closing_principal' => 'decimal:2',

//             'principal_paid' => 'decimal:2',
//             'interest_paid' => 'decimal:2',
//             'outstanding_amount' => 'decimal:2',

//             'payment_status' => InstallmentPaymentStatus::class,
//             'due_status' => InstallmentDueStatus::class,

//             'last_payment_at' => 'datetime',
//             'fully_paid_at' => 'datetime',

//             'schedule_snapshot' => 'array',
//         ];
//     }

//     /**
//      * Generate UUID for the uuid column while retaining
//      * numeric auto-incrementing ID as primary key.
//      *
//      * @return array<int, string>
//      */
//     public function uniqueIds(): array
//     {
//         return ['uuid'];
//     }

//     /**
//      * Use UUID for route model binding.
//      */
//     public function getRouteKeyName(): string
//     {
//         return 'uuid';
//     }

//     /**
//      * Parent repayment account.
//      */
//     public function account(): BelongsTo
//     {
//         return $this->belongsTo(
//             RepaymentAccount::class,
//             'repayment_account_id'
//         );
//     }

//     /**
//      * Component-wise payment allocations.
//      */
//     public function allocations(): HasMany
//     {
//         return $this->hasMany(
//             RepaymentAllocation::class,
//             'repayment_installment_id'
//         );
//     }

//     /**
//      * Installment status-change audit history.
//      */
//     public function statusHistories(): HasMany
//     {
//         return $this->hasMany(
//             RepaymentStatusHistory::class,
//             'repayment_installment_id'
//         );
//     }

//     /**
//      * Query installments having financial outstanding.
//      */
//     public function scopeOutstanding(Builder $query): Builder
//     {
//         return $query->where('outstanding_amount', '>', 0);
//     }

//     /**
//      * Query installments that are not fully paid.
//      */
//     public function scopeUnpaid(Builder $query): Builder
//     {
//         return $query->whereIn('payment_status', [
//             InstallmentPaymentStatus::UNPAID->value,
//             InstallmentPaymentStatus::PARTIAL->value,
//         ]);
//     }

//     /**
//      * Query fully paid installments.
//      */
//     public function scopePaid(Builder $query): Builder
//     {
//         return $query->where(
//             'payment_status',
//             InstallmentPaymentStatus::PAID->value
//         );
//     }

//     /**
//      * Query overdue installments.
//      *
//      * This does not calculate DPD.
//      */
//     public function scopeOverdue(Builder $query): Builder
//     {
//         return $query
//             ->where(
//                 'due_status',
//                 InstallmentDueStatus::OVERDUE->value
//             )
//             ->where('outstanding_amount', '>', 0);
//     }

//     /**
//      * Default payment-allocation order.
//      *
//      * Oldest installment will be processed first.
//      */
//     public function scopeAllocationOrder(Builder $query): Builder
//     {
//         return $query
//             ->orderBy('due_date')
//             ->orderBy('installment_number');
//     }

//     /**
//      * Remaining principal component.
//      */
//     public function principalOutstanding(): string
//     {
//         return $this->nonNegativeDifference(
//             (string) $this->principal_due,
//             (string) $this->principal_paid
//         );
//     }

//     /**
//      * Remaining interest component.
//      */
//     public function interestOutstanding(): string
//     {
//         return $this->nonNegativeDifference(
//             (string) $this->interest_due,
//             (string) $this->interest_paid
//         );
//     }

//     /**
//      * Check whether any installment amount remains unpaid.
//      */
//     public function hasOutstanding(): bool
//     {
//         return bccomp(
//             (string) $this->outstanding_amount,
//             '0.00',
//             2
//         ) === 1;
//     }

//     /**
//      * Check whether installment accepts payment allocation.
//      */
//     public function allowsAllocation(): bool
//     {
//         return $this->payment_status->allowsAllocation()
//             && $this->hasOutstanding();
//     }

//     /**
//      * Check whether installment is fully paid.
//      */
//     public function isPaid(): bool
//     {
//         return $this->payment_status->isPaid();
//     }

//     /**
//      * Check whether installment is currently overdue.
//      *
//      * No overdue-day count is calculated here.
//      */
//     public function isOverdue(): bool
//     {
//         return $this->due_status->isOverdue()
//             && $this->hasOutstanding();
//     }

//     /**
//      * Calculate a non-negative monetary difference.
//      */
//     private function nonNegativeDifference(
//         string $due,
//         string $paid
//     ): string {
//         $difference = bcsub($due, $paid, 2);

//         return bccomp($difference, '0.00', 2) === -1
//             ? '0.00'
//             : $difference;
//     }

//     //access added

//     // protected function installmentAmount(): Attribute
//     // {
//     //     return Attribute::make(
//     //         get: fn($value) => round((float) $value)
//     //     );
//     // }

//     // protected function outstandingAmount(): Attribute
//     // {
//     //     return Attribute::make(
//     //         get: fn($value) => round((float) $value)
//     //     );
//     // }
// }
