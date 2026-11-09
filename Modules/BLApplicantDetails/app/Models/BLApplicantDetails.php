<?php

namespace Modules\BLApplicantDetails\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Customers\Models\Customer;

class BLApplicantDetails extends Model
{
    use HasFactory;

    protected $table = 'b_l_applicant_details';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'created_by',
        'lead_id',
        'applicant_id',
        'applicant_sno',
        'applicant_type',
        'full_name',
        'aadhaar_card_number',
        'secondary_kyc_type',
        'secondary_kyc_number',
        'pan_card_number',
        'date_of_birth',
        'age',
        'nationality',
        'religion',
        'caste',
        'bureau_deviation',
        'is_income_considered',
        'applying_as',
        'employment_type',
        'entity_type',
        'proposed_property_owner',
        'alternate_contact_number',
        'qualification',
        'gender',
        'marital_status',
        'email_address',
        'father_full_name',
        'mother_full_name',
        'spouse_full_name',
        'living_standard_indicators',
        'premise_ownership',
        'residence_in_months',
        'numbers_of_family_members',
        'numbers_of_earning_members',
        'numbers_dependents',
        'property_owner',
        'relationship',
        'current_address',
        'current_landmark',
        'current_pincode',
        'current_district',
        'current_city',
        'current_state',
        'current_staying_in_years',
        'permanent_address',
        'permanent_landmark',
        'permanent_pincode',
        'permanent_district',
        'permanent_city',
        'permanent_state',
        'permanent_staying_in_years',
        'same_as_current_address',
        'is_co_applicant',
        'profile_photo',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'secondary_kyc_type' => 'string',
        'is_income_considered' => 'string',
        'entity_type' => 'string',
        'same_as_current_address' => 'boolean',
        'is_co_applicant' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['profile_photo_url'];

    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (
            empty($this->profile_photo)
            || ! Storage::disk('private')->exists($this->profile_photo)
        ) {
            return null;
        }

        try {
            return URL::temporarySignedRoute(
                'api.bl.applicant.photo.view',
                now()->addMinutes(30),
                [
                    'lead_uuid' => $this->lead_uuid,
                    'applicant_id' => $this->applicant_id,
                ],
                false
            );
        } catch (\Throwable $e) {
            Log::warning('[BLApplicantDetails] profile_photo_url generation failed', [
                'applicant_id' => $this->applicant_id,
                'lead_uuid' => $this->lead_uuid,
                'profile_photo' => $this->profile_photo,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function leadCreation()
    {
        return $this->belongsTo(
            \Modules\BLLeadCreation\Models\BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function applicant()
    {
        return $this->belongsTo(
            \Modules\BLApplicant\Models\BLApplicant::class,
            'applicant_id'
        );
    }

    protected static function booted(): void
    {
        static::saved(function (BLApplicantDetails $applicant): void {
            // Only the PRIMARY Applicant can own/link the lead customer.
            // Co-applicant and Guarantor must never overwrite lead.customer_id.
            $role = strtolower(trim((string) $applicant->applicant_type));

            if ($role !== 'applicant') {
                return;
            }

            $lead = $applicant->leadCreation()->first();

            if (! $lead) {
                Log::warning('CUSTOMER_CREATION_LEAD_NOT_FOUND', [
                    'applicant_detail_id' => $applicant->id,
                    'applicant_id' => $applicant->applicant_id,
                    'lead_uuid' => $applicant->lead_uuid,
                ]);

                return;
            }

            Customer::findOrCreateForApplicant($applicant, $lead);
        });
    }
}

// namespace Modules\BLApplicantDetails\Models;

// use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\Model;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Facades\URL;
// use Modules\Customers\Models\Customer;

// class BLApplicantDetails extends Model
// {
//     use HasFactory;

//     protected $table = 'b_l_applicant_details';
//     protected $primaryKey = 'id';
//     public $incrementing = true;
//     protected $keyType = 'int';

//     protected $fillable = [
//         'lead_uuid',
//         'created_by',
//         'lead_id',
//         'applicant_id',
//         'applicant_sno',
//         'applicant_type',
//         'full_name',
//         'aadhaar_card_number',
//         'secondary_kyc_type',
//         'secondary_kyc_number',
//         'pan_card_number',
//         'date_of_birth',
//         'age',
//         'nationality',
//         'religion',
//         'caste',
//         'bureau_deviation',
//         'is_income_considered',
//         'applying_as',
//         'employment_type',
//         'entity_type',
//         'alternate_contact_number',
//         'qualification',
//         'gender',
//         'marital_status',
//         'email_address',
//         'father_full_name',
//         'mother_full_name',
//         'spouse_full_name',
//         'living_standard_indicators',
//         'premise_ownership',
//         'residence_in_months',
//         'numbers_of_family_members',
//         'numbers_of_earning_members',
//         'numbers_dependents',
//         'relationship',
//         'current_address',
//         'current_landmark',
//         'current_pincode',
//         'current_district',
//         'current_city',
//         'current_state',
//         'current_staying_in_years',
//         'permanent_address',
//         'permanent_landmark',
//         'permanent_pincode',
//         'permanent_district',
//         'permanent_city',
//         'permanent_state',
//         'permanent_staying_in_years',
//         'same_as_current_address',
//         'is_co_applicant',
//         'profile_photo',
//     ];

//     protected $casts = [
//         'date_of_birth' => 'date',
//         'secondary_kyc_type' => 'string',
//         'is_income_considered' => 'string',
//         'entity_type' => 'string',
//         'same_as_current_address' => 'boolean',
//         'is_co_applicant' => 'boolean',
//         'created_at' => 'datetime',
//         'updated_at' => 'datetime',
//     ];

//     protected $appends = ['profile_photo_url'];

//     public function getProfilePhotoUrlAttribute(): ?string
//     {
//         if (
//             empty($this->profile_photo)
//             || ! Storage::disk('private')->exists($this->profile_photo)
//         ) {
//             return null;
//         }

//         try {
//             return URL::temporarySignedRoute(
//                 'api.bl.applicant.photo.view',
//                 now()->addMinutes(30),
//                 [
//                     'lead_uuid' => $this->lead_uuid,
//                     'applicant_id' => $this->applicant_id,
//                 ],
//                 false
//             );
//         } catch (\Throwable $e) {
//             Log::warning('[BLApplicantDetails] profile_photo_url generation failed', [
//                 'applicant_id' => $this->applicant_id,
//                 'lead_uuid' => $this->lead_uuid,
//                 'profile_photo' => $this->profile_photo,
//                 'error' => $e->getMessage(),
//             ]);

//             return null;
//         }
//     }

//     public function leadCreation()
//     {
//         return $this->belongsTo(
//             \Modules\BLLeadCreation\Models\BLLeadCreation::class,
//             'lead_uuid',
//             'uuid'
//         );
//     }

//     public function applicant()
//     {
//         return $this->belongsTo(
//             \Modules\BLApplicant\Models\BLApplicant::class,
//             'applicant_id'
//         );
//     }

//     protected static function booted(): void
//     {
//         static::saved(function (BLApplicantDetails $applicant): void {
//             if ((bool) $applicant->is_co_applicant) {
//                 return;
//             }

//             $lead = $applicant->leadCreation()->first();

//             if (! $lead) {
//                 Log::warning('CUSTOMER_CREATION_LEAD_NOT_FOUND', [
//                     'applicant_id' => $applicant->id,
//                     'lead_uuid' => $applicant->lead_uuid,
//                 ]);

//                 return;
//             }

//             Customer::findOrCreateForApplicant($applicant, $lead);
//         });
//     }
// }