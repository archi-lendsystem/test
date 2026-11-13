<?php

namespace Modules\BLApplicant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BLApplicant extends Model
{
    use HasFactory;

    protected $table = 'b_l_applicants';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'created_by',
        'lead_id',
        'applicant_sno',
        'applicant_types',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function leadCreation()
    {
        return $this->belongsTo(\Modules\BLLeadCreation\Models\BLLeadCreation::class, 'lead_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(\Modules\UserManagement\Models\User::class, 'created_by');
    }

    public function detail()
    {
        return $this->hasOne(\Modules\BLApplicantDetails\Models\BLApplicantDetails::class, 'applicant_id');
    }

    public function businessDetail()
    {
        return $this->hasOne(\Modules\BLBusinessDetails\Models\BLBusinessDetails::class, 'applicant_id', 'id');
    }
}
