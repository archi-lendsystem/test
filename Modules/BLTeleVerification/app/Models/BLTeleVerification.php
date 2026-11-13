<?php

namespace Modules\BLTeleVerification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLLeadCreation\Models\BLLeadCreation;


class BLTeleVerification extends Model
{
   use HasFactory, SoftDeletes;

    protected $table = 'b_l_tele_verifications';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
      
        'lead_uuid',
        'lead_id',

        
        'applicant_sno',
        'applicant_type',

    
        'tvr_flag',
        'tvr_status',
        'tvr_risk_assessment',
        'tvr_completed_at',

  
        'q1_name_correct',
        'q1_correct_name',
        'q2_mobile_verified',
        'q3_address_correct',
        'q3_updated_address',

       
        'q4_self_employed',
        'q4_business_type',
        'q5_business_vintage',
        'q5_vintage_years',
        'q6_fulltime_business',

     
        'q7_salaried',
        'q8_employer_name',
        'q9_employment_type',
        'q10_total_experience',
        'q11_current_tenure',
        'q12_salary_bank',

     
        'q13_income_threshold',
        'q13_monthly_income',
        'q14_income_banking',
        'q15_additional_income',
        'q15_income_source',
        'q15_income_amount',

   
        'q16_married',
        'q17_spouse_income',
        'q17_spouse_income_nature',
        'q18_dependents',
        'q18_dependent_count',
        'q19_expenses',
        'q19_expense_amount',

     
        'q20_existing_loans',
        'q20_loan_details',
        'q20_emi_amount',
        'q21_loan_defaults',

      
       

     
        'q27_emi_aware',
        'q28_other_applications',

      
        'q29_consent',
        'q30_information_true',

   
        'q31_tvr_status',
        'q32_risk_assessment',
        'q33_caller_remarks',
        'q34_recommended_action',

 
        'consent_given',
        'consent_at',
        'verified_by',
        'verified_at',
        'ip_address',
    ];

    protected $casts = [
        'applicant_sno'       => 'integer',
        'consent_given'       => 'boolean',
        'tvr_completed_at'    => 'datetime',
        'consent_at'          => 'datetime',
        'verified_at'         => 'datetime',
        'q5_vintage_years'    => 'float',
        'q13_monthly_income'  => 'float',
        'q15_income_amount'   => 'float',
        'q19_expense_amount'  => 'float',
        'q20_emi_amount'      => 'float',
        'q18_dependent_count' => 'integer',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
        'deleted_at'          => 'datetime',
    ];

  
    public function leadCreation()
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(\Modules\UserManagement\Models\User::class, 'verified_by');
    }
}
