<?php

namespace Modules\LUC\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Models\User;
// use Modules\LUC\Database\Factories\LucReportFactory;

class LucReport extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'luc_reports';

    protected $fillable = [
        'uuid',
        'lead_uuid',
        'disbursement_id',
        'borrower_name',
        'loan_number',
        'loan_amount',
        'disbursement_date',
        'luc_due_date',
        'utilization_status',
        'note_code',
        'note_details',
        'photo_path',
        'remarks',
        'workflow_status',
        'created_by',
        'verified_by',
        'verified_by_name',
        'verified_by_role',
        'date_of_luc',
        'verified_at',
    ];

    protected $casts = [
        'loan_amount' => 'decimal:2',
        'disbursement_date' => 'date:Y-m-d',
        'luc_due_date' => 'date:Y-m-d',
        'date_of_luc' => 'date:Y-m-d',
        'verified_at' => 'datetime',
    ];

    protected $appends = [
        'photo_url',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(BLDisbursment::class, 'disbursement_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        return route('api.luc.photo', ['lead_uuid' => $this->lead_uuid]);
    }
}
