<?php

namespace Modules\BLLeadSubmit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;


class BLLeadSubmit extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'b_l_lead_submits';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'customer_consent', 
    ];

    protected $casts = [
        'customer_consent' => 'boolean', 
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];


    public function leadCreation()
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }


    public function applicantDetails()
    {
        return $this->hasMany(BLApplicantDetails::class, 'lead_uuid', 'lead_uuid');
    }
}
