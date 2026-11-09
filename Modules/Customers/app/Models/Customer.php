<?php

namespace Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;

class Customer extends Model
{
    use HasFactory;

    protected $table = 'customers';

    protected $fillable = [
        'customer_application_id',
        'full_name',
        'pan_card_number',
        'aadhaar_card_number',
        'mobile_number',
        'email_address',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(BLLeadCreation::class, 'customer_id');
    }

    public static function findOrCreateForApplicant(
        BLApplicantDetails $applicant,
        BLLeadCreation $lead
    ): void {
        // Safety: only PRIMARY Applicant can link a customer to the lead.
        if (strtolower(trim((string) $applicant->applicant_type)) !== 'applicant') {
            return;
        }

        $pan = $applicant->pan_card_number ?: null;
        $aadhaar = $applicant->aadhaar_card_number ?: null;
        $mobile = $applicant->alternate_contact_number ?: $lead->mobile_number ?: null;

        // If already linked, keep the same customer and only fill missing master data.
        if ($lead->customer_id) {
            $customer = static::find($lead->customer_id);

            if ($customer) {
                $customer->fill(array_filter([
                    'full_name' => $customer->full_name ?: $applicant->full_name,
                    'pan_card_number' => $customer->pan_card_number ?: $pan,
                    'aadhaar_card_number' => $customer->aadhaar_card_number ?: $aadhaar,
                    'mobile_number' => $customer->mobile_number ?: $mobile,
                    'email_address' => $customer->email_address ?: $applicant->email_address,
                ], fn ($value) => $value !== null && $value !== ''))->save();

                $lead->forceFill([
                    'customer_match_status' => 'linked',
                ])->save();

                return;
            }
        }

        $panMatch = $pan
            ? static::where('pan_card_number', $pan)->first()
            : null;

        $aadhaarMatch = $aadhaar
            ? static::where('aadhaar_card_number', $aadhaar)->first()
            : null;

        $mobileMatch = $mobile
            ? static::where('mobile_number', $mobile)->first()
            : null;

        $matchedIds = collect([$panMatch, $aadhaarMatch, $mobileMatch])
            ->filter()
            ->pluck('id')
            ->unique();

        if ($matchedIds->count() > 1) {
            $lead->forceFill([
                'customer_id' => null,
                'customer_match_status' => 'conflict',
            ])->save();

            Log::channel('audit')->warning('CUSTOMER_MATCH_CONFLICT', [
                'lead_uuid' => $lead->uuid,
                'applicant_id' => $applicant->applicant_id,
                'pan_customer_id' => $panMatch?->id,
                'aadhaar_customer_id' => $aadhaarMatch?->id,
                'mobile_customer_id' => $mobileMatch?->id,
            ]);

            return;
        }

        $customer = $panMatch ?? $aadhaarMatch ?? $mobileMatch;

        if (! $customer) {
            $customer = static::create([
                'customer_application_id' => static::generateApplicationId(),
                'full_name' => $applicant->full_name,
                'pan_card_number' => $pan,
                'aadhaar_card_number' => $aadhaar,
                'mobile_number' => $mobile,
                'email_address' => $applicant->email_address,
            ]);
        } else {
            $customer->fill(array_filter([
                'full_name' => $customer->full_name ?: $applicant->full_name,
                'pan_card_number' => $customer->pan_card_number ?: $pan,
                'aadhaar_card_number' => $customer->aadhaar_card_number ?: $aadhaar,
                'mobile_number' => $customer->mobile_number ?: $mobile,
                'email_address' => $customer->email_address ?: $applicant->email_address,
            ], fn ($value) => $value !== null && $value !== ''))->save();
        }

        // Link only the current lead. Previous loans are never changed.
        $lead->forceFill([
            'customer_id' => $customer->id,
            'customer_match_status' => 'linked',
        ])->save();
    }

    private static function generateApplicationId(): string
    {
        do {
            $candidate = 'CUST-' . now()->format('Ymd') . '-' . random_int(1000, 9999);
        } while (static::where('customer_application_id', $candidate)->exists());

        return $candidate;
    }
}

// namespace Modules\Customers\Models;

// use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\Relations\HasMany;
// use Illuminate\Support\Facades\Log;
// use Modules\BLApplicantDetails\Models\BLApplicantDetails;
// use Modules\BLLeadCreation\Models\BLLeadCreation;

// class Customer extends Model
// {
//     use HasFactory;

//     protected $table = 'customers';

//     protected $fillable = [
//         'customer_application_id',
//         'full_name',
//         'pan_card_number',
//         'aadhaar_card_number',
//         'mobile_number',
//         'email_address',
//     ];

//     protected $casts = [
//         'created_at' => 'datetime',
//         'updated_at' => 'datetime',
//     ];

//     public function leads(): HasMany
//     {
//         return $this->hasMany(BLLeadCreation::class, 'customer_id');
//     }

  
//     public static function findOrCreateForApplicant(BLApplicantDetails $applicant, BLLeadCreation $lead): void
//     {
//         $pan      = $applicant->pan_card_number ?: null;
//         $aadhaar  = $applicant->aadhaar_card_number ?: null;
//         $mobile   = $lead->mobile_number ?: null;

//         $panMatch     = $pan ? static::where('pan_card_number', $pan)->first() : null;
//         $aadhaarMatch = $aadhaar ? static::where('aadhaar_card_number', $aadhaar)->first() : null;
//         $mobileMatch  = $mobile ? static::where('mobile_number', $mobile)->first() : null;

//         $matchedIds = collect([$panMatch, $aadhaarMatch, $mobileMatch])
//             ->filter()
//             ->pluck('id')
//             ->unique();

//         if ($matchedIds->count() > 1) {
//             $lead->forceFill(['customer_id' => null, 'customer_match_status' => 'conflict'])->save();

//             Log::channel('audit')->warning('CUSTOMER_MATCH_CONFLICT', [
//                 'lead_uuid'         => $lead->uuid,
//                 'applicant_id'      => $applicant->applicant_id,
//                 'pan_customer_id'   => $panMatch?->id,
//                 'aadhaar_customer_id' => $aadhaarMatch?->id,
//                 'mobile_customer_id' => $mobileMatch?->id,
//             ]);

//             return;
//         }

//         $customer = $panMatch ?? $aadhaarMatch ?? $mobileMatch;

//         if (! $customer) {
//             $customer = static::create([
//                 'customer_application_id' => static::generateApplicationId(),
//                 'full_name'               => $applicant->full_name,
//                 'pan_card_number'         => $pan,
//                 'aadhaar_card_number'     => $aadhaar,
//                 'mobile_number'           => $mobile,
//                 'email_address'           => $applicant->email_address,
//             ]);
//         } else {
//             $customer->fill(array_filter([
//                 'full_name'           => $customer->full_name ?: $applicant->full_name,
//                 'pan_card_number'     => $customer->pan_card_number ?: $pan,
//                 'aadhaar_card_number' => $customer->aadhaar_card_number ?: $aadhaar,
//                 'mobile_number'       => $customer->mobile_number ?: $mobile,
//                 'email_address'       => $customer->email_address ?: $applicant->email_address,
//             ]))->save();
//         }

//         $lead->forceFill([
//             'customer_id'           => $customer->id,
//             'customer_match_status' => 'linked',
//         ])->save();
//     }

//     private static function generateApplicationId(): string
//     {
//         do {
//             $candidate = 'CUST-' . now()->format('Ymd') . '-' . random_int(1000, 9999);
//         } while (static::where('customer_application_id', $candidate)->exists());

//         return $candidate;
//     }
// }
