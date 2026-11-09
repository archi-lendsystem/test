<?php

namespace Modules\BLBusinessDetails\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLBusinessDetails extends Model
{
    use HasFactory;

    protected $table = 'b_l_business_details';

    protected $primaryKey = 'id';

    protected $fillable = [
        'lead_uuid',
        'created_by',
        'lead_id',
        'applicant_id',
        'applicant_sno',
        'applicant_type',
        'business_name',
        'nature_of_business',
        'incorporation_date',
        'pan_number',
        'monthly_sales',
        'office_address',
        'pincode',
        'district',
        'city',
        'state',
        'business_premise_ownership',
    ];

    protected $casts = [
        'monthly_sales'      => 'decimal:2',
        'incorporation_date' => 'date',
    ];



    public function lead()
    {
        return $this->belongsTo(
        \Modules\BLLeadCreation\Models\BLLeadCreation::class,
        'lead_uuid',
        'uuid');
    }

     public function applicant()
    {
        return $this->belongsTo(
        \Modules\BLApplicant\Models\BLApplicant::class,
        'applicant_id',
        'id');
    }

   public function creator()
    {
        return $this->belongsTo(
        \Modules\UserManagement\Models\User::class,
        'created_by');
    }


    public function scopeForApplicant($query, string $leadUuid, int $applicantId)
    {
        return $query->where('lead_uuid', $leadUuid)
                     ->where('applicant_id', $applicantId);
    }
}
