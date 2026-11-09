<?php

namespace Modules\BLApplicantDetails\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Transformers\BLApplicantDetailsResource;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\BLJourney\Models\BLJourney;
use Throwable;

class BLApplicantDetailsController extends Controller
{
    use ApiResponse;

    private const PHOTO_DISK       = 'private';
    private const PHOTO_TTL_MINS   = 30;



    public function show($lead_uuid, $applicant_id): JsonResponse
    {
        $blApplicantDetail = BLApplicantDetails::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->with(['leadCreation', 'applicant'])
            ->first();

        if (!$blApplicantDetail) {
            return response()->json([
                'success'    => false,
                'status_code' => 404,
                'request_id' => app('request_id') ?? null,
                'timestamp'  => now()->toDateTimeString(),
                'message'    => 'Applicant detail not found',
                'data'       => null,
            ], 404);
        }

        $resource = (new BLApplicantDetailsResource($blApplicantDetail))->toArray(request());

        $resource['profile_photo_url'] = $this->generateSignedPhotoUrl($lead_uuid, $applicant_id, $blApplicantDetail);

        return response()->json([
            'success'    => true,
            'status_code' => 200,
            'request_id' => app('request_id') ?? null,
            'timestamp'  => now()->toDateTimeString(),
            'message'    => 'BL Applicant Detail retrieved successfully',
            'data'       => $resource,
        ], 200);
    }





