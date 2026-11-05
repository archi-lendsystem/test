<?php

namespace Modules\Insurance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLCam\Models\BLCam;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLSaction\Models\BLSaction;

class Insurance extends Model
{
     use HasFactory, SoftDeletes;

    protected $table = 'insurances';

    protected $fillable = [
        'lead_uuid',
        'insurance_percentage',
        'insurance_amount',
    ];

    protected $casts = [
        'insurance_percentage' => 'decimal:2',
        'insurance_amount'     => 'decimal:2',
        'deleted_at'           => 'datetime',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    public function leadCreation()
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    /**
     * Latest CAM record for loan amount.
     */
    public function cam()
    {
        return $this->hasOne(
            BLCam::class,
            'lead_uuid',
            'lead_uuid'
        )->latestOfMany();
    }

    /**
     * All applicant details belonging to the lead.
     */
    public function applicantDetails()
    {
        return $this->hasMany(
            BLApplicantDetails::class,
            'lead_uuid',
            'lead_uuid'
        )->orderBy('applicant_sno');
    }

    /**
     * Latest sanction version belonging to the lead.
     */
public function latestSanction()
{
    return $this->hasOne(
        BLSaction::class,
        'lead_uuid',
        'lead_uuid'
    )->latestOfMany();
}
}
