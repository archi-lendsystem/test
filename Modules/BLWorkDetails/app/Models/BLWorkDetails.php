<?php

namespace Modules\BLWorkDetails\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLWorkDetails extends Model
{
   use HasFactory;

    protected $table = 'b_l_work_details';

    protected $primaryKey = 'id';

    protected $fillable = [
        'lead_uuid',
        'product_id',
        'created_by',
        'lead_id',
        'applicant_id',
        'applicant_sno',
        'applicant_type',

        'company_name',
        'designation',
        'salary_credit_method',
        'monthly_salary',
        'official_email',
        'total_work_company',
        'epf_deducted',
        'working_months',
    ];

    protected $casts = [
        'monthly_salary'     => 'decimal:2',
        'total_work_company' => 'integer',
        'working_months'     => 'integer',
        'epf_deducted'       => 'string',   
    ];

  
    public function lead()
    {
        return $this->belongsTo(\Modules\BLLeadCreation\Models\BLLeadCreation::class, 'lead_uuid', 'uuid');
    }

    public function applicant()
    {
        return $this->belongsTo(\Modules\BLApplicant\Models\BLApplicant::class, 'applicant_id','id');
    }

     public function creator()
    {
        return $this->belongsTo(\Modules\UserManagement\Models\User::class, 'created_by');
    }
    public function scopeForApplicant($query, string $leadUuid, int $applicantId)
    {
        return $query->where('lead_uuid', $leadUuid)
                     ->where('applicant_id', $applicantId);
    }
}
