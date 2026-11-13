<?php

namespace Modules\FollowUpPTP\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FollowUpPTP\Enums\FollowUpOutcome;
use Modules\FollowUpPTP\Enums\FollowUpType;
use Modules\FollowUpPTP\Enums\PtpStatus;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\UserManagement\Models\User;

class FollowUp extends Model
{
    use HasUuids;

    protected $table = 'follow_ups';

    protected $fillable = [
        'repayment_account_id',

        'follow_up_type',
        'follow_up_date',
        'remarks',
        'outcome',
        'next_follow_up_date',

        'ptp_amount',
        'ptp_date',
        'ptp_status',
        'ptp_resolved_by',
        'ptp_resolved_at',
        'ptp_resolution_remarks',

        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',

            'follow_up_type' => FollowUpType::class,
            'follow_up_date' => 'datetime',
            'outcome' => FollowUpOutcome::class,
            'next_follow_up_date' => 'date',

            'ptp_amount' => 'decimal:2',
            'ptp_date' => 'date',
            'ptp_status' => PtpStatus::class,
            'ptp_resolved_by' => 'integer',
            'ptp_resolved_at' => 'datetime',

            'created_by' => 'integer',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ptpResolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ptp_resolved_by');
    }

    public function hasOpenPtp(): bool
    {
        return $this->outcome === FollowUpOutcome::PTP_TAKEN
            && $this->ptp_status === PtpStatus::PENDING;
    }
}