    public function update(
        Request $request,
        $lead_uuid,
        $applicant_id
    ): JsonResponse {
        $blApplicantDetail = BLApplicantDetails::where(
            'lead_uuid',
            $lead_uuid
        )
            ->where('applicant_id', $applicant_id)
            ->first();

        if (! $blApplicantDetail) {
            return response()->json([
                'success'     => false,
                'status_code' => Response::HTTP_NOT_FOUND,
                'request_id'  => app('request_id'),
                'timestamp'   => now()->format('Y-m-d H:i:s'),
                'message'     => 'Applicant detail not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();

        if (! $lead) {
            return response()->json([
                'success'     => false,
                'status_code' => Response::HTTP_NOT_FOUND,
                'request_id'  => app('request_id'),
                'timestamp'   => now()->format('Y-m-d H:i:s'),
                'message'     => 'Lead not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $isExistingCustomer =
    strtolower(trim((string) $lead->customer_type)) === 'existing';

        $secondaryKycType = trim(
            (string) $request->input('secondary_kyc_type', '')
        );

        $secondaryKycNumber = strtoupper(
            preg_replace(
                '/\s+/',
                '',
                trim((string) $request->input('secondary_kyc_number', ''))
            )
        );

        $aadhaarRules = [
    'nullable',
    'string',
    'regex:/^[0-9]{12}$/',
];

if ($isExistingCustomer) {
    // Re-loan: same Aadhaar previous loans me allowed,
    // but same current lead ke kisi other applicant me duplicate nahi.
    $aadhaarRules[] = Rule::unique(
        'b_l_applicant_details',
        'aadhaar_card_number'
    )
        ->where(function ($query) use ($lead_uuid) {
            return $query->where('lead_uuid', $lead_uuid);
        })
        ->ignore($blApplicantDetail->id, 'id');
} else {
    // New customer: Aadhaar poori applicant table me unique rehna chahiye.
    $aadhaarRules[] = Rule::unique(
        'b_l_applicant_details',
        'aadhaar_card_number'
    )->ignore($blApplicantDetail->id, 'id');
}

$mobileRules = [
    'sometimes',
    'nullable',
    'regex:/^[0-9]{10}$/',
];

if ($isExistingCustomer) {
    // Re-loan: same mobile previous loans me allowed,
    // but same current lead me duplicate nahi.
    $mobileRules[] = Rule::unique(
        'b_l_applicant_details',
        'alternate_contact_number'
    )
        ->where(function ($query) use ($lead_uuid) {
            return $query->where('lead_uuid', $lead_uuid);
        })
        ->ignore($blApplicantDetail->id, 'id');
} else {
    // New customer: mobile globally unique.
    $mobileRules[] = Rule::unique(
        'b_l_applicant_details',
        'alternate_contact_number'
    )->ignore($blApplicantDetail->id, 'id');
}

        $request->merge([
            'aadhaar_card_number' => filled($request->input('aadhaar_card_number'))
                ? preg_replace('/\D/', '', (string) $request->input('aadhaar_card_number'))
                : null,


            'alternate_contact_number' => filled($request->input('alternate_contact_number'))
                ? preg_replace('/\D/', '', (string) $request->input('alternate_contact_number'))
                : null,

            'secondary_kyc_type' => $secondaryKycType !== ''
                ? $secondaryKycType
                : null,

            'secondary_kyc_number' => $secondaryKycNumber !== ''
                ? $secondaryKycNumber
                : null,
        ]);

        $secondaryKycNumberRules = [
            'nullable',
            'required_with:secondary_kyc_type',
            'string',
            'max:50',
        ];

        // if ($secondaryKycType === 'PAN Card') {
        //     $secondaryKycNumberRules[] = Rule::unique(
        //         'b_l_applicant_details',
        //         'pan_card_number'
        //     )->ignore($blApplicantDetail->id, 'id');
        // }

        if ($secondaryKycType === 'PAN Card') {
    $panUniqueRule = Rule::unique(
        'b_l_applicant_details',
        'pan_card_number'
    )->ignore($blApplicantDetail->id, 'id');

    if ($isExistingCustomer) {
        $panUniqueRule->where(function ($query) use ($lead_uuid) {
            return $query->where('lead_uuid', $lead_uuid);
        });
    }

    $secondaryKycNumberRules[] = $panUniqueRule;
}

        $validated = $request->validate([
            'full_name' => [
                'required',
                'string',
                'max:255',
            ],

            'gender' => [
                'required',
                Rule::in(['Male', 'Female', 'Other']),
            ],

            'date_of_birth' => [
                'required',
                'date',
                'before_or_equal:today',
            ],

            'age' => [
                'sometimes',
                'nullable',
                'string',
                'min:18',
                'max:100',
            ],

            'nationality' => [
                'required',
                'string',
                'max:100',
            ],

            'religion' => [
                'required',
                'string',
                'max:100',
            ],

            'caste' => [
                'required',
                'string',
                'max:100',
            ],

            'marital_status' => [
                'required',
                Rule::in([
                    'Single',
                    'Married',
                    'Divorced',
                    'Widowed',
                    'Separated',
                ]),
            ],

            'spouse_full_name' => [
                'nullable',
                'required_if:marital_status,Married',
                'string',
                'max:255',
            ],

            'qualification' => [
                'required',
                'string',
                'max:255',
            ],



            // 'aadhaar_card_number' => [
            //     'nullable',
            //     'string',
            //     'regex:/^[0-9]{12}$/',

            //     Rule::unique(
            //         'b_l_applicant_details',
            //         'aadhaar_card_number'
            //     )->ignore($blApplicantDetail->id, 'id'),
            // ],

            'aadhaar_card_number' => $aadhaarRules,



            'secondary_kyc_type' => [
                'nullable',
                'required_with:secondary_kyc_number',
                Rule::in([
                    'PAN Card',
                    'Voter ID Card',
                    'Driving Licence',
                    'Passport',
                    'Job Card',
                    'Digital Ration Card',
                ]),
            ],

            // 'secondary_kyc_number' => [
            //     'nullable',
            //      'required_with:secondary_kyc_type',
            //     'string',
            //     'max:50',
            // ],

            'secondary_kyc_number' => $secondaryKycNumberRules,

            'email_address' => [
                'required',
                'email',
            ],

            // 'alternate_contact_number' => [
            //     'sometimes',
            //     'nullable',
            //     'regex:/^[0-9]{10}$/',

            //     Rule::unique(
            //         'b_l_applicant_details',
            //         'alternate_contact_number'
            //     )->ignore($blApplicantDetail->id, 'id'),
            // ],

            'alternate_contact_number' => $mobileRules,

            'father_full_name' => [
                'required',
                'string',
                'max:255',
            ],

            'mother_full_name' => [
                'required',
                'string',
                'max:255',
            ],

            'numbers_of_family_members' => [
                'required',
                'integer',
                'min:0',
            ],

            'numbers_of_earning_members' => [
                'required',
                'integer',
                'min:0',
            ],

            'numbers_dependents' => [
                'required',
                'integer',
                'min:0',
            ],

            'is_income_considered' => [
                'required',
                Rule::in(['Yes', 'No']),
            ],

            'bureau_deviation' => [
                'required',
                'string',
                'max:255',
            ],

            'applying_as' => [
                'required',
                Rule::in(['Individual', 'Business']),
            ],

            'employment_type' => [
                'required_if:applying_as,Individual',
                'nullable',
                'string',
                'max:255',
            ],

            'entity_type' => [
                'required_if:applying_as,Business',
                'nullable',
                'string',
                'max:255',
            ],

            'relationship' => [
                'required',
                'string',
                'max:255',
            ],

            'premise_ownership' => [
                'required',
                'string',
                'max:255',
            ],

            'living_standard_indicators' => [
                'required',
                'string',
                'max:255',
            ],

            'residence_in_months' => [
                'required',
                'integer',
                'min:0',
                'max:1200',
            ],

            'current_address' => [
                'required',
                'string',
            ],

            'current_landmark' => [
                'nullable',
                'string',
                'max:255',
            ],

            'current_pincode' => [
                'required',
                'string',
                'max:10',
            ],

            'current_district' => [
                'required',
                'string',
                'max:255',
            ],

            'current_city' => [
                'required',
                'string',
                'max:255',
            ],

            'current_state' => [
                'required',
                'string',
                'max:255',
            ],

            'current_staying_in_years' => [
                'required',
                'integer',
                'min:0',
            ],

            'permanent_address' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'permanent_landmark' => [
                'nullable',
                'string',
                'max:255',
            ],

            'permanent_pincode' => [
                'sometimes',
                'nullable',
                'string',
                'max:10',
            ],

            'permanent_district' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'permanent_city' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'permanent_state' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'permanent_staying_in_years' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            'same_as_current_address' => [
                'sometimes',
                'boolean',
            ],

            'is_co_applicant' => [
                'sometimes',
                'boolean',
            ],
        ], [
            'aadhaar_card_number.required' =>
            'Aadhaar card number is required.',

            'aadhaar_card_number.regex' =>
            'Aadhaar card number must contain exactly 12 digits.',

            'aadhaar_card_number.unique' =>
            'This Aadhaar card number is already registered with another applicant.',

            'alternate_contact_number.regex' =>
            'Mobile number must contain exactly 10 digits.',

            'alternate_contact_number.unique' =>
            'This mobile number is already registered with another applicant.',

            'secondary_kyc_number.unique' =>
            'This PAN number is already registered with another applicant.',

            'secondary_kyc_type.required' =>
            'Please select a Secondary KYC document.',

            'secondary_kyc_type.in' =>
            'The selected Secondary KYC document is invalid.',

            'secondary_kyc_number.required' =>
            'Secondary KYC document number is required.',
        ]);



        $secondaryKycRules = [
            'PAN Card' => [
                'pattern' => '/^[A-Z]{5}[0-9]{4}[A-Z]$/',
                'message' => 'PAN number must be valid format.',
            ],

            'Voter ID Card' => [
                'pattern' => '/^[A-Z]{3}[0-9]{7}$/',
                'message' =>
                'Voter ID number must be valid format.',
            ],

            'Driving Licence' => [
                'pattern' => '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,13}$/',
                'message' =>
                'Please enter a valid Driving Licence number.',
            ],

            'Passport' => [
                'pattern' => '/^[A-Z][0-9]{7}$/',
                'message' =>
                'Passport number must be valid format.',
            ],

            'Job Card' => [
                'pattern' => '/^[A-Z0-9\/\-]{5,50}$/',
                'message' =>
                'Please enter a valid Job Card number.',
            ],

            'Digital Ration Card' => [
                'pattern' => '/^[A-Z0-9\/\-]{5,50}$/',
                'message' =>
                'Please enter a valid Digital Ration Card number.',
            ],
        ];

        $selectedKycType =
            $validated['secondary_kyc_type'] ?? null;

        $secondaryKycNumber =
            $validated['secondary_kyc_number'] ?? null;

        if ($selectedKycType !== null && $secondaryKycNumber !== null) {
            $selectedKycRule = $secondaryKycRules[$selectedKycType];

            if (! preg_match(
                $selectedKycRule['pattern'],
                $secondaryKycNumber
            )) {
                return response()->json([
                    'success'     => false,
                    'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'request_id'  => app('request_id') ?? null,
                    'timestamp'   => now()->format('Y-m-d H:i:s'),
                    'message'     => $selectedKycRule['message'],
                    'errors'      => [
                        'secondary_kyc_number' => [
                            $selectedKycRule['message'],
                        ],
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $validated['pan_card_number'] =
            $selectedKycType === 'PAN Card' && $secondaryKycNumber !== null
            ? $secondaryKycNumber
            : null;

        // $selectedKycType = $validated['secondary_kyc_type'];
        // $secondaryKycNumber = $validated['secondary_kyc_number'];
        // $selectedKycRule = $secondaryKycRules[$selectedKycType];

        // if (
        //     ! preg_match(
        //         $selectedKycRule['pattern'],
        //         $secondaryKycNumber
        //     )
        // ) {
        //     return response()->json([
        //         'success'     => false,
        //         'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
        //         'request_id'  => app('request_id') ?? null,
        //         'timestamp'   => now()->format('Y-m-d H:i:s'),
        //         'message'     => $selectedKycRule['message'],
        //         'errors'      => [
        //             'secondary_kyc_number' => [
        //                 $selectedKycRule['message'],
        //             ],
        //         ],
        //     ], Response::HTTP_UNPROCESSABLE_ENTITY);
        // }



        // $validated['pan_card_number'] =
        //     $selectedKycType === 'PAN Card'
        //     ? $secondaryKycNumber
        //     : null;



        if (
            isset(
                $validated['gender'],
                $validated['employment_type']
            ) &&
            $validated['gender'] === 'Female' &&
            strtolower($validated['employment_type']) === 'housewife'
        ) {
            return response()->json([
                'success'     => false,
                'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message'     =>
                'Female co-applicant cannot be housewife.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (
            isset(
                $validated['is_income_considered'],
                $validated['employment_type']
            ) &&
            strtolower($validated['is_income_considered']) === 'yes' &&
            strtolower($validated['employment_type']) === 'housewife'
        ) {
            return response()->json([
                'success'     => false,
                'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message'     =>
                'Employment type cannot be Housewife when income is considered.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }



        $blApplicantDetail->update($validated);
        $blApplicantDetail->refresh();

        $resource = (
            new BLApplicantDetailsResource($blApplicantDetail)
        )->toArray($request);

        $resource['profile_photo_url'] =
            $this->generateSignedPhotoUrl(
                $lead_uuid,
                $applicant_id,
                $blApplicantDetail
            );

        return response()->json([
            'success'     => true,
            'status_code' => Response::HTTP_OK,
            'request_id'  => app('request_id') ?? null,
            'timestamp'   => now()->toDateTimeString(),
            'message'     =>
            'BL Applicant Detail updated successfully',
            'data'        => $resource,
        ], Response::HTTP_OK);
    }

    public function uploadPhoto(Request $request, string $lead_uuid, int $applicant_id): JsonResponse
    {
        $blApplicantDetail = BLApplicantDetails::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->first();

        if (!$blApplicantDetail) {
            return $this->apiError('Applicant detail not found.', null, 404, 'NOT_FOUND');
        }

        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        try {
            if (
                !empty($blApplicantDetail->profile_photo) &&
                Storage::disk(self::PHOTO_DISK)->exists($blApplicantDetail->profile_photo)
            ) {
                Storage::disk(self::PHOTO_DISK)->delete($blApplicantDetail->profile_photo);
            }

            $lead      = BLLeadCreation::where('uuid', $lead_uuid)->firstOrFail();
            $file      = $request->file('photo');
            $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
            $folder    = "applicants/{$lead->lead_id}/{$applicant_id}/photos";
            $filename  = 'live_photo.' . $extension;

            $path = $file->storeAs($folder, $filename, self::PHOTO_DISK);


            $blApplicantDetail->profile_photo = $path;
            $blApplicantDetail->save();
            $blApplicantDetail->refresh();
            return $this->apiSuccess(
                [
                    'applicant_id'      => $applicant_id,
                    'profile_photo_url' => $this->generateSignedPhotoUrl($lead_uuid, $applicant_id, $blApplicantDetail),
                ],
                'Live photo uploaded successfully.',
                200,
                'PHOTO_UPLOADED'
            );
        } catch (Throwable $e) {
            return $this->apiError(
                'Something went wrong while uploading photo.',
                app()->environment('local') ? $e->getMessage() : null,
                500,
                'SERVER_ERROR'
            );
        }
    }



    // public function viewPhoto(string $lead_uuid, int $applicant_id)
    // {
    //     $blApplicantDetail = BLApplicantDetails::where('lead_uuid', $lead_uuid)
    //         ->where('applicant_id', $applicant_id)
    //         ->first();

    //     if (!$blApplicantDetail || empty($blApplicantDetail->profile_photo)) {
    //         abort(404, 'Photo not found.');
    //     }

    //     if (!Storage::disk(self::PHOTO_DISK)->exists($blApplicantDetail->profile_photo)) {
    //         abort(404, 'Photo file not found on storage.');
    //     }

    //     $fileContent = Storage::disk(self::PHOTO_DISK)->get($blApplicantDetail->profile_photo);
    //     $extension   = strtolower(pathinfo($blApplicantDetail->profile_photo, PATHINFO_EXTENSION));

    //     $mimeMap = [
    //         'jpg'  => 'image/jpeg',
    //         'jpeg' => 'image/jpeg',
    //         'png'  => 'image/png',
    //         'webp' => 'image/webp',
    //     ];
    //     $mimeType = $mimeMap[$extension] ?? 'image/jpeg';

    //     return response($fileContent, 200)
    //         ->header('Content-Type', $mimeType)
    //         ->header('Content-Disposition', 'inline; filename="live_photo.' . $extension . '"')
    //         ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
    //         ->header('Pragma', 'no-cache');
    // }

    public function viewPhoto(string $lead_uuid, int $applicant_id)
    {
        $detail = BLApplicantDetails::query()
            ->where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->first();

        if (! $detail) {
            abort(404, 'Applicant not found.');
        }

        if (empty($detail->profile_photo)) {
            abort(404, 'Applicant photo path is empty.');
        }

        $disk = Storage::disk(self::PHOTO_DISK);

        if (! $disk->exists($detail->profile_photo)) {
            Log::warning('APPLICANT_PHOTO_FILE_NOT_FOUND', [
                'lead_uuid'    => $lead_uuid,
                'applicant_id' => $applicant_id,
                'photo_path'   => $detail->profile_photo,
            ]);

            abort(404, 'Applicant photo file not found.');
        }

        $absolutePath = $disk->path($detail->profile_photo);

        $extension = strtolower(
            pathinfo($detail->profile_photo, PATHINFO_EXTENSION)
        );

        $mimeTypes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
        ];

        $mimeType = $mimeTypes[$extension] ?? 'image/jpeg';

        return response()->file($absolutePath, [
            'Content-Type'              => $mimeType,
            'Content-Disposition'       =>
            'inline; filename="' . basename($detail->profile_photo) . '"',
            'Cache-Control'             => 'private, no-store, no-cache, max-age=0',
            'Pragma'                    => 'no-cache',
            'X-Content-Type-Options'    => 'nosniff',
        ]);
    }

    public function finalValidation(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'lead_uuid' => 'required|uuid|exists:b_l_lead_creations,uuid'
            ]);

            $leadUuid = $request->lead_uuid;
            $lead     = BLLeadCreation::where('uuid', $leadUuid)->first();

            if (!$lead) {
                return $this->apiError('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
            }

            $applicants = BLApplicantDetails::where('lead_uuid', $leadUuid)->get();

            if ($applicants->isEmpty()) {
                return $this->apiError(
                    'No applicants found.',
                    ['lead_uuid' => $leadUuid],
                    422,
                    'NO_APPLICANTS_FOUND'
                );
            }

            $errors = [];


            $mainApplicant = $applicants->firstWhere('applicant_type', 'applicant');
            if (
                $mainApplicant &&
                in_array(strtolower((string) $mainApplicant->employment_type), ['housewife', 'house_wife'], true)
            ) {
                $errors[] = 'Applicant cannot be a Housewife.';
            }

            foreach ($applicants as $applicant) {
                $isIncomeYes = (int) ($applicant->is_income_considered ?? 0) === 1
                    || strtolower((string) ($applicant->is_income_considered ?? '')) === 'yes';

                $isHousewife = in_array(
                    strtolower((string) ($applicant->employment_type ?? '')),
                    ['housewife', 'house_wife'],
                    true
                );

                if ($isIncomeYes && $isHousewife) {
                    $applicantName = $applicant->full_name ?: 'Applicant';
                    $errors[]      = "{$applicantName} ({$applicant->applicant_type}): income considered Yes but employment type is Housewife.";
                }
            }

            if (!empty($errors)) {
                return $this->apiError(
                    'Applicant final validation failed.',
                    ['lead_uuid' => $leadUuid, 'errors' => $errors],
                    422,
                    'APPLICANT_VALIDATION_FAILED'
                );
            }

            $journey = $this->createApplicantDetailsJourneyStep($lead, $request);

            return $this->apiSuccess(
                [
                    'lead_uuid'        => $leadUuid,
                    'lead_id'          => $lead->lead_id,
                    'journey'          => $journey,
                    'total_applicants' => $applicants->count(),
                ],
                'Applicant details validated successfully. Moving to next step.',
                200,
                'APPLICANT_DETAILS_COMPLETED'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->apiError('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (Throwable $e) {
            return $this->apiError(
                'Something went wrong while validating applicant details.',
                app()->environment('local') ? $e->getMessage() : null,
                500,
                'SERVER_ERROR'
            );
        }
    }


    public function checkInternalDuplicates($lead_uuid): JsonResponse
    {
        try {
            $records = BLApplicantDetails::where('lead_uuid', $lead_uuid)->get();

            if ($records->count() <= 1) {
                return $this->apiSuccess(
                    ['lead_uuid' => $lead_uuid, 'duplicates' => []],
                    'Not enough records to compare.',
                    200,
                    'NOT_ENOUGH_RECORDS'
                );
            }

            $duplicates = [];

            for ($i = 0; $i < count($records); $i++) {
                for ($j = $i + 1; $j < count($records); $j++) {
                    $first  = $records[$i];
                    $second = $records[$j];

                    $checks = [
                        'alternate_contact_number' => fn($a, $b) => $a === $b,
                        'email_address'            => fn($a, $b) => strtolower($a) === strtolower($b),
                        'aadhaar_card_number'      => fn($a, $b) => $a === $b,
                        'pan_card_number'          => fn($a, $b) => strtoupper($a) === strtoupper($b),
                    ];

                    foreach ($checks as $field => $comparator) {
                        $val1 = (string) ($first->{$field}  ?? '');
                        $val2 = (string) ($second->{$field} ?? '');

                        if (!empty($val1) && $comparator($val1, $val2)) {
                            $duplicates[] = [
                                'field'           => $field,
                                'value'           => $first->{$field},
                                'matched_between' => [
                                    ['name' => $first->full_name,  'applicant_type' => $first->applicant_type,  'applicant_sno' => $first->applicant_sno],
                                    ['name' => $second->full_name, 'applicant_type' => $second->applicant_type, 'applicant_sno' => $second->applicant_sno],
                                ],
                            ];
                        }
                    }
                }
            }

            if (!empty($duplicates)) {
                return $this->apiError(
                    'Internal duplicates found among applicants.',
                    ['lead_uuid' => $lead_uuid, 'duplicates' => $duplicates],
                    409,
                    'INTERNAL_DUPLICATES_FOUND'
                );
            }

            return $this->apiSuccess(
                ['lead_uuid' => $lead_uuid, 'duplicates' => []],
                'No duplicates found among applicants.',
                200,
                'NO_DUPLICATES_FOUND'
            );
        } catch (Throwable $e) {
            return $this->apiError(
                'Something went wrong while checking internal duplicates.',
                app()->environment('local') ? $e->getMessage() : null,
                500,
                'SERVER_ERROR'
            );
        }
    }

    private function generateSignedPhotoUrl(
        string $lead_uuid,
        int $applicant_id,
        BLApplicantDetails $detail
    ): ?string {
        if (
            empty($detail->profile_photo) ||
            ! Storage::disk(self::PHOTO_DISK)->exists($detail->profile_photo)
        ) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.bl.applicant.photo.view',
            now()->addMinutes(self::PHOTO_TTL_MINS),
            [
                'lead_uuid'    => $lead_uuid,
                'applicant_id' => $applicant_id,
            ],
            false
        );
    }


    // private function generateSignedPhotoUrl(string $lead_uuid, int $applicant_id, BLApplicantDetails $detail): ?string
    // {

    //     if (empty($detail->profile_photo)) {
    //         return null;
    //     }


    //     try {
    //         return URL::temporarySignedRoute(
    //             'api.bl.applicant.photo.view',
    //             now()->addMinutes(self::PHOTO_TTL_MINS),
    //             [
    //                 'lead_uuid'    => $lead_uuid,
    //                 'applicant_id' => $applicant_id,
    //             ]
    //         );
    //     } catch (Throwable $e) {

    //         Log::error('[BLApplicantDetails] generateSignedPhotoUrl failed', [
    //             'lead_uuid'    => $lead_uuid,
    //             'applicant_id' => $applicant_id,
    //             'profile_photo' => $detail->profile_photo,
    //             'error'        => $e->getMessage(),
    //         ]);

    //         return null;
    //     }
    // }

    private function createApplicantDetailsJourneyStep(BLLeadCreation $lead, Request $request): BLJourney
    {
        BLJourney::where('lead_uuid', $lead->uuid)
            ->where('is_latest', true)
            ->update(['is_latest' => false]);

        $workflowStage  = null;
        $workflowStatus = null;

        if (Schema::hasTable('b_l_workflow_stages')) {
            $workflowStage = DB::table('b_l_workflow_stages')
                ->where('stage_code', 'APPLICANT_DETAILS')
                ->first();
        }

        if ($workflowStage && Schema::hasTable('b_l_workflow_statuses')) {
            $workflowStatus = DB::table('b_l_workflow_statuses')
                ->where('stage_id', $workflowStage->id)
                ->whereIn('status_code', ['COMPLETED', 'SUBMITTED'])
                ->orderByRaw("FIELD(status_code, 'COMPLETED', 'SUBMITTED')")
                ->first();
        }

        $authUser = $request->user();

        $payload = [
            'lead_uuid'          => $lead->uuid,
            'lead_id'            => $lead->lead_id,
            'workflow_stage_id'  => $workflowStage->id ?? null,
            'workflow_status_id' => $workflowStatus->id ?? null,
            'previous_stage_id'  => null,
            'action_performed'   => 'APPLICANT_DETAILS_COMPLETED',
            'performed_by_role'  => $authUser->user_role ?? $authUser->user_designation ?? $authUser->user_type ?? null,
            'transition_type'    => 'FORWARD',
            'stage'              => $workflowStage->stage_name ?? 'Applicant Details',
            'status'             => $workflowStatus->status_name ?? 'Completed',
            'team'               => $workflowStage->team_name ?? 'Sales',
            'action_type'        => 'APPLICANT_DETAILS_COMPLETED',
            'remarks'            => 'Applicant details completed and validated successfully.',
            'user_id'            => $authUser->id ?? null,
            'user_name'          => $authUser->user_name ?? $authUser->name ?? null,
            'is_latest'          => true,
            'ip_address'         => $request->ip(),
            'device_info'        => $request->header('User-Agent'),
            'created_by'         => $authUser->id ?? null,
            'is_completed'       => true,
        ];

        return BLJourney::create($this->onlyExistingColumns('b_l_journeys', $payload));
    }

    private function onlyExistingColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn($value, $key) => Schema::hasColumn($table, $key))
            ->toArray();
    }

    private function apiSuccess(mixed $data = null, string $message = 'Success', int $statusCode = 200, string $code = 'SUCCESS'): JsonResponse
    {
        return response()->json([
            'success'     => true,
            'status_code' => $statusCode,
            'code'        => $code,
            'message'     => $message,
            'data'        => $data,
        ], $statusCode);
    }

    private function apiError(string $message = 'Something went wrong.', mixed $data = null, int $statusCode = 400, string $code = 'ERROR'): JsonResponse
    {
        return response()->json([
            'success'     => false,
            'status_code' => $statusCode,
            'code'        => $code,
            'message'     => $message,
            'data'        => $data,
        ], $statusCode);
    }
}


// namespace Modules\BLApplicantDetails\Http\Controllers;

// use App\Http\Controllers\Controller;
// use App\Traits\ApiResponse;
// use Illuminate\Http\Request;
// use Illuminate\Http\Response;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Validation\Rule;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Facades\URL;
// use Illuminate\Support\Facades\Log;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\BLApplicant\Models\BLApplicant;
// use Modules\BLApplicantDetails\Transformers\BLApplicantDetailsResource;
// use Modules\BLApplicantDetails\Models\BLApplicantDetails;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Schema;
// use Modules\BLJourney\Models\BLJourney;
// use Throwable;

// class BLApplicantDetailsController extends Controller
// {
//     use ApiResponse;

//     private const PHOTO_DISK       = 'private';
//     private const PHOTO_TTL_MINS   = 30;



//     public function show($lead_uuid, $applicant_id): JsonResponse
//     {
//         $blApplicantDetail = BLApplicantDetails::where('lead_uuid', $lead_uuid)
//             ->where('applicant_id', $applicant_id)
//             ->with(['leadCreation', 'applicant'])
//             ->first();

//         if (!$blApplicantDetail) {
//             return response()->json([
//                 'success'    => false,
//                 'status_code' => 404,
//                 'request_id' => app('request_id') ?? null,
//                 'timestamp'  => now()->toDateTimeString(),
//                 'message'    => 'Applicant detail not found',
//                 'data'       => null,
//             ], 404);
//         }

//         $resource = (new BLApplicantDetailsResource($blApplicantDetail))->toArray(request());

//         $resource['profile_photo_url'] = $this->generateSignedPhotoUrl($lead_uuid, $applicant_id, $blApplicantDetail);

//         return response()->json([
//             'success'    => true,
//             'status_code' => 200,
//             'request_id' => app('request_id') ?? null,
//             'timestamp'  => now()->toDateTimeString(),
//             'message'    => 'BL Applicant Detail retrieved successfully',
//             'data'       => $resource,
//         ], 200);
//     }



//     // public function update(Request $request, $lead_uuid, $applicant_id): JsonResponse
//     // {
//     //     $blApplicantDetail = BLApplicantDetails::where('lead_uuid', $lead_uuid)
//     //         ->where('applicant_id', $applicant_id)
//     //         ->first();

//     //     if (!$blApplicantDetail) {
//     //         return response()->json([
//     //             'success'    => false,
//     //             'status_code' => Response::HTTP_NOT_FOUND,
//     //             'request_id' => app('request_id'),
//     //             'timestamp'  => now()->format('Y-m-d H:i:s'),
//     //             'message'    => 'Applicant detail not found.',
//     //         ], 404);
//     //     }

//     //     $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
//     //     if (!$lead) {
//     //         return response()->json(['success' => false, 'message' => 'Lead not found.'], 404);
//     //     }

//     //     $validated = $request->validate([
//     //         'full_name'                  => 'required|string|max:255',
//     //         'gender'                     => 'required|in:Male,Female,Other',
//     //         'date_of_birth'              => 'required|date',
//     //         'age'                        => 'sometimes|string|min:18|max:100',
//     //         'nationality'                => 'required|string|max:100',
//     //         'religion'                   => 'required|string|max:100',
//     //         'caste'                      => 'required|string|max:100',
//     //         'marital_status'             => 'required|in:Single,Married,Divorced,Widowed,Separated',
//     //         'spouse_full_name'           => 'nullable|required_if:marital_status,Married|string|max:255',
//     //         'qualification'              => 'required|string|max:255',

//     //         // 'pan_card_number'            => 'nullable|string|max:20',
//     //         // 'aadhaar_card_number'        => 'nullable|string|max:20',
//     //         'aadhaar_card_number' => [
//     //             'required',
//     //             'string',
//     //             'regex:/^[0-9]{12}$/',
//     //         ],

//     //         'secondary_kyc_type' => [
//     //             'required',
//     //             Rule::in([
//     //                 'PAN Card',
//     //                 'Voter ID Card',
//     //                 'Driving Licence',
//     //                 'Passport',
//     //                 'Job Card',
//     //                 'Digital Ration Card',
//     //             ]),
//     //         ],

//     //         'secondary_kyc_number' => [
//     //             'required',
//     //             'string',
//     //             'max:50',
//     //         ],

//     //         'pan_card_number' => [
//     //             'nullable',
//     //             'string',
//     //             'max:20',
//     //             'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
//     //         ],
//     //         'email_address'              => 'required|email',
//     //         'alternate_contact_number'   => 'sometimes|string|max:15',
//     //         'father_full_name'           => 'required|string|max:255',
//     //         'mother_full_name'           => 'required|string|max:255',
//     //         'numbers_of_family_members'  => 'required|integer|min:0',
//     //         'numbers_of_earning_members' => 'required|integer|min:0',
//     //         'numbers_dependents'         => 'required|integer|min:0',
//     //         'is_income_considered'       => 'required|in:Yes,No',
//     //         'bureau_deviation'           => 'required|string|max:255',
//     //         'applying_as'                => 'required|string|in:Individual,Business',
//     //         'employment_type'            => 'required_if:applying_as,Individual|nullable|string|max:255',
//     //         'entity_type'                => 'required_if:applying_as,Business|nullable|string|max:255',
//     //         'relationship'               => 'required|string|max:255',
//     //         'premise_ownership'          => 'required|string|max:255',
//     //         'living_standard_indicators' => 'required|string|max:255',
//     //         'residence_in_months'        => 'required|integer|max:1200',
//     //         'current_address'            => 'required|string',
//     //         'current_landmark'           => 'nullable|string|max:255',
//     //         'current_pincode'            => 'required|string|max:10',
//     //         'current_district'           => 'required|string|max:255',
//     //         'current_city'               => 'required|string|max:255',
//     //         'current_state'              => 'required|string|max:255',
//     //         'current_staying_in_years'   => 'required|integer|min:0',
//     //         'permanent_address'          => 'sometimes|string',
//     //         'permanent_landmark'         => 'nullable|string|max:255',
//     //         'permanent_pincode'          => 'sometimes|string|max:10',
//     //         'permanent_district'         => 'sometimes|string|max:255',
//     //         'permanent_city'             => 'sometimes|string|max:255',
//     //         'permanent_state'            => 'sometimes|string|max:255',
//     //         'permanent_staying_in_years' => 'sometimes|integer|min:0',
//     //         'same_as_current_address'    => 'sometimes|boolean',
//     //         'is_co_applicant'            => 'sometimes|boolean',
//     //     ]);

//     //     $validated['aadhaar_card_number'] = preg_replace(
//     //         '/\D/',
//     //         '',
//     //         $validated['aadhaar_card_number']
//     //     );

//     //     $validated['secondary_kyc_number'] = strtoupper(
//     //         trim($validated['secondary_kyc_number'])
//     //     );

//     //     if (
//     //         isset($validated['gender'], $validated['employment_type']) &&
//     //         $validated['gender'] === 'Female' &&
//     //         $validated['employment_type'] === 'Housewife'
//     //     ) {
//     //         return response()->json(['success' => false, 'message' => 'Female co-applicant cannot be housewife.'], 422);
//     //     }



//     //     if (
//     //         isset($validated['is_income_considered'], $validated['employment_type']) &&
//     //         strtolower($validated['is_income_considered']) === 'yes' &&
//     //         strtolower($validated['employment_type']) === 'housewife'
//     //     ) {
//     //         return response()->json([
//     //             'success' => false,
//     //             'message' => 'Employment type cannot be Housewife when income is considered.',
//     //         ], 422);
//     //     }

//     //     $blApplicantDetail->update($validated);

//     //     $resource = (new BLApplicantDetailsResource($blApplicantDetail))->toArray(request());
//     //     $resource['profile_photo_url'] = $this->generateSignedPhotoUrl($lead_uuid, $applicant_id, $blApplicantDetail);

//     //     return response()->json([
//     //         'success'    => true,
//     //         'status_code' => 200,
//     //         'request_id' => app('request_id') ?? null,
//     //         'timestamp'  => now()->toDateTimeString(),
//     //         'message'    => 'BL Applicant Detail updated successfully',
//     //         'data'       => $resource,
//     //     ], 200);
//     // }

//     public function update(
//         Request $request,
//         $lead_uuid,
//         $applicant_id
//     ): JsonResponse {
//         $blApplicantDetail = BLApplicantDetails::where(
//             'lead_uuid',
//             $lead_uuid
//         )
//             ->where('applicant_id', $applicant_id)
//             ->first();

//         if (! $blApplicantDetail) {
//             return response()->json([
//                 'success'     => false,
//                 'status_code' => Response::HTTP_NOT_FOUND,
//                 'request_id'  => app('request_id'),
//                 'timestamp'   => now()->format('Y-m-d H:i:s'),
//                 'message'     => 'Applicant detail not found.',
//             ], Response::HTTP_NOT_FOUND);
//         }

//         $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();

//         if (! $lead) {
//             return response()->json([
//                 'success'     => false,
//                 'status_code' => Response::HTTP_NOT_FOUND,
//                 'request_id'  => app('request_id'),
//                 'timestamp'   => now()->format('Y-m-d H:i:s'),
//                 'message'     => 'Lead not found.',
//             ], Response::HTTP_NOT_FOUND);
//         }

//         $secondaryKycType = trim(
//             (string) $request->input('secondary_kyc_type', '')
//         );

//         $secondaryKycNumber = strtoupper(
//             preg_replace(
//                 '/\s+/',
//                 '',
//                 trim((string) $request->input('secondary_kyc_number', ''))
//             )
//         );

//         $request->merge([
//             'aadhaar_card_number' => filled($request->input('aadhaar_card_number'))
//                 ? preg_replace('/\D/', '', (string) $request->input('aadhaar_card_number'))
//                 : null,


//             'alternate_contact_number' => filled($request->input('alternate_contact_number'))
//                 ? preg_replace('/\D/', '', (string) $request->input('alternate_contact_number'))
//                 : null,

//             'secondary_kyc_type' => $secondaryKycType !== ''
//                 ? $secondaryKycType
//                 : null,

//             'secondary_kyc_number' => $secondaryKycNumber !== ''
//                 ? $secondaryKycNumber
//                 : null,
//         ]);

//         $secondaryKycNumberRules = [
//             'nullable',
//             'required_with:secondary_kyc_type',
//             'string',
//             'max:50',
//         ];

//         if ($secondaryKycType === 'PAN Card') {
//             $secondaryKycNumberRules[] = Rule::unique(
//                 'b_l_applicant_details',
//                 'pan_card_number'
//             )->ignore($blApplicantDetail->id, 'id');
//         }

//         $validated = $request->validate([
//             'full_name' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'gender' => [
//                 'required',
//                 Rule::in(['Male', 'Female', 'Other']),
//             ],

//             'date_of_birth' => [
//                 'required',
//                 'date',
//                 'before_or_equal:today',
//             ],

//             'age' => [
//                 'sometimes',
//                 'nullable',
//                 'string',
//                 'min:18',
//                 'max:100',
//             ],

//             'nationality' => [
//                 'required',
//                 'string',
//                 'max:100',
//             ],

//             'religion' => [
//                 'required',
//                 'string',
//                 'max:100',
//             ],

//             'caste' => [
//                 'required',
//                 'string',
//                 'max:100',
//             ],

//             'marital_status' => [
//                 'required',
//                 Rule::in([
//                     'Single',
//                     'Married',
//                     'Divorced',
//                     'Widowed',
//                     'Separated',
//                 ]),
//             ],

//             'spouse_full_name' => [
//                 'nullable',
//                 'required_if:marital_status,Married',
//                 'string',
//                 'max:255',
//             ],

//             'qualification' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],



//             'aadhaar_card_number' => [
//                 'nullable',
//                 'string',
//                 'regex:/^[0-9]{12}$/',

//                 Rule::unique(
//                     'b_l_applicant_details',
//                     'aadhaar_card_number'
//                 )->ignore($blApplicantDetail->id, 'id'),
//             ],



//             'secondary_kyc_type' => [
//                 'nullable',
//                 'required_with:secondary_kyc_number',
//                 Rule::in([
//                     'PAN Card',
//                     'Voter ID Card',
//                     'Driving Licence',
//                     'Passport',
//                     'Job Card',
//                     'Digital Ration Card',
//                 ]),
//             ],

//             // 'secondary_kyc_number' => [
//             //     'nullable',
//             //      'required_with:secondary_kyc_type',
//             //     'string',
//             //     'max:50',
//             // ],

//             'secondary_kyc_number' => $secondaryKycNumberRules,

//             'email_address' => [
//                  'nullable',
//                 'email',
//             ],

//             'alternate_contact_number' => [
//                 'sometimes',
//                 'nullable',
//                 'regex:/^[0-9]{10}$/',

//                 Rule::unique(
//                     'b_l_applicant_details',
//                     'alternate_contact_number'
//                 )->ignore($blApplicantDetail->id, 'id'),
//             ],

//             'father_full_name' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'mother_full_name' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'numbers_of_family_members' => [
//                 'required',
//                 'integer',
//                 'min:0',
//             ],

//             'numbers_of_earning_members' => [
//                 'required',
//                 'integer',
//                 'min:0',
//             ],

//             'numbers_dependents' => [
//                 'required',
//                 'integer',
//                 'min:0',
//             ],

//             'is_income_considered' => [
//                 'required',
//                 Rule::in(['Yes', 'No']),
//             ],

//             'bureau_deviation' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'applying_as' => [
//                 'required',
//                 Rule::in(['Individual', 'Business']),
//             ],

//             'employment_type' => [
//                 'required_if:applying_as,Individual',
//                 'nullable',
//                 'string',
//                 'max:255',
//             ],

//             'entity_type' => [
//                 'required_if:applying_as,Business',
//                 'nullable',
//                 'string',
//                 'max:255',
//             ],

//             'relationship' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'premise_ownership' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'living_standard_indicators' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'residence_in_months' => [
//                 'required',
//                 'integer',
//                 'min:0',
//                 'max:1200',
//             ],

//             'current_address' => [
//                 'required',
//                 'string',
//             ],

//             'current_landmark' => [
//                 'nullable',
//                 'string',
//                 'max:255',
//             ],

//             'current_pincode' => [
//                 'required',
//                 'string',
//                 'max:10',
//             ],

//             'current_district' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'current_city' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'current_state' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],

//             'current_staying_in_years' => [
//                 'required',
//                 'integer',
//                 'min:0',
//             ],

//             'permanent_address' => [
//                 'sometimes',
//                 'nullable',
//                 'string',
//             ],

//             'permanent_landmark' => [
//                 'nullable',
//                 'string',
//                 'max:255',
//             ],

//             'permanent_pincode' => [
//                 'sometimes',
//                 'nullable',
//                 'string',
//                 'max:10',
//             ],

//             'permanent_district' => [
//                 'sometimes',
//                 'nullable',
//                 'string',
//                 'max:255',
//             ],

//             'permanent_city' => [
//                 'sometimes',
//                 'nullable',
//                 'string',
//                 'max:255',
//             ],

//             'permanent_state' => [
//                 'sometimes',
//                 'nullable',
//                 'string',
//                 'max:255',
//             ],

//             'permanent_staying_in_years' => [
//                 'sometimes',
//                 'nullable',
//                 'integer',
//                 'min:0',
//             ],

//             'same_as_current_address' => [
//                 'sometimes',
//                 'boolean',
//             ],

//             'is_co_applicant' => [
//                 'sometimes',
//                 'boolean',
//             ],
//         ], [
//             'aadhaar_card_number.required' =>
//             'Aadhaar card number is required.',

//             'aadhaar_card_number.regex' =>
//             'Aadhaar card number must contain exactly 12 digits.',

//             'aadhaar_card_number.unique' =>
//             'This Aadhaar card number is already registered with another applicant.',

//             'alternate_contact_number.regex' =>
//             'Mobile number must contain exactly 10 digits.',

//             'alternate_contact_number.unique' =>
//             'This mobile number is already registered with another applicant.',

//             'secondary_kyc_number.unique' =>
//             'This PAN number is already registered with another applicant.',

//             'secondary_kyc_type.required' =>
//             'Please select a Secondary KYC document.',

//             'secondary_kyc_type.in' =>
//             'The selected Secondary KYC document is invalid.',

//             'secondary_kyc_number.required' =>
//             'Secondary KYC document number is required.',
//         ]);



//         $secondaryKycRules = [
//             'PAN Card' => [
//                 'pattern' => '/^[A-Z]{5}[0-9]{4}[A-Z]$/',
//                 'message' => 'PAN number must be valid format.',
//             ],

//             'Voter ID Card' => [
//                 'pattern' => '/^[A-Z]{3}[0-9]{7}$/',
//                 'message' =>
//                 'Voter ID number must be valid format.',
//             ],

//             'Driving Licence' => [
//                 'pattern' => '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,13}$/',
//                 'message' =>
//                 'Please enter a valid Driving Licence number.',
//             ],

//             'Passport' => [
//                 'pattern' => '/^[A-Z][0-9]{7}$/',
//                 'message' =>
//                 'Passport number must be valid format.',
//             ],

//             'Job Card' => [
//                 'pattern' => '/^[A-Z0-9\/\-]{5,50}$/',
//                 'message' =>
//                 'Please enter a valid Job Card number.',
//             ],

//             'Digital Ration Card' => [
//                 'pattern' => '/^[A-Z0-9\/\-]{5,50}$/',
//                 'message' =>
//                 'Please enter a valid Digital Ration Card number.',
//             ],
//         ];

//         $selectedKycType =
//             $validated['secondary_kyc_type'] ?? null;

//         $secondaryKycNumber =
//             $validated['secondary_kyc_number'] ?? null;

//         if ($selectedKycType !== null && $secondaryKycNumber !== null) {
//             $selectedKycRule = $secondaryKycRules[$selectedKycType];

//             if (! preg_match(
//                 $selectedKycRule['pattern'],
//                 $secondaryKycNumber
//             )) {
//                 return response()->json([
//                     'success'     => false,
//                     'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
//                     'request_id'  => app('request_id') ?? null,
//                     'timestamp'   => now()->format('Y-m-d H:i:s'),
//                     'message'     => $selectedKycRule['message'],
//                     'errors'      => [
//                         'secondary_kyc_number' => [
//                             $selectedKycRule['message'],
//                         ],
//                     ],
//                 ], Response::HTTP_UNPROCESSABLE_ENTITY);
//             }
//         }

//         $validated['pan_card_number'] =
//             $selectedKycType === 'PAN Card' && $secondaryKycNumber !== null
//             ? $secondaryKycNumber
//             : null;

//         // $selectedKycType = $validated['secondary_kyc_type'];
//         // $secondaryKycNumber = $validated['secondary_kyc_number'];
//         // $selectedKycRule = $secondaryKycRules[$selectedKycType];

//         // if (
//         //     ! preg_match(
//         //         $selectedKycRule['pattern'],
//         //         $secondaryKycNumber
//         //     )
//         // ) {
//         //     return response()->json([
//         //         'success'     => false,
//         //         'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
//         //         'request_id'  => app('request_id') ?? null,
//         //         'timestamp'   => now()->format('Y-m-d H:i:s'),
//         //         'message'     => $selectedKycRule['message'],
//         //         'errors'      => [
//         //             'secondary_kyc_number' => [
//         //                 $selectedKycRule['message'],
//         //             ],
//         //         ],
//         //     ], Response::HTTP_UNPROCESSABLE_ENTITY);
//         // }



//         // $validated['pan_card_number'] =
//         //     $selectedKycType === 'PAN Card'
//         //     ? $secondaryKycNumber
//         //     : null;



//         if (
//             isset(
//                 $validated['gender'],
//                 $validated['employment_type']
//             ) &&
//             $validated['gender'] === 'Female' &&
//             strtolower($validated['employment_type']) === 'housewife'
//         ) {
//             return response()->json([
//                 'success'     => false,
//                 'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
//                 'message'     =>
//                 'Female co-applicant cannot be housewife.',
//             ], Response::HTTP_UNPROCESSABLE_ENTITY);
//         }

//         if (
//             isset(
//                 $validated['is_income_considered'],
//                 $validated['employment_type']
//             ) &&
//             strtolower($validated['is_income_considered']) === 'yes' &&
//             strtolower($validated['employment_type']) === 'housewife'
//         ) {
//             return response()->json([
//                 'success'     => false,
//                 'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
//                 'message'     =>
//                 'Employment type cannot be Housewife when income is considered.',
//             ], Response::HTTP_UNPROCESSABLE_ENTITY);
//         }



//         $blApplicantDetail->update($validated);
//         $blApplicantDetail->refresh();

//         $resource = (
//             new BLApplicantDetailsResource($blApplicantDetail)
//         )->toArray($request);

//         $resource['profile_photo_url'] =
//             $this->generateSignedPhotoUrl(
//                 $lead_uuid,
//                 $applicant_id,
//                 $blApplicantDetail
//             );

//         return response()->json([
//             'success'     => true,
//             'status_code' => Response::HTTP_OK,
//             'request_id'  => app('request_id') ?? null,
//             'timestamp'   => now()->toDateTimeString(),
//             'message'     =>
//             'BL Applicant Detail updated successfully',
//             'data'        => $resource,
//         ], Response::HTTP_OK);
//     }

//     public function uploadPhoto(Request $request, string $lead_uuid, int $applicant_id): JsonResponse
//     {
//         $blApplicantDetail = BLApplicantDetails::where('lead_uuid', $lead_uuid)
//             ->where('applicant_id', $applicant_id)
//             ->first();

//         if (!$blApplicantDetail) {
//             return $this->apiError('Applicant detail not found.', null, 404, 'NOT_FOUND');
//         }

//         $request->validate([
//             'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
//         ]);

//         try {
//             if (
//                 !empty($blApplicantDetail->profile_photo) &&
//                 Storage::disk(self::PHOTO_DISK)->exists($blApplicantDetail->profile_photo)
//             ) {
//                 Storage::disk(self::PHOTO_DISK)->delete($blApplicantDetail->profile_photo);
//             }

//             $lead      = BLLeadCreation::where('uuid', $lead_uuid)->firstOrFail();
//             $file      = $request->file('photo');
//             $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
//             $folder    = "applicants/{$lead->lead_id}/{$applicant_id}/photos";
//             $filename  = 'live_photo.' . $extension;

//             $path = $file->storeAs($folder, $filename, self::PHOTO_DISK);


//             $blApplicantDetail->profile_photo = $path;
//             $blApplicantDetail->save();
//             $blApplicantDetail->refresh();
//             return $this->apiSuccess(
//                 [
//                     'applicant_id'      => $applicant_id,
//                     'profile_photo_url' => $this->generateSignedPhotoUrl($lead_uuid, $applicant_id, $blApplicantDetail),
//                 ],
//                 'Live photo uploaded successfully.',
//                 200,
//                 'PHOTO_UPLOADED'
//             );
//         } catch (Throwable $e) {
//             return $this->apiError(
//                 'Something went wrong while uploading photo.',
//                 app()->environment('local') ? $e->getMessage() : null,
//                 500,
//                 'SERVER_ERROR'
//             );
//         }
//     }



//     // public function viewPhoto(string $lead_uuid, int $applicant_id)
//     // {
//     //     $blApplicantDetail = BLApplicantDetails::where('lead_uuid', $lead_uuid)
//     //         ->where('applicant_id', $applicant_id)
//     //         ->first();

//     //     if (!$blApplicantDetail || empty($blApplicantDetail->profile_photo)) {
//     //         abort(404, 'Photo not found.');
//     //     }

//     //     if (!Storage::disk(self::PHOTO_DISK)->exists($blApplicantDetail->profile_photo)) {
//     //         abort(404, 'Photo file not found on storage.');
//     //     }

//     //     $fileContent = Storage::disk(self::PHOTO_DISK)->get($blApplicantDetail->profile_photo);
//     //     $extension   = strtolower(pathinfo($blApplicantDetail->profile_photo, PATHINFO_EXTENSION));

//     //     $mimeMap = [
//     //         'jpg'  => 'image/jpeg',
//     //         'jpeg' => 'image/jpeg',
//     //         'png'  => 'image/png',
//     //         'webp' => 'image/webp',
//     //     ];
//     //     $mimeType = $mimeMap[$extension] ?? 'image/jpeg';

//     //     return response($fileContent, 200)
//     //         ->header('Content-Type', $mimeType)
//     //         ->header('Content-Disposition', 'inline; filename="live_photo.' . $extension . '"')
//     //         ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
//     //         ->header('Pragma', 'no-cache');
//     // }

//     public function viewPhoto(string $lead_uuid, int $applicant_id)
//     {
//         $detail = BLApplicantDetails::query()
//             ->where('lead_uuid', $lead_uuid)
//             ->where('applicant_id', $applicant_id)
//             ->first();

//         if (! $detail) {
//             abort(404, 'Applicant not found.');
//         }

//         if (empty($detail->profile_photo)) {
//             abort(404, 'Applicant photo path is empty.');
//         }

//         $disk = Storage::disk(self::PHOTO_DISK);

//         if (! $disk->exists($detail->profile_photo)) {
//             Log::warning('APPLICANT_PHOTO_FILE_NOT_FOUND', [
//                 'lead_uuid'    => $lead_uuid,
//                 'applicant_id' => $applicant_id,
//                 'photo_path'   => $detail->profile_photo,
//             ]);

//             abort(404, 'Applicant photo file not found.');
//         }

//         $absolutePath = $disk->path($detail->profile_photo);

//         $extension = strtolower(
//             pathinfo($detail->profile_photo, PATHINFO_EXTENSION)
//         );

//         $mimeTypes = [
//             'jpg'  => 'image/jpeg',
//             'jpeg' => 'image/jpeg',
//             'png'  => 'image/png',
//             'webp' => 'image/webp',
//         ];

//         $mimeType = $mimeTypes[$extension] ?? 'image/jpeg';

//         return response()->file($absolutePath, [
//             'Content-Type'              => $mimeType,
//             'Content-Disposition'       =>
//             'inline; filename="' . basename($detail->profile_photo) . '"',
//             'Cache-Control'             => 'private, no-store, no-cache, max-age=0',
//             'Pragma'                    => 'no-cache',
//             'X-Content-Type-Options'    => 'nosniff',
//         ]);
//     }

//     public function finalValidation(Request $request): JsonResponse
//     {
//         try {
//             $request->validate([
//                 'lead_uuid' => 'required|uuid|exists:b_l_lead_creations,uuid'
//             ]);

//             $leadUuid = $request->lead_uuid;
//             $lead     = BLLeadCreation::where('uuid', $leadUuid)->first();

//             if (!$lead) {
//                 return $this->apiError('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//             }

//             $applicants = BLApplicantDetails::where('lead_uuid', $leadUuid)->get();

//             if ($applicants->isEmpty()) {
//                 return $this->apiError(
//                     'No applicants found.',
//                     ['lead_uuid' => $leadUuid],
//                     422,
//                     'NO_APPLICANTS_FOUND'
//                 );
//             }

//             $errors = [];


//             $mainApplicant = $applicants->firstWhere('applicant_type', 'applicant');
//             if (
//                 $mainApplicant &&
//                 in_array(strtolower((string) $mainApplicant->employment_type), ['housewife', 'house_wife'], true)
//             ) {
//                 $errors[] = 'Applicant cannot be a Housewife.';
//             }

//             foreach ($applicants as $applicant) {
//                 $isIncomeYes = (int) ($applicant->is_income_considered ?? 0) === 1
//                     || strtolower((string) ($applicant->is_income_considered ?? '')) === 'yes';

//                 $isHousewife = in_array(
//                     strtolower((string) ($applicant->employment_type ?? '')),
//                     ['housewife', 'house_wife'],
//                     true
//                 );

//                 if ($isIncomeYes && $isHousewife) {
//                     $applicantName = $applicant->full_name ?: 'Applicant';
//                     $errors[]      = "{$applicantName} ({$applicant->applicant_type}): income considered Yes but employment type is Housewife.";
//                 }
//             }

//             if (!empty($errors)) {
//                 return $this->apiError(
//                     'Applicant final validation failed.',
//                     ['lead_uuid' => $leadUuid, 'errors' => $errors],
//                     422,
//                     'APPLICANT_VALIDATION_FAILED'
//                 );
//             }

//             $journey = $this->createApplicantDetailsJourneyStep($lead, $request);

//             return $this->apiSuccess(
//                 [
//                     'lead_uuid'        => $leadUuid,
//                     'lead_id'          => $lead->lead_id,
//                     'journey'          => $journey,
//                     'total_applicants' => $applicants->count(),
//                 ],
//                 'Applicant details validated successfully. Moving to next step.',
//                 200,
//                 'APPLICANT_DETAILS_COMPLETED'
//             );
//         } catch (\Illuminate\Validation\ValidationException $e) {
//             return $this->apiError('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (Throwable $e) {
//             return $this->apiError(
//                 'Something went wrong while validating applicant details.',
//                 app()->environment('local') ? $e->getMessage() : null,
//                 500,
//                 'SERVER_ERROR'
//             );
//         }
//     }


//     public function checkInternalDuplicates($lead_uuid): JsonResponse
//     {
//         try {
//             $records = BLApplicantDetails::where('lead_uuid', $lead_uuid)->get();

//             if ($records->count() <= 1) {
//                 return $this->apiSuccess(
//                     ['lead_uuid' => $lead_uuid, 'duplicates' => []],
//                     'Not enough records to compare.',
//                     200,
//                     'NOT_ENOUGH_RECORDS'
//                 );
//             }

//             $duplicates = [];

//             for ($i = 0; $i < count($records); $i++) {
//                 for ($j = $i + 1; $j < count($records); $j++) {
//                     $first  = $records[$i];
//                     $second = $records[$j];

//                     $checks = [
//                         'alternate_contact_number' => fn($a, $b) => $a === $b,
//                         'email_address'            => fn($a, $b) => strtolower($a) === strtolower($b),
//                         'aadhaar_card_number'      => fn($a, $b) => $a === $b,
//                         'pan_card_number'          => fn($a, $b) => strtoupper($a) === strtoupper($b),
//                     ];

//                     foreach ($checks as $field => $comparator) {
//                         $val1 = (string) ($first->{$field}  ?? '');
//                         $val2 = (string) ($second->{$field} ?? '');

//                         if (!empty($val1) && $comparator($val1, $val2)) {
//                             $duplicates[] = [
//                                 'field'           => $field,
//                                 'value'           => $first->{$field},
//                                 'matched_between' => [
//                                     ['name' => $first->full_name,  'applicant_type' => $first->applicant_type,  'applicant_sno' => $first->applicant_sno],
//                                     ['name' => $second->full_name, 'applicant_type' => $second->applicant_type, 'applicant_sno' => $second->applicant_sno],
//                                 ],
//                             ];
//                         }
//                     }
//                 }
//             }

//             if (!empty($duplicates)) {
//                 return $this->apiError(
//                     'Internal duplicates found among applicants.',
//                     ['lead_uuid' => $lead_uuid, 'duplicates' => $duplicates],
//                     409,
//                     'INTERNAL_DUPLICATES_FOUND'
//                 );
//             }

//             return $this->apiSuccess(
//                 ['lead_uuid' => $lead_uuid, 'duplicates' => []],
//                 'No duplicates found among applicants.',
//                 200,
//                 'NO_DUPLICATES_FOUND'
//             );
//         } catch (Throwable $e) {
//             return $this->apiError(
//                 'Something went wrong while checking internal duplicates.',
//                 app()->environment('local') ? $e->getMessage() : null,
//                 500,
//                 'SERVER_ERROR'
//             );
//         }
//     }

//     private function generateSignedPhotoUrl(
//         string $lead_uuid,
//         int $applicant_id,
//         BLApplicantDetails $detail
//     ): ?string {
//         if (
//             empty($detail->profile_photo) ||
//             ! Storage::disk(self::PHOTO_DISK)->exists($detail->profile_photo)
//         ) {
//             return null;
//         }

//         return URL::temporarySignedRoute(
//             'api.bl.applicant.photo.view',
//             now()->addMinutes(self::PHOTO_TTL_MINS),
//             [
//                 'lead_uuid'    => $lead_uuid,
//                 'applicant_id' => $applicant_id,
//             ],
//             false
//         );
//     }


//     // private function generateSignedPhotoUrl(string $lead_uuid, int $applicant_id, BLApplicantDetails $detail): ?string
//     // {

//     //     if (empty($detail->profile_photo)) {
//     //         return null;
//     //     }


//     //     try {
//     //         return URL::temporarySignedRoute(
//     //             'api.bl.applicant.photo.view',
//     //             now()->addMinutes(self::PHOTO_TTL_MINS),
//     //             [
//     //                 'lead_uuid'    => $lead_uuid,
//     //                 'applicant_id' => $applicant_id,
//     //             ]
//     //         );
//     //     } catch (Throwable $e) {

//     //         Log::error('[BLApplicantDetails] generateSignedPhotoUrl failed', [
//     //             'lead_uuid'    => $lead_uuid,
//     //             'applicant_id' => $applicant_id,
//     //             'profile_photo' => $detail->profile_photo,
//     //             'error'        => $e->getMessage(),
//     //         ]);

//     //         return null;
//     //     }
//     // }

//     private function createApplicantDetailsJourneyStep(BLLeadCreation $lead, Request $request): BLJourney
//     {
//         BLJourney::where('lead_uuid', $lead->uuid)
//             ->where('is_latest', true)
//             ->update(['is_latest' => false]);

//         $workflowStage  = null;
//         $workflowStatus = null;

//         if (Schema::hasTable('b_l_workflow_stages')) {
//             $workflowStage = DB::table('b_l_workflow_stages')
//                 ->where('stage_code', 'APPLICANT_DETAILS')
//                 ->first();
//         }

//         if ($workflowStage && Schema::hasTable('b_l_workflow_statuses')) {
//             $workflowStatus = DB::table('b_l_workflow_statuses')
//                 ->where('stage_id', $workflowStage->id)
//                 ->whereIn('status_code', ['COMPLETED', 'SUBMITTED'])
//                 ->orderByRaw("FIELD(status_code, 'COMPLETED', 'SUBMITTED')")
//                 ->first();
//         }

//         $authUser = $request->user();

//         $payload = [
//             'lead_uuid'          => $lead->uuid,
//             'lead_id'            => $lead->lead_id,
//             'workflow_stage_id'  => $workflowStage->id ?? null,
//             'workflow_status_id' => $workflowStatus->id ?? null,
//             'previous_stage_id'  => null,
//             'action_performed'   => 'APPLICANT_DETAILS_COMPLETED',
//             'performed_by_role'  => $authUser->user_role ?? $authUser->user_designation ?? $authUser->user_type ?? null,
//             'transition_type'    => 'FORWARD',
//             'stage'              => $workflowStage->stage_name ?? 'Applicant Details',
//             'status'             => $workflowStatus->status_name ?? 'Completed',
//             'team'               => $workflowStage->team_name ?? 'Sales',
//             'action_type'        => 'APPLICANT_DETAILS_COMPLETED',
//             'remarks'            => 'Applicant details completed and validated successfully.',
//             'user_id'            => $authUser->id ?? null,
//             'user_name'          => $authUser->user_name ?? $authUser->name ?? null,
//             'is_latest'          => true,
//             'ip_address'         => $request->ip(),
//             'device_info'        => $request->header('User-Agent'),
//             'created_by'         => $authUser->id ?? null,
//             'is_completed'       => true,
//         ];

//         return BLJourney::create($this->onlyExistingColumns('b_l_journeys', $payload));
//     }

//     private function onlyExistingColumns(string $table, array $payload): array
//     {
//         return collect($payload)
//             ->filter(fn($value, $key) => Schema::hasColumn($table, $key))
//             ->toArray();
//     }

//     private function apiSuccess(mixed $data = null, string $message = 'Success', int $statusCode = 200, string $code = 'SUCCESS'): JsonResponse
//     {
//         return response()->json([
//             'success'     => true,
//             'status_code' => $statusCode,
//             'code'        => $code,
//             'message'     => $message,
//             'data'        => $data,
//         ], $statusCode);
//     }

//     private function apiError(string $message = 'Something went wrong.', mixed $data = null, int $statusCode = 400, string $code = 'ERROR'): JsonResponse
//     {
//         return response()->json([
//             'success'     => false,
//             'status_code' => $statusCode,
//             'code'        => $code,
//             'message'     => $message,
//             'data'        => $data,
//         ], $statusCode);
//     }
// }
