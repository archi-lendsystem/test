<?php

namespace Modules\BLOfficeDetails\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLOfficeDetail extends Model
{
    use HasFactory;

    protected $table = 'b_l_office_details';
    protected $primaryKey = 'id';
    protected $fillable = [
        'lead_uuid',
        'created_by',
        'lead_id',
        'applicant_type',
        'applicant_sno',
        'applicant_id',

        'address',
        'landmark',
        'pincode',
        'district',
        'city',
        'state',

        'working_in_months',
    ];

    protected $casts = [
        'working_in_months' => 'integer',
    ];

    

    public function lead()
    {
        return $this->belongsTo(
            \Modules\BLLeadCreation\Models\BLLeadCreation::class,'lead_uuid','uuid');
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
