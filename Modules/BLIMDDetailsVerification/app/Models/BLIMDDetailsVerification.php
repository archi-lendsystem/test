<?php

namespace Modules\BLIMDDetailsVerification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\BLLeadCreation\Models\BLLeadCreation;


class BLIMDDetailsVerification extends Model
{
    use HasFactory;

    protected $table = 'b_l_i_m_d_details_verifications';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'lead_id',       
        'applicant_id',
        'status',
        'remarks',
        'consent_given',
        'consent_at',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'consent_given' => 'boolean',
        'consent_at'    => 'datetime',
        'verified_at'   => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

 
    public function leadCreation()
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }
}
