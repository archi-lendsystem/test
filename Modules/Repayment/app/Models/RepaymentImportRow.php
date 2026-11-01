<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Repayment\Enums\RepaymentImportRowStatus;

class RepaymentImportRow extends Model
{
    /**
     * Database table.
     */
    protected $table = 'repayment_import_rows';

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'repayment_import_id',
        'row_number',

        'repayment_account_uuid',
        'loan_number',
        'lead_uuid',

        'snapshot_amortization_id',
        'snapshot_schedule_version',

        'snapshot_total_outstanding',
        'snapshot_total_received',
        'snapshot_next_due_date',

        'amount',
        'amount_collected',
        'collection_date',
        'payment_mode',
        'transaction_reference',
        'utr_number',
        'penalty_base_amount',
        'penalty_gst_amount',
        'penalty_total_amount',
        'bounce_base_amount',
        'bounce_gst_amount',
        'bounce_total_amount',
        'emi_allocation_amount',
        'collected_by',
        'received_date',
        'value_date',
        'remarks',

        'raw_payload',

        'validation_status',
        'validation_errors',

        'repayment_transaction_id',
        'imported_at',
    ];

    /**
     * Default values.
     */
    protected $attributes = [
        'validation_status' => 'ERROR',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'repayment_import_id' => 'integer',
            'row_number' => 'integer',

            'snapshot_amortization_id' => 'integer',
            'snapshot_schedule_version' => 'integer',

            'snapshot_total_outstanding' => 'decimal:2',
            'snapshot_total_received' => 'decimal:2',
            'snapshot_next_due_date' => 'date',

            'amount' => 'decimal:2',
            'amount_collected' => 'decimal:2',
            'collection_date' => 'date',
            'penalty_base_amount' => 'decimal:2',
            'penalty_gst_amount' => 'decimal:2',
            'penalty_total_amount' => 'decimal:2',
            'bounce_base_amount' => 'decimal:2',
            'bounce_gst_amount' => 'decimal:2',
            'bounce_total_amount' => 'decimal:2',
            'emi_allocation_amount' => 'decimal:2',
            'collected_by' => 'integer',

            'received_date' => 'date',
            'value_date' => 'date',

            'raw_payload' => 'array',

            'validation_status' =>
                RepaymentImportRowStatus::class,

            'validation_errors' => 'array',

            'repayment_transaction_id' => 'integer',

            'imported_at' => 'datetime',
        ];
    }

    /**
     * Parent Excel import.
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentImport::class,
            'repayment_import_id'
        );
    }

    /**
     * EMI/installment collection entries selected inside
     * this horizontal Excel account row.
     *
     * Example:
     *
     * One repayment_import_row:
     *
     * Loan A
     *
     * may contain:
     *
     * EMI 1 => 1500
     * EMI 3 => 500
     *
     * while blank EMI cells are not stored.
     */
    public function installments(): HasMany
    {
        return $this->hasMany(
            RepaymentImportInstallment::class,
            'repayment_import_row_id'
        );
    }

    /**
     * Current repayment account resolved through UUID.
     *
     * No database foreign key is required for this relation.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentAccount::class,
            'repayment_account_uuid',
            'uuid'
        );
    }

    /**
     * Actual repayment transaction created after
     * successful final import.
     *
     * This is intentionally a logical relationship.
     * No DB foreign key was added to the existing
     * repayment_transactions table.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentTransaction::class,
            'repayment_transaction_id'
        );
    }

    /**
     * Check whether this Excel row passed validation.
     */
    public function isValid(): bool
    {
        return $this->validation_status->isValid();
    }

    /**
     * Check whether this Excel row contains errors.
     *
     * Parent row errors as well as any child EMI-level
     * validation error will make the row erroneous once
     * the installments relationship is loaded.
     */
    public function hasErrors(): bool
    {
        if (
            $this->validation_status->hasError()
            || ! empty($this->validation_errors)
        ) {
            return true;
        }

        if (
            $this->relationLoaded('installments')
            && $this->installments->contains(
                static fn (
                    RepaymentImportInstallment $installment
                ): bool => $installment->hasErrors()
            )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check whether this account row has at least one
     * selected EMI collection.
     */
    public function hasInstallmentCollections(): bool
    {
        if ($this->relationLoaded('installments')) {
            return $this->installments->isNotEmpty();
        }

        return $this->installments()->exists();
    }
}


