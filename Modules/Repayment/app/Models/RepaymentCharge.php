<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Repayment\Enums\RepaymentChargeStatus;
use Modules\Repayment\Enums\RepaymentChargeType;
use Modules\UserManagement\Models\User;

class RepaymentCharge extends Model
{
    use HasUuids;

    protected $table = 'repayment_charges';

    protected $fillable = [
        'repayment_account_id',
        'repayment_installment_id',
        'repayment_transaction_id',

        'charge_type',
        'charge_date',

        'base_amount',
        'gst_percentage',
        'gst_amount',
        'total_amount',

        'waived_amount',
        'net_payable',
        'status',

        'reason',
        'metadata',

        'levied_by',
        'waived_by',
        'waived_at',
        'waiver_reason',
    ];

    protected $attributes = [
        'waived_amount' => 0,
        'status' => 'PENDING',
    ];

    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',
            'repayment_installment_id' => 'integer',
            'repayment_transaction_id' => 'integer',

            'charge_type' => RepaymentChargeType::class,
            'status' => RepaymentChargeStatus::class,

            'charge_date' => 'date',

            'base_amount' => 'decimal:2',
            'gst_percentage' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'waived_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',

            'metadata' => 'array',

            'levied_by' => 'integer',
            'waived_by' => 'integer',
            'waived_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(RepaymentAccount::class, 'repayment_account_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(RepaymentInstallment::class, 'repayment_installment_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(RepaymentTransaction::class, 'repayment_transaction_id');
    }

    public function leviedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'levied_by');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }
}
