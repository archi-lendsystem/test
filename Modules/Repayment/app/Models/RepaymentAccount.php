<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\BLAmortization\Models\BLAmortization;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Repayment\Enums\RepaymentAccountStatus;

class RepaymentAccount extends Model
{
    use HasUuids;

    /**
     * Database table represented by this model.
     */
    protected $table = 'repayment_accounts';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'loan_type',
        'lead_uuid',
        'disbursement_id',
        'amortization_id',
        'loan_number',
        'schedule_version',

        'principal_amount',
        'net_disbursed_amount',
        'interest_rate',
        'tenure',
        'tenure_unit',
        'frequency',
        'installment_count',
        'total_interest',
        'total_payable',

        'principal_outstanding',
        'interest_outstanding',
        'total_outstanding',
        'overdue_amount',
        'advance_amount',
        'total_received',

        'first_due_date',
        'next_due_date',
        'maturity_date',
        'last_payment_at',

        'status',
        'financial_snapshot',
        'activation_error',
        'activated_at',
        'closed_at',

        'created_by',
        'updated_by',
    ];

    /**
     * Default values used before database persistence.
     */
    protected $attributes = [
        'loan_type' => 'BL',
        'schedule_version' => 1,

        'principal_outstanding' => 0,
        'interest_outstanding' => 0,
        'total_outstanding' => 0,
        'overdue_amount' => 0,
        'advance_amount' => 0,
        'total_received' => 0,

        'status' => 'ACTIVATION_PENDING',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'disbursement_id' => 'integer',
            'amortization_id' => 'integer',
            'schedule_version' => 'integer',
            'tenure' => 'decimal:2',
            'installment_count' => 'integer',

            'principal_amount' => 'decimal:2',
            'net_disbursed_amount' => 'decimal:2',
            'interest_rate' => 'decimal:6',
            'total_interest' => 'decimal:2',
            'total_payable' => 'decimal:2',

            'principal_outstanding' => 'decimal:2',
            'interest_outstanding' => 'decimal:2',
            'total_outstanding' => 'decimal:2',
            'overdue_amount' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'total_received' => 'decimal:2',

            'first_due_date' => 'date',
            'next_due_date' => 'date',
            'maturity_date' => 'date',

            'last_payment_at' => 'datetime',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',

            'status' => RepaymentAccountStatus::class,
            'financial_snapshot' => 'array',

            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /**
     * Generate UUID for the uuid column while retaining
     * the numeric auto-incrementing id as primary key.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Use UUID instead of numeric ID in route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Repayment schedule installments.
     */
    public function installments(): HasMany
    {
        return $this->hasMany(
            RepaymentInstallment::class,
            'repayment_account_id'
        );
    }

    /**
     * Bounce/penal/foreclosure charges levied on this account.
     */
    public function charges(): HasMany
    {
        return $this->hasMany(
            RepaymentCharge::class,
            'repayment_account_id'
        );
    }

    /**
     * All payment, reversal, refund and adjustment transactions.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(
            RepaymentTransaction::class,
            'repayment_account_id'
        );
    }

    /**
     * Collections follow-up / PTP activity log (Modules/FollowUpPTP).
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(
            \Modules\FollowUpPTP\Models\FollowUp::class,
            'repayment_account_id'
        );
    }

    /**
     * No Objection Certificate issued once this account is closed
     * (Modules/NOC) — one per account.
     */
    public function nocCertificate(): HasOne
    {
        return $this->hasOne(
            \Modules\NOC\Models\NocCertificate::class,
            'repayment_account_id'
        );
    }

    /**
     * Payment-demand notices issued against this account (Modules/DemandNotice).
     */
    public function demandNotices(): HasMany
    {
        return $this->hasMany(
            \Modules\DemandNotice\Models\DemandNotice::class,
            'repayment_account_id'
        );
    }

    /**
     * Customer-shareable EMI statements generated for this account
     * (Modules/EmiStatement).
     */
    public function emiStatements(): HasMany
    {
        return $this->hasMany(
            \Modules\EmiStatement\Models\EmiStatement::class,
            'repayment_account_id'
        );
    }

    /**
     * Account and installment status audit history.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(
            RepaymentStatusHistory::class,
            'repayment_account_id'
        );
    }

    /**
     * Query only active repayment accounts.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(
            'status',
            RepaymentAccountStatus::ACTIVE->value
        );
    }

    /**
     * Query accounts waiting for activation.
     */
    public function scopeActivationPending(Builder $query): Builder
    {
        return $query->where(
            'status',
            RepaymentAccountStatus::ACTIVATION_PENDING->value
        );
    }

    /**
     * Check whether a payment can be posted.
     */
    public function allowsPayment(): bool
    {
        return $this->status->allowsPayment();
    }

    /**
     * Check whether financial outstanding remains.
     */
    public function hasOutstanding(): bool
    {
        return bccomp(
            (string) $this->total_outstanding,
            '0.00',
            2
        ) === 1;
    }

    /**
     * Check whether account is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === RepaymentAccountStatus::CLOSED;
    }

    /**
     * Original BL lead linked through lead UUID.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    /**
     * Disbursement used to activate this repayment account.
     */
    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(
            BLDisbursment::class,
            'disbursement_id',
            'id'
        );
    }

    /**
     * Amortization schedule from which installments were imported.
     */
    public function amortization(): BelongsTo
    {
        return $this->belongsTo(
            BLAmortization::class,
            'amortization_id',
            'id'
        )->withTrashed();
    }
}