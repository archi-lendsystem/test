<?php

namespace Modules\BLApplicantRole\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantRoleDetails\Models\BLApplicantRoleDetails;

class BLApplicantRole extends Model
{
    use HasFactory;

    protected $table = 'b_l_applicant_roles';

    protected $fillable = [
        'lead_uuid',
        'product_id',
        'created_by',
        'lead_id',
        'applicant_id',
        'sno',
        'applicant_sno',
        'applicant_type',
        'type',
        'role',
    ];

    public function leadCreation()
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }

    public function applicant()
    {
        return $this->belongsTo(BLApplicant::class, 'applicant_id', 'id');
    }
   
    public function detail()
    {
        return $this->hasOne(BLApplicantRoleDetails::class, 'applicant_role_id', 'id');
    }

   
    public function details()
    {
        return $this->hasOne(BLApplicantRoleDetails::class, 'applicant_role_id', 'id');
    }
}
