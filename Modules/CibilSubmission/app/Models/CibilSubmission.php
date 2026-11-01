<?php

namespace Modules\CibilSubmission\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;


class CibilSubmission extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cibil_submissions';

  
    protected $fillable = [
        'uuid',

      

        'reporting_member_id',
        'member_short_name',
        'cycle_identification',
        'date_reported',
        'reporting_password',
        'authentication_method',

       

        'source_file_name',
        'future_use',
        'member_data',

      

        'source_row_number',

    

        'consumer_name',
        'date_of_birth',
        'gender',

    

        'income_tax_id_number',
        'passport_number',
        'passport_issue_date',
        'passport_expiry_date',
        'voter_id_number',
        'driving_license_number',
        'driving_license_issue_date',
        'driving_license_expiry_date',
        'ration_card_number',
        'universal_id_number',
        'additional_id_1',
        'additional_id_2',

  

        'telephone_mobile',
        'telephone_residence',
        'telephone_office',
        'extension_office',
        'telephone_other',
        'extension_other',

     

        'email_id_1',
        'email_id_2',

     

        'address_line_1',
        'state_code_1',
        'pin_code_1',
        'address_category_1',


        'residence_code_1',
        'address_line_2',
        'state_code_2',
        'pin_code_2',
        'address_category_2',
        'residence_code_2',

     

        'current_new_member_code',
        'current_new_member_short_name',
        'current_new_account_no',
        'account_type',
        'ownership_indicator',
        'date_opened_disbursed',
        'account_date_reported',
        'high_credit_sanctioned_amount',

     

        'date_of_last_payment',
        'date_closed',
        'current_balance',
        'amount_overdue',
        'days_past_due',

        'old_member_code',
        'old_member_short_name',
        'old_account_no',
        'old_account_type',
        'old_ownership_indicator',

        'suit_filed_wilful_default',
        'credit_facility_status',
        'asset_classification',

        'value_of_collateral',
        'type_of_collateral',
        'credit_limit',
        'cash_limit',

        'rate_of_interest',
        'repayment_tenure',
        'emi_amount',

        'written_off_amount_total',
        'written_off_principal_amount',
        'settlement_amount',

        'payment_frequency',
        'actual_payment_amount',

        'occupation_code',
        'income',

        'net_gross_income_indicator',
        'monthly_annual_income_indicator',

        'ckyc',
        'nrega_card_number',


        'is_valid',
        'validation_errors',
        'raw_payload',
        'status',
        'remarks',
        'extra_config',

   

        'created_by',
        'updated_by',
    ];


    protected $hidden = [
        'reporting_password',
    ];

  
    protected $casts = [
        'source_row_number' => 'integer',

        'high_credit_sanctioned_amount' => 'integer',
        'current_balance' => 'integer',
        'amount_overdue' => 'integer',
        'days_past_due' => 'integer',

        'value_of_collateral' => 'integer',
        'credit_limit' => 'integer',
        'cash_limit' => 'integer',

        'rate_of_interest' => 'decimal:2',
        'repayment_tenure' => 'integer',
        'emi_amount' => 'integer',

        'written_off_amount_total' => 'integer',
        'written_off_principal_amount' => 'integer',
        'settlement_amount' => 'integer',

        'actual_payment_amount' => 'integer',
        'income' => 'integer',

        'is_valid' => 'boolean',
        'validation_errors' => 'array',
        'raw_payload' => 'array',
        'extra_config' => 'array',
    ];

  
    protected static function booted(): void
    {
        static::creating(function (CibilSubmission $cibilSubmission) {
            if (empty($cibilSubmission->uuid)) {
                $cibilSubmission->uuid = (string) Str::uuid();
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

 
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
