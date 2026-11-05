<?php

namespace Modules\DemandNotice\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\DemandNotice\Enums\DemandNoticeStatus;
use Modules\DemandNotice\Enums\DemandNoticeType;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\UserManagement\Models\User;

class DemandNotice extends Model
{
    use HasUuids;

    protected $table = 'demand_notices';

    protected $fillable = [
        'repayment_account_id',
        'notice_number',
        'notice_type',
        'notice_date',
        'due_by_date',
        'overdue_since_date',
        'overdue_amount',
        'status',
        'sent_via',
        'sent_at',
        'pdf_path',
        'remarks',
        'generated_by',
    ];

    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',
            'notice_type' => DemandNoticeType::class,
            'notice_date' => 'date',
            'due_by_date' => 'date',
            'overdue_since_date' => 'date',
            'overdue_amount' => 'decimal:2',
            'status' => DemandNoticeStatus::class,
            'sent_at' => 'datetime',
            'generated_by' => 'integer',
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

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
