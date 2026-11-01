<?php

namespace Modules\EmiStatement\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\UserManagement\Models\User;

class EmiStatement extends Model
{
    use HasUuids;

    protected $table = 'emi_statements';

    protected $fillable = [
        'repayment_account_id',
        'statement_number',
        'generated_on',
        'total_installments',
        'paid_installments',
        'pending_installments',
        'total_received_amount',
        'total_pending_amount',
        'pdf_path',
        'generated_by',
    ];

    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',
            'generated_on' => 'date',
            'total_installments' => 'integer',
            'paid_installments' => 'integer',
            'pending_installments' => 'integer',
            'total_received_amount' => 'decimal:2',
            'total_pending_amount' => 'decimal:2',
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
