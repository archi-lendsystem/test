<?php

namespace Modules\CrifSubmission\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;


class CrifSubmission extends Model
{
    use HasFactory;

   
    protected $table = 'crif_submissions';

  
    protected $fillable = [
        'uuid',
        'reporting_member_id',
        'short_name',
        'cycle_identification',
        'header_date_reported',
        'reporting_password',
        'authentication_method',
        'future_use',
        'member_data',

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

        'address_1',
        'state_code_1',
        'pin_code_1',
        'address_category_1',
        'residence_code_1',
        'address_2',
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
        'date_of_last_payment',
        'date_closed',
        'date_reported',
        'high_credit_sanctioned_amt',
        'current_balance',
        'amt_overdue',
        'no_of_days_past_due',

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
        'settlement_amt',
        'payment_frequency',
        'actual_payment_amt',

        'created_by',
        'updated_by',

        'occupation_code',
        'income',
        'net_gross_income_indicator',
        'monthly_annual_income_indicator',
    ];

  
    protected $casts = [
        'high_credit_sanctioned_amt' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'amt_overdue' => 'decimal:2',

        'value_of_collateral' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'cash_limit' => 'decimal:2',

        'rate_of_interest' => 'decimal:2',
        'repayment_tenure' => 'integer',
        'emi_amount' => 'decimal:2',

        'written_off_amount_total' => 'decimal:2',
        'written_off_principal_amount' => 'decimal:2',
        'settlement_amt' => 'decimal:2',
        'actual_payment_amt' => 'decimal:2',

        'income' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (CrifSubmission $crifSubmission) {
            if (empty($crifSubmission->uuid)) {
                $crifSubmission->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
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

