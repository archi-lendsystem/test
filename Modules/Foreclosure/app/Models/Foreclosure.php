<?php

namespace Modules\Foreclosure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Repayment\Enums\PaymentMode;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentCharge;
use Modules\Repayment\Models\RepaymentTransaction;
use Modules\UserManagement\Models\User;

class Foreclosure extends Model
{
    use HasUuids;

    protected $table = 'foreclosures';

    protected $fillable = [
        'repayment_account_id',
        'repayment_transaction_id',
        'repayment_charge_id',
        'foreclosure_amount',
        'foreclosure_date',
        'foreclosure_charges',
        'waived_charges',
        'net_foreclosure_charges',
        'principal_outstanding',
        'interest_outstanding',
        'total_outstanding',
        'payment_mode',
        'transaction_reference',
        'remarks',
        'metadata',
        'foreclosed_by',
    ];

    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',
            'repayment_transaction_id' => 'integer',
            'repayment_charge_id' => 'integer',
            'foreclosure_amount' => 'decimal:2',
            'foreclosure_date' => 'date',
            'foreclosure_charges' => 'decimal:2',
            'waived_charges' => 'decimal:2',
            'net_foreclosure_charges' => 'decimal:2',
            'principal_outstanding' => 'decimal:2',
            'interest_outstanding' => 'decimal:2',
            'total_outstanding' => 'decimal:2',
            'payment_mode' => PaymentMode::class,
            'metadata' => 'array',
            'foreclosed_by' => 'integer',
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

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(RepaymentTransaction::class, 'repayment_transaction_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(RepaymentCharge::class, 'repayment_charge_id');
    }

  public function foreclosedBy()
{
    return $this->belongsTo(User::class, 'foreclosed_by', 'id');
}

    public function totalClosurePayable(): string
    {
        return bcadd(
            (string) $this->foreclosure_amount,
            (string) $this->net_foreclosure_charges,
            2
        );
    }
}
