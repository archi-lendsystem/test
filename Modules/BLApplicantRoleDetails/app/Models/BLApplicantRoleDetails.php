<?php

namespace Modules\BLApplicantRoleDetails\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLApplicantRoleDetails extends Model
{
   use HasFactory;

    protected $table = 'b_l_applicant_role_details';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'product_id',
        'created_by',
        'lead_id',
        'applicant_role_id',
        'applicant_id',
        'applicant_sno',
        'applicant_type',
        'type',
        'role',
        'sno',
        'full_name',
        'mother_name',
        'date_of_birth',
        'mobile_number',
        'gender',
        'pan_number',
        'residence_address',
        'pincode',
        'district',
        'city',
        'state',
        'ownership_status',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];



    
    public function role()
    {
        return $this->belongsTo(\Modules\BLApplicantRole\Models\BLApplicantRole::class, 'applicant_role_id', 'id');
    }

    
    public function blApplicantRole()
    {
        return $this->role();
    }
}
