<?php

namespace Modules\BLBankDetails\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;


class BLBankDetail extends Model
{
   use HasFactory, SoftDeletes;

    protected $table = 'b_l_bank_details';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'lead_id',
        'product_id',
        'applicant_id',
        'applicant_sno',
        'applicant_type',
        'created_by',
        'account_holder_name',
        'bank_name',
        'account_type',
        'account_number',
        'ifsc_code',
        'statement_file',
        'statement_password',

    ];

    protected $casts = [

        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
        'deleted_at'  => 'datetime',
    ];



    public function lead()
    {
        return $this->belongsTo(
            \Modules\BLLeadCreation\Models\BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function applicant()
    {
        return $this->belongsTo(
            \Modules\BLApplicant\Models\BLApplicant::class,
            'applicant_id',
            'id'
        );
    }

    public function creator()
    {
        return $this->belongsTo(
            \Modules\UserManagement\Models\User::class,
            'created_by'
        );
    }




    public function scopeForLead($query, string $leadUuid)
    {
        return $query->where('lead_uuid', $leadUuid);
    }


    public function scopeForApplicant($query, string $leadUuid, int $applicantId)
    {
        return $query->where('lead_uuid', $leadUuid)
                     ->where('applicant_id', $applicantId);
    }


    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }
}
