<?php

namespace Modules\NOC\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\UserManagement\Models\User;

class NocCertificate extends Model
{
    use HasUuids;

    protected $table = 'noc_certificates';

    protected $fillable = [
        'repayment_account_id',
        'noc_number',
        'issue_date',
        'closure_type',
        'remarks',
        'pdf_path',
        'generated_by',
    ];

    protected function casts(): array
    {
        return [
            'repayment_account_id' => 'integer',
            'issue_date' => 'date',
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
