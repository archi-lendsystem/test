<?php

namespace Modules\BLCreditVisitReport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLLeadCreation\Models\BLLeadCreation; 


class BLCreditVisitReport extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'b_l_credit_visit_reports';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'pd_done_by',
        'pd_date',
        'meeting_place',
        'person_meet',
        'loan_purpose',
        'residence_details',
        'staying_together',
        'years_current_residence',
        'business_premises_ownership',
        'years_current_business_premises',
        'years_incurrent_business',
        'office_type',
        'name_board_seen',
        'monthly_emi_liabilities',
        'comfortable_emi',
        'business_registration',
        'industry_type',
        'sub_industry',
        'business_vintage',
        'inventory_in_business',
        'pd_status',
        'remarks',
        'property_value',
        'pd_property_owner_done',
        'stay_verification',
        'stay_since',
        'no_of_family_members',
        'stay_with',
        'house_ownership',
        'house_structure',
        'neighbour_reference',
        'address_as_per_kyc',
        'landlord_name',
        'relation_with_owner',
        'landlord_contact',
        'documents',
        'document_remark',
        'site_image',
    ];

    protected $casts = [
        'pd_date'                         => 'date',
        'stay_since'                      => 'date',
        'monthly_emi_liabilities'         => 'decimal:2',
        'comfortable_emi'                 => 'decimal:2',
        'property_value'                  => 'decimal:2',
        'years_current_residence'         => 'integer',
        'years_current_business_premises' => 'integer',
        'years_incurrent_business'        => 'integer',
        'no_of_family_members'            => 'integer',
        'created_at'                      => 'datetime',
        'updated_at'                      => 'datetime',
    ];

    public function leadCreation()
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }
}
