<?php

namespace Modules\Settlement\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Settlement\Enums\SettlementStatus;
use Modules\UserManagement\Models\User;

class RepaymentSettlement extends Model
{
    use HasUuids;

    protected $table = 'repayment_settlements';

    protected $fillable = [
        'repayment_account_id',
        'total_outstanding_at_proposal',
        'settlement_amount',
        'waived_amount',
        'status',
        'reason',
        'proposed_by',
        'approved_by',
        'approved_at',
        'decision_remarks',
        'paid_at',
        'paid_reference',
    ];

    protected $attributes = [
        'status' => 'PROPOSED',
    ];

    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',
            'total_outstanding_at_proposal' => 'decimal:2',
            'settlement_amount' => 'decimal:2',
            'waived_amount' => 'decimal:2',
            'status' => SettlementStatus::class,
            'proposed_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
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

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
