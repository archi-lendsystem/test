<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Repayment\Enums\RepaymentImportRowStatus;

class RepaymentImportInstallment extends Model
{
    /**
     * Database table.
     *
     * This is a bulk-import staging/audit table.
     * It is NOT the live repayment_installments table.
     */
    protected $table = 'repayment_import_installments';

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'repayment_import_row_id',

        'excel_column',

        'repayment_installment_uuid',
        'installment_number',

        'snapshot_schedule_version',
        'snapshot_due_date',

        'snapshot_installment_amount',
        'snapshot_principal_due',
        'snapshot_interest_due',

        'snapshot_principal_paid',
        'snapshot_interest_paid',

        'snapshot_outstanding_amount',

        'snapshot_payment_status',
        'snapshot_due_status',

        'collection_amount',

        'validation_status',
        'validation_errors',

        'raw_payload',
        'metadata',

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
            'repayment_import_row_id' => 'integer',

            'installment_number' => 'integer',
            'snapshot_schedule_version' => 'integer',

            'snapshot_due_date' => 'date',

            'snapshot_installment_amount' => 'decimal:2',
            'snapshot_principal_due' => 'decimal:2',
            'snapshot_interest_due' => 'decimal:2',

            'snapshot_principal_paid' => 'decimal:2',
            'snapshot_interest_paid' => 'decimal:2',

            'snapshot_outstanding_amount' => 'decimal:2',

            'collection_amount' => 'decimal:2',

            'validation_status' =>
                RepaymentImportRowStatus::class,

            'validation_errors' => 'array',

            'raw_payload' => 'array',
            'metadata' => 'array',

            'imported_at' => 'datetime',
        ];
    }

    /**
     * Parent Excel/account import row.
     */
    public function row(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentImportRow::class,
            'repayment_import_row_id'
        );
    }

    /**
     * Live repayment installment represented by this
     * imported EMI entry.
     *
     * This is a logical UUID relationship only.
     *
     * No database foreign key exists between
     * repayment_import_installments and
     * repayment_installments because invalid/stale
     * Excel values must remain stageable for validation
     * and audit purposes.
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(
            RepaymentInstallment::class,
            'repayment_installment_uuid',
            'uuid'
        );
    }

    /**
     * Check whether this imported EMI passed validation.
     */
    public function isValid(): bool
    {
        return $this->validation_status->isValid();
    }

    /**
     * Check whether this imported EMI contains errors.
     */
    public function hasErrors(): bool
    {
        return $this->validation_status->hasError()
            || ! empty($this->validation_errors);
    }

    /**
     * Check whether this EMI collection was successfully
     * included in the final bulk import.
     */
    public function isImported(): bool
    {
        return $this->imported_at !== null;
    }
}