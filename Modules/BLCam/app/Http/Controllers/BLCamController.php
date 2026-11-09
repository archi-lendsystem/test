<?php

namespace Modules\BLCam\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantRole\Models\BLApplicantRole;
use Modules\BLApplicantRoleDetails\Models\BLApplicantRoleDetails;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLCam\Models\BLCam;
use Modules\BLCam\Transformers\BLCamResource;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLOfficeDetails\Models\BLOfficeDetail;
use Modules\BLReference\Models\BLReference;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;
use Modules\UserManagement\Models\User;
use Modules\BLIMDDetailsVerification\Models\BLIMDDetailsVerification;
use Modules\BLTeleVerification\Models\BLTeleVerification;
use Modules\BLReferenceVerification\Models\BLReferenceVerification;
use Modules\BLSchemeSelect\Models\BLSchemeSelect;
use Modules\BLAmortization\Services\AmortizationCalculatorService;
use Throwable;
use Illuminate\Support\Str;

class BLCamController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AmortizationCalculatorService $calculatorService
    ) {}

    public function show(Request $request, string $lead_uuid): JsonResponse
    {
        try {
            $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();

            if (! $lead) {
                return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
            }

            $schemeSelection = BLSchemeSelect::query()
                ->with('productScheme')
                ->where('lead_uuid', $lead_uuid)
                ->first();

            $selectedScheme = $schemeSelection?->productScheme;

            $applicants = BLApplicant::where('lead_uuid', $lead_uuid)
                ->with(['detail', 'businessDetail'])
                ->get()
                ->map(function ($applicant) use ($lead_uuid) {

                    $detail         = $applicant->detail;
                    $businessDetail = $applicant->businessDetail;

                    $officeDetail = BLOfficeDetail::where('lead_uuid', $lead_uuid)
                        ->where('applicant_id', $applicant->id)
                        ->first();

                    $bankDetails = BLBankDetail::where('lead_uuid', $lead_uuid)
                        ->where('applicant_id', $applicant->id)
                        ->get()
                        ->map(fn($b) => [
                            'id'                 => $b->id,
                            'applicant_sno'      => $b->applicant_sno,
                            'applicant_type'     => $b->applicant_type,
                            'bank_name'          => $b->bank_name,
                            'account_type'       => $b->account_type,
                            'statement_file'     => $b->statement_file,
                            'statement_password' => $b->statement_password,
                            'status'             => $b->status,
                            'remarks'            => $b->remarks,
                        ]);

                    $roles = BLApplicantRole::where('lead_uuid', $lead_uuid)
                        ->where('applicant_id', $applicant->id)
                        ->get()
                        ->map(function ($role) use ($lead_uuid) {

                            $roleDetails = BLApplicantRoleDetails::where('lead_uuid', $lead_uuid)
                                ->where('applicant_id', $role->applicant_id)
                                ->where('applicant_role_id', $role->id)
                                ->get()
                                ->map(fn($rd) => [
                                    'id'               => $rd->id,
                                    'full_name'        => $rd->full_name,
                                    'mother_name'      => $rd->mother_name,
                                    'date_of_birth'    => $rd->date_of_birth,
                                    'mobile_number'    => $rd->mobile_number,
                                    'gender'           => $rd->gender,
                                    'pan_number'       => $rd->pan_number,
                                    'residence_address' => $rd->residence_address,
                                    'pincode'          => $rd->pincode,
                                    'district'         => $rd->district,
                                    'city'             => $rd->city,
                                    'state'            => $rd->state,
                                    'ownership_status' => $rd->ownership_status,
                                ]);

                            return [
                                'id'             => $role->id,
                                'sno'            => $role->sno,
                                'applicant_sno'  => $role->applicant_sno,
                                'applicant_type' => $role->applicant_type,
                                'type'           => $role->type,
                                'role'           => $role->role,
                                'role_details'   => $roleDetails,
                            ];
                        });

                    $BLIMDDetailsVerification = BLIMDDetailsVerification::where('lead_uuid', $lead_uuid)
                        ->where('applicant_id', $applicant->id)
                        ->first();

                    // $teleVerification = BLTeleVerification::where('lead_uuid', $lead_uuid)
                    //     ->where('applicant_sno', (string) $applicant->applicant_sno)
                    //     ->first();

                    $teleVerification = BLTeleVerification::query()
                        ->with('verifiedBy')
                        ->where('lead_uuid', $lead_uuid)
                        ->where('applicant_sno', $applicant->applicant_sno)
                        ->first();

                    return [
                        'id'              => $applicant->id,
                        'applicant_sno'   => $applicant->applicant_sno,
                        'applicant_types' => $applicant->applicant_types,

                        'personal_details' => $detail ? [
                            'id'                        => $detail->id,
                            'full_name'                 => $detail->full_name,
                            'gender'                    => $detail->gender,
                            'date_of_birth'             => $detail->date_of_birth,
                            'age'                       => $detail->age,
                            'nationality'               => $detail->nationality,
                            'religion'                  => $detail->religion,
                            'caste'                     => $detail->caste,
                            'marital_status'            => $detail->marital_status,
                            'qualification'             => $detail->qualification,
                            'pan_card_number'           => $detail->pan_card_number,
                            'secondary_kyc_type'        => $detail->secondary_kyc_type,
                            'secondary_kyc_number'      => $detail->secondary_kyc_number,
                            'aadhaar_card_number'       => $detail->aadhaar_card_number,
                            'email_address'             => $detail->email_address,
                            'alternate_contact_number'  => $detail->alternate_contact_number,
                            'employment_type'           => $detail->employment_type,
                            'entity_type'               => $detail->entity_type,
                            'applying_as'               => $detail->applying_as,
                            'is_income_considered'      => $detail->is_income_considered,
                            'father_full_name'          => $detail->father_full_name,
                            'mother_full_name'          => $detail->mother_full_name,
                            'spouse_full_name'          => $detail->spouse_full_name,
                            'premise_ownership'         => $detail->premise_ownership,
                            'numbers_of_family_members' => $detail->numbers_of_family_members,
                            'numbers_of_earning_members' => $detail->numbers_of_earning_members,
                            'numbers_dependents'        => $detail->numbers_dependents,
                            'current_address'           => $detail->current_address,
                            'current_pincode'           => $detail->current_pincode,
                            'current_district'          => $detail->current_district,
                            'current_city'              => $detail->current_city,
                            'current_state'             => $detail->current_state,
                            'current_staying_in_years'  => $detail->current_staying_in_years,
                            'permanent_address'         => $detail->permanent_address,
                            'permanent_pincode'         => $detail->permanent_pincode,
                            'permanent_district'        => $detail->permanent_district,
                            'permanent_city'            => $detail->permanent_city,
                            'permanent_state'           => $detail->permanent_state,
                            'same_as_current_address'   => $detail->same_as_current_address,
                        ] : null,

                        'business_details' => $businessDetail ? [
                            'id'                        => $businessDetail->id,
                            'business_name'             => $businessDetail->business_name,
                            'nature_of_business'        => $businessDetail->nature_of_business,
                            'incorporation_date'        => $businessDetail->incorporation_date,
                            'pan_number'                => $businessDetail->pan_number,
                            'monthly_sales'             => $businessDetail->monthly_sales,
                            'office_address'            => $businessDetail->office_address,
                            'pincode'                   => $businessDetail->pincode,
                            'district'                  => $businessDetail->district,
                            'city'                      => $businessDetail->city,
                            'state'                     => $businessDetail->state,
                            'business_premise_ownership' => $businessDetail->business_premise_ownership,
                        ] : null,

                        'office_details' => $officeDetail ? [
                            'id'               => $officeDetail->id,
                            'address'          => $officeDetail->address,
                            'landmark'         => $officeDetail->landmark,
                            'pincode'          => $officeDetail->pincode,
                            'district'         => $officeDetail->district,
                            'city'             => $officeDetail->city,
                            'state'            => $officeDetail->state,
                            'working_in_months' => $officeDetail->working_in_months,
                        ] : null,

                        'bank_details' => $bankDetails,

                        'roles' => $roles,

                        'imd_verification' => $BLIMDDetailsVerification ? [
                            'id'           => $BLIMDDetailsVerification->id,
                            'lead_uuid'    => $BLIMDDetailsVerification->lead_uuid,
                            'lead_id'      => $BLIMDDetailsVerification->lead_id,
                            'applicant_id' => $BLIMDDetailsVerification->applicant_id,
                            'status'       => $BLIMDDetailsVerification->status,
                            'remarks'      => $BLIMDDetailsVerification->remarks,
                            'consent_given' => $BLIMDDetailsVerification->consent_given,
                            'consent_at'   => $BLIMDDetailsVerification->consent_at?->toISOString(),
                            'verified_by'  => $BLIMDDetailsVerification->verified_by,
                            'verified_at'  => $BLIMDDetailsVerification->verified_at?->toISOString(),
                            'created_at'   => $BLIMDDetailsVerification->created_at?->toISOString(),
                            'updated_at'   => $BLIMDDetailsVerification->updated_at?->toISOString(),
                        ] : null,

                        'tele_verification' => $teleVerification ? [
                            'id'                    => $teleVerification->id,
                            'lead_uuid'             => $teleVerification->lead_uuid,
                            'lead_id'               => $teleVerification->lead_id,
                            'applicant_sno'         => $teleVerification->applicant_sno,
                            'applicant_type'        => $teleVerification->applicant_type,

                            'tvr_flag'              => $teleVerification->tvr_flag,
                            'tvr_status'            => $teleVerification->tvr_status,
                            'tvr_risk_assessment'   => $teleVerification->tvr_risk_assessment,
                            'tvr_completed_at'      => $teleVerification->tvr_completed_at?->toISOString(),

                            'q1_name_correct'       => $teleVerification->q1_name_correct,
                            'q1_correct_name'       => $teleVerification->q1_correct_name,
                            'q2_mobile_verified'    => $teleVerification->q2_mobile_verified,
                            'q3_address_correct'    => $teleVerification->q3_address_correct,
                            'q3_updated_address'    => $teleVerification->q3_updated_address,

                            'q4_self_employed'      => $teleVerification->q4_self_employed,
                            'q4_business_type'      => $teleVerification->q4_business_type,
                            'q5_business_vintage'   => $teleVerification->q5_business_vintage,
                            'q5_vintage_years'      => $teleVerification->q5_vintage_years,
                            'q6_fulltime_business'  => $teleVerification->q6_fulltime_business,

                            'q7_salaried'           => $teleVerification->q7_salaried,
                            'q8_employer_name'      => $teleVerification->q8_employer_name,
                            'q9_employment_type'    => $teleVerification->q9_employment_type,
                            'q10_total_experience'  => $teleVerification->q10_total_experience,
                            'q11_current_tenure'    => $teleVerification->q11_current_tenure,
                            'q12_salary_bank'       => $teleVerification->q12_salary_bank,

                            'q13_income_threshold'  => $teleVerification->q13_income_threshold,
                            'q13_monthly_income'    => $teleVerification->q13_monthly_income,
                            'q14_income_banking'    => $teleVerification->q14_income_banking,
                            'q15_additional_income' => $teleVerification->q15_additional_income,
                            'q15_income_source'     => $teleVerification->q15_income_source,
                            'q15_income_amount'     => $teleVerification->q15_income_amount,

                            'q16_married'           => $teleVerification->q16_married,
                            'q17_spouse_income'     => $teleVerification->q17_spouse_income,
                            'q17_spouse_income_nature' => $teleVerification->q17_spouse_income_nature,
                            'q18_dependents'        => $teleVerification->q18_dependents,
                            'q18_dependent_count'   => $teleVerification->q18_dependent_count,
                            'q19_expenses'          => $teleVerification->q19_expenses,
                            'q19_expense_amount'    => $teleVerification->q19_expense_amount,

                            'q20_existing_loans'    => $teleVerification->q20_existing_loans,
                            'q20_loan_details'      => $teleVerification->q20_loan_details,
                            'q20_emi_amount'        => $teleVerification->q20_emi_amount,
                            'q21_loan_defaults'     => $teleVerification->q21_loan_defaults,

                            'q27_emi_aware'         => $teleVerification->q27_emi_aware,
                            'q28_other_applications' => $teleVerification->q28_other_applications,

                            'q29_consent'           => $teleVerification->q29_consent,
                            'q30_information_true'  => $teleVerification->q30_information_true,

                            'q31_tvr_status'        => $teleVerification->q31_tvr_status,
                            'q32_risk_assessment'   => $teleVerification->q32_risk_assessment,
                            'q33_caller_remarks'    => $teleVerification->q33_caller_remarks,
                            'q34_recommended_action' => $teleVerification->q34_recommended_action,

                            'consent_given'         => $teleVerification->consent_given,
                            'consent_at'            => $teleVerification->consent_at?->toISOString(),
                            // 'verified_by'           => $teleVerification->verified_by,
                            'verified_by' => $teleVerification->verifiedBy?->user_name,
                            'verified_at'           => $teleVerification->verified_at?->toISOString(),
                            'ip_address'            => $teleVerification->ip_address,
                            'created_at'            => $teleVerification->created_at?->toISOString(),
                            'updated_at'            => $teleVerification->updated_at?->toISOString(),
                        ] : null,
                    ];
                });

            $references = BLReference::where('lead_uuid', $lead_uuid)
                ->get()
                ->map(function ($ref) use ($lead_uuid) {

                    $refDetails = BLReferenceDetails::where('lead_uuid', $lead_uuid)
                        ->where('reference_id', $ref->id)
                        ->first();

                    $refVerification = BLReferenceVerification::where('lead_uuid', $lead_uuid)
                        ->where('reference_id', $ref->id)
                        ->first();

                    return [
                        'id'              => $ref->id,
                        'reference_sno'   => $ref->reference_sno,
                        'reference_types' => $ref->reference_types,

                        'details' => $refDetails ? [
                            'id'           => $refDetails->id,
                            'full_name'    => $refDetails->full_name,
                            'mobile_no'    => $refDetails->mobile_no,
                            'email'        => $refDetails->email,
                            'gender'       => $refDetails->gender,
                            'relationship' => $refDetails->relationship,
                            'address'      => $refDetails->address,
                            'pincode'      => $refDetails->pincode,
                            'district'     => $refDetails->district,
                            'city'         => $refDetails->city,
                            'state'        => $refDetails->state,
                            'status'       => $refDetails->status,
                            'remarks'      => $refDetails->remarks,
                        ] : null,

                        'verification' => $refVerification ? [
                            'id'                  => $refVerification->id,
                            'reference_id'        => $refVerification->reference_id,
                            'reference_detail_id' => $refVerification->reference_detail_id,
                            'status'              => $refVerification->status,
                            'remarks'             => $refVerification->remarks,
                            'known_since'         => $refVerification->Known_since,
                            'created_at'          => $refVerification->created_at?->toISOString(),
                            'updated_at'          => $refVerification->updated_at?->toISOString(),
                        ] : null,
                    ];
                });

            $assignableUsers = User::query()
                ->select('id', 'user_name')
                ->where('is_active', true)
                ->where('id', '!=', auth()->id())
                ->when($request->filled('search'), function ($query) use ($request) {
                    $query->where('user_name', 'like', '%' . $request->input('search') . '%');
                })
                ->orderBy('user_name', 'asc')
                ->get();

            $camSummary = $this->buildCamSummary($lead, $applicants, $references);

            // Flag for the frontend: is this scheme a one-time (payday) repayment?
            $isOneTimePayment = $selectedScheme
                ? $this->isOneTimePayment($selectedScheme)
                : false;

            return $this->success(
                [
                    'lead' => [
                        'uuid'          => $lead->uuid,
                        'lead_id'       => $lead->lead_id,
                        'loan_type'     => $lead->loan_type,
                        'customer_type' => $lead->customer_type,
                        'sub_loan_type' => $lead->sub_loan_type,
                        'source_by'     => $lead->source_by,
                        'email'         => $lead->email,
                        'mobile_number' => $lead->mobile_number,
                        'loan_amount'   => $lead->loan_amount,
                        'loan_tenur'    => $lead->loan_tenur,
                        'created_at'    => $lead->created_at?->toISOString(),
                    ],

                    'selected_scheme' => $selectedScheme ? [
                        'uuid'        => $selectedScheme->uuid,
                        'scheme_name' => $selectedScheme->scheme_name,

                        'interest_rate' => $selectedScheme->interest_rate !== null
                            ? (float) $selectedScheme->interest_rate
                            : null,

                        'tenure_value' => $selectedScheme->tenure_value !== null
                            ? (int) $selectedScheme->tenure_value
                            : null,

                        'tenure_unit'         => $selectedScheme->tenure_unit,
                        'repayment_frequency' => $selectedScheme->repayment_frequency,
                        'calculation_method'  => $selectedScheme->calculation_method,

                        'is_one_time_payment' => $isOneTimePayment,

                        'minimum_pf' => $selectedScheme->minimum_pf !== null
                            ? (float) $selectedScheme->minimum_pf
                            : null,

                        'maximum_pf' => $selectedScheme->maximum_pf !== null
                            ? (float) $selectedScheme->maximum_pf
                            : null,

                        'lpf_rate' => $selectedScheme->lpf_rate !== null
                            ? (float) $selectedScheme->lpf_rate
                            : null,
                    ] : null,

                    'cam_defaults' => $selectedScheme ? [
                        'roi' => $selectedScheme->interest_rate !== null
                            ? (float) $selectedScheme->interest_rate
                            : null,

                        'loan_tenure' => $selectedScheme->tenure_value !== null
                            ? (int) $selectedScheme->tenure_value
                            : null,

                        'minimum_pf_percentage' => $selectedScheme->minimum_pf !== null
                            ? (float) $selectedScheme->minimum_pf
                            : null,

                        'maximum_pf_percentage' => $selectedScheme->maximum_pf !== null
                            ? (float) $selectedScheme->maximum_pf
                            : null,

                        'tenure_unit' => $selectedScheme->tenure_unit,

                        'is_one_time_payment' => $isOneTimePayment,
                    ] : null,

                    'cam_summary'      => $camSummary,
                    'applicants'       => $applicants,
                    'references'       => $references,
                    'assignable_users' => $assignableUsers,
                ],
                'BL CAM data fetched successfully.',
                200,
                'BL_CAM_FETCHED_SUCCESSFULLY'
            );
        } catch (Throwable $e) {
            return $this->error(
                'Something went wrong while fetching BL CAM data.',
                app()->environment('local') ? $e->getMessage() : null,
                500,
                'SERVER_ERROR'
            );
        }
    }




    public function store(Request $request, string $lead_uuid): JsonResponse
    {
        try {
            $lead = BLLeadCreation::query()
                ->where('uuid', $lead_uuid)
                ->first();

            if (! $lead) {
                return $this->error(
                    'Lead not found.',
                    null,
                    404,
                    'LEAD_NOT_FOUND'
                );
            }


            $validatedData = $request->validate([
                'status' => [
                    'required',
                    'string',
                    'in:Approve,Reject',
                ],

                'loan_amount' => [
                    'required_if:status,Approve',
                    'nullable',
                    'numeric',
                    'min:1',
                ],

                'loan_tenure' => [
                    'required_if:status,Approve',
                    'nullable',
                    'integer',
                    'min:1',
                    'max:600',
                ],

                'roi' => [
                    'required_if:status,Approve',
                    'nullable',
                    'numeric',
                    'min:0',
                    'max:100',
                ],

                'pf_percentage' => [
                    'required_if:status,Approve',
                    'nullable',
                    'numeric',
                    'between:0,100',
                ],

                'gst' => [
                    'required_if:status,Approve',
                    'nullable',
                    'numeric',
                    'min:0',
                    'max:100',
                ],

                'forward_to_user_id' => [
                    'nullable',
                    'exists:users,id',
                ],

                'forward_to' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'remarks' => [
                    'required_if:status,Reject',
                    'nullable',
                    'string',
                    'max:1000',
                ],
            ]);

            $isApprove = strtolower($validatedData['status']) === 'approve';

            if ($isApprove) {
                $alreadyApproved = BLCam::query()
                    ->where('lead_uuid', $lead_uuid)
                    ->where('status', 'Approve')
                    ->exists();

                if ($alreadyApproved) {
                    return $this->error(
                        'This lead has already been approved.',
                        null,
                        422,
                        'ALREADY_APPROVED'
                    );
                }
            }

            $scheme = null;

            if ($isApprove) {
                $schemeSelection = BLSchemeSelect::query()
                    ->with('productScheme')
                    ->where('lead_uuid', $lead_uuid)
                    ->first();

                if (! $schemeSelection) {
                    return $this->error(
                        'Please select a product scheme before approving CAM.',
                        null,
                        422,
                        'PRODUCT_SCHEME_NOT_SELECTED'
                    );
                }

                $scheme = $schemeSelection->productScheme;

                if (! $scheme) {
                    return $this->error(
                        'Selected product scheme not found.',
                        null,
                        404,
                        'PRODUCT_SCHEME_NOT_FOUND'
                    );
                }

                if (! $scheme->is_active) {
                    return $this->error(
                        'Selected product scheme is inactive.',
                        null,
                        422,
                        'PRODUCT_SCHEME_INACTIVE'
                    );
                }

                if (
                    $scheme->valid_from &&
                    $scheme->valid_from->isAfter(now()->startOfDay())
                ) {
                    return $this->error(
                        'Selected product scheme is not active yet.',
                        [
                            'valid_from' => $scheme->valid_from->format('Y-m-d'),
                        ],
                        422,
                        'PRODUCT_SCHEME_NOT_STARTED'
                    );
                }

                if (
                    $scheme->valid_till &&
                    $scheme->valid_till->isBefore(now()->startOfDay())
                ) {
                    return $this->error(
                        'Selected product scheme has expired.',
                        [
                            'valid_till' => $scheme->valid_till->format('Y-m-d'),
                        ],
                        422,
                        'PRODUCT_SCHEME_EXPIRED'
                    );
                }

                if ($scheme->interest_rate === null) {
                    return $this->error(
                        'ROI is not configured in the selected scheme.',
                        [
                            'roi' => [
                                'Selected product scheme does not have ROI.',
                            ],
                        ],
                        422,
                        'SCHEME_ROI_MISSING'
                    );
                }


                $postedRoi = round((float) $validatedData['roi'], 2);
                $schemeRoi = round((float) $scheme->interest_rate, 2);

                if ($postedRoi !== $schemeRoi) {
                    return $this->error(
                        'Posted ROI does not match the selected scheme ROI.',
                        [
                            'roi' => [
                                "ROI must be {$schemeRoi}%.",
                            ],
                        ],
                        422,
                        'ROI_DOES_NOT_MATCH_SCHEME'
                    );
                }

                $tenureType = $scheme->tenure_type ?: 'fixed';
                $postedTenure = (int) $validatedData['loan_tenure'];


                if ($tenureType === 'fixed') {
                    if (
                        $scheme->tenure_value === null ||
                        (int) $scheme->tenure_value < 1
                    ) {
                        return $this->error(
                            'Fixed tenure is not configured in the selected scheme.',
                            [
                                'loan_tenure' => [
                                    'Selected scheme does not have a valid tenure.',
                                ],
                            ],
                            422,
                            'SCHEME_FIXED_TENURE_MISSING'
                        );
                    }

                    if ($postedTenure !== (int) $scheme->tenure_value) {
                        return $this->error(
                            'Posted tenure does not match the selected scheme tenure.',
                            [
                                'loan_tenure' => [
                                    'Tenure must be ' .
                                        $scheme->tenure_value .
                                        ' ' .
                                        $scheme->tenure_unit .
                                        '.',
                                ],
                            ],
                            422,
                            'TENURE_DOES_NOT_MATCH_SCHEME'
                        );
                    }
                }


                if ($tenureType === 'range') {
                    if (
                        $scheme->minimum_tenure === null ||
                        $scheme->maximum_tenure === null
                    ) {
                        return $this->error(
                            'Tenure range is not configured in the selected scheme.',
                            null,
                            422,
                            'SCHEME_TENURE_RANGE_MISSING'
                        );
                    }

                    if (
                        $postedTenure < (int) $scheme->minimum_tenure ||
                        $postedTenure > (int) $scheme->maximum_tenure
                    ) {
                        return $this->error(
                            'Loan tenure is outside the selected scheme range.',
                            [
                                'loan_tenure' => [
                                    'Tenure must be between ' .
                                        $scheme->minimum_tenure .
                                        ' and ' .
                                        $scheme->maximum_tenure .
                                        ' ' .
                                        $scheme->tenure_unit .
                                        '.',
                                ],
                            ],
                            422,
                            'LOAN_TENURE_OUTSIDE_SCHEME_RANGE'
                        );
                    }
                }

                if (! in_array($tenureType, ['fixed', 'range'], true)) {
                    return $this->error(
                        'Selected scheme has an invalid tenure type.',
                        null,
                        422,
                        'INVALID_SCHEME_TENURE_TYPE'
                    );
                }

                $loanAmount = (float) $validatedData['loan_amount'];
                $pfPercentage = (float) $validatedData['pf_percentage'];

                if (
                    $scheme->minimum_loan_amount !== null &&
                    (float) $scheme->minimum_loan_amount > 0 &&
                    $loanAmount < (float) $scheme->minimum_loan_amount
                ) {
                    return $this->error(
                        'Sanction amount is below the scheme minimum amount.',
                        [
                            'loan_amount' => [
                                'Minimum allowed amount is ' .
                                    (float) $scheme->minimum_loan_amount .
                                    '.',
                            ],
                        ],
                        422,
                        'LOAN_AMOUNT_BELOW_SCHEME_LIMIT'
                    );
                }

                if (
                    $scheme->maximum_loan_amount !== null &&
                    (float) $scheme->maximum_loan_amount > 0 &&
                    $loanAmount > (float) $scheme->maximum_loan_amount
                ) {
                    return $this->error(
                        'Sanction amount exceeds the scheme maximum amount.',
                        [
                            'loan_amount' => [
                                'Maximum allowed amount is ' .
                                    (float) $scheme->maximum_loan_amount .
                                    '.',
                            ],
                        ],
                        422,
                        'LOAN_AMOUNT_ABOVE_SCHEME_LIMIT'
                    );
                }

                if (
                    $scheme->minimum_pf !== null &&
                    $pfPercentage < (float) $scheme->minimum_pf
                ) {
                    return $this->error(
                        'PF percentage is below the scheme minimum PF.',
                        [
                            'pf_percentage' => [
                                'Minimum allowed PF is ' .
                                    (float) $scheme->minimum_pf .
                                    '%.',
                            ],
                        ],
                        422,
                        'PF_BELOW_SCHEME_LIMIT'
                    );
                }

                if (
                    $scheme->maximum_pf !== null &&
                    $pfPercentage > (float) $scheme->maximum_pf
                ) {
                    return $this->error(
                        'PF percentage exceeds the scheme maximum PF.',
                        [
                            'pf_percentage' => [
                                'Maximum allowed PF is ' .
                                    (float) $scheme->maximum_pf .
                                    '%.',
                            ],
                        ],
                        422,
                        'PF_ABOVE_SCHEME_LIMIT'
                    );
                }
            }

            $currentUserName = auth()->check()
                ? auth()->user()->user_name
                : 'System';

            $loan = DB::transaction(function () use (
                $validatedData,
                $lead_uuid,
                $currentUserName,
                $lead,
                $scheme,
                $isApprove
            ) {
                $forwardToName = null;

                if (! empty($validatedData['forward_to_user_id'])) {
                    $forwardUser = User::query()
                        ->find($validatedData['forward_to_user_id']);

                    $forwardToName = $forwardUser?->user_name;
                } elseif (! empty($validatedData['forward_to'])) {
                    $forwardToName = $validatedData['forward_to'];
                }

                $loan = new BLCam();

                $loan->lead_uuid = $lead_uuid;
                $loan->status = $validatedData['status'];
                $loan->forward_to = $forwardToName;
                $loan->forward_by = $currentUserName;

                if (! $isApprove) {
                    $loan->loan_amount = null;
                    $loan->loan_tenure = null;
                    $loan->roi = null;
                    $loan->pf = null;
                    $loan->gst = null;
                    $loan->remarks = $validatedData['remarks'];
                    $loan->save();

                    return $loan;
                }

                $loanAmount = (float) $validatedData['loan_amount'];

                $interestRate = (float) $scheme->interest_rate;


                $loanTenure = ($scheme->tenure_type ?: 'fixed') === 'fixed'
                    ? (int) $scheme->tenure_value
                    : (int) $validatedData['loan_tenure'];

                $tenureUnit = strtolower(
                    trim((string) $scheme->tenure_unit)
                );

                $repaymentFrequency = $this->normalizeFrequency(
                    $scheme->repayment_frequency
                );

                $calculationMethod = $this->normalizeCalculationMethod(
                    $scheme->calculation_method
                );

                $pfPercentage = (float) $validatedData['pf_percentage'];
                $gstPercentage = (float) $validatedData['gst'];

                $processingFee = round(
                    ($loanAmount * $pfPercentage) / 100,
                    2
                );

                $processingFeeGst = round(
                    ($processingFee * $gstPercentage) / 100,
                    2
                );


                $calculation = $this->calculateFirstInstallment(
                    principal: $loanAmount,
                    annualRoi: $interestRate,
                    tenure: $loanTenure,
                    tenureUnit: $tenureUnit,
                    frequency: $repaymentFrequency,
                    calculationMethod: $calculationMethod,
                    roundOffType: $scheme->emi_round_off_type,
                    roundUpValue: (float) ($scheme->emi_round_up_value ?? 0),
                     isOneTimeScheme: $this->isOneTimePayment($scheme)
                );

                $firstInstallment = $calculation['first_installment'];

                $loan->loan_amount = $loanAmount;
                $loan->loan_tenure = $loanTenure;
                $loan->roi = $interestRate;
                $loan->pf = $pfPercentage;
                $loan->gst = $gstPercentage;

                $loan->remarks = $validatedData['remarks']
                    ?? (
                        $calculation['is_one_time']
                        ? 'BL CAM approved with one-time repayment.'
                        : 'BL CAM approved using selected scheme.'
                    );

                $loan->save();


                \Modules\BLSaction\Models\BLSaction::create([
                    'lead_uuid' => $lead_uuid,

                    'sanction_number' =>
                    'SAR-' .
                        strtoupper(Str::random(8)) .
                        '-' .
                        now()->format('Y'),

                    'sanctioned_amount' => $loanAmount,
                    'interest_rate' => $interestRate,

                    'tenure_months' => $loanTenure,
                    // 'tenure_months' => $this->convertTenureToMonths(
                    //     $loanTenure,
                    //     $tenureUnit
                    // ),

                    'emi_amount' => $firstInstallment,

                    'processing_fee' => round(
                        $processingFee + $processingFeeGst,
                        2
                    ),


                    'sanction_date' => now()->toDateString(),

                    'sanction_expiry_date' => now()
                        ->addDays(30)
                        ->toDateString(),

                    'loan_type' => $lead->loan_type ?? 'BL',

                    'repayment_mode' => $calculation['is_one_time']
                        ? 'ONE TIME'
                        : strtoupper(
                            str_replace('_', ' ', $repaymentFrequency)
                        ),

                    'status' => 'generated',

                    'generated_by' => auth()->check()
                        ? auth()->id()
                        : null,
                ]);

                Log::info('BL CAM EMI calculated successfully.', [
                    'lead_uuid' => $lead_uuid,
                    'loan_amount' => $loanAmount,
                    'roi' => $interestRate,
                    'tenure' => $loanTenure,
                    'tenure_unit' => $tenureUnit,
                    'repayment_frequency' => $repaymentFrequency,
                    'calculation_method' => $calculationMethod,
                    'number_of_installments' =>
                    $calculation['number_of_installments'],
                    'first_installment' => $firstInstallment,
                    'total_interest' => $calculation['total_interest'],
                    'total_payable' => $calculation['total_payable'],
                    'processing_fee' => $processingFee,
                    'processing_fee_gst' => $processingFeeGst,
                ]);

                return $loan;
            });

            return (new BLCamResource($loan))
                ->additional([
                    'status' => 'success',
                    'request_id' => app('request_id') ?? null,
                    'timestamp' => now()->toDateTimeString(),
                    'message' => 'BL CAM decision created successfully.',
                ])
                ->response()
                ->setStatusCode(201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'request_id' => app('request_id') ?? null,
                'timestamp' => now()->toDateTimeString(),
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('BL CAM store failed.', [
                'lead_uuid' => $lead_uuid,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'request_id' => app('request_id') ?? null,
                'timestamp' => now()->toDateTimeString(),
                'message' => app()->environment('local')
                    ? $e->getMessage()
                    : 'Something went wrong while processing CAM.',
            ], 500);
        }
    }

    public function getByLead($leadUuid): JsonResponse
    {
        try {
            $decisions = BLCam::where('lead_uuid', $leadUuid)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status'  => 'success',
                'message' => 'BL CAM decisions fetched successfully.',
                'data'    => BLCamResource::collection($decisions),
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Something went wrong.',
            ], 500);
        }
    }

    public function showById(string $lead_uuid, int $id): JsonResponse
    {
        try {
            $BLCam = BLCam::where('id', $id)
                ->where('lead_uuid', $lead_uuid)
                ->first();

            if (! $BLCam) {
                return response()->json([
                    'status'     => 'error',
                    'request_id' => app('request_id') ?? null,
                    'timestamp'  => now()->toDateTimeString(),
                    'message'    => 'BL CAM record not found for the given ID.',
                ], 404);
            }

            return (new BLCamResource($BLCam))
                ->additional([
                    'status'  => 'success',
                    'message' => 'BL CAM record fetched successfully by ID.',
                ])
                ->response()
                ->setStatusCode(200);
        } catch (Exception $e) {
            Log::error('BL Show By ID Error: ' . $e->getMessage());

            return response()->json([
                'status'     => 'error',
                'request_id' => app('request_id') ?? null,
                'timestamp'  => now()->toDateTimeString(),
                'message'    => 'An error occurred while fetching the record.',
            ], 500);
        }
    }




    // private function calculateFirstInstallment(
    //     float $principal,
    //     float $annualRoi,
    //     int $tenure,
    //     string $tenureUnit,
    //     string $frequency,
    //     string $calculationMethod,
    //     ?string $roundOffType = 'none',
    //     float $roundUpValue = 0
    // ): array {
    //     if ($principal <= 0) {
    //         throw new Exception('Loan amount must be greater than zero.');
    //     }

    //     if ($tenure <= 0) {
    //         throw new Exception('Loan tenure must be greater than zero.');
    //     }

    //     $durationInYears = $this->convertTenureToYears(
    //         $tenure,
    //         $tenureUnit
    //     );


    //     if (in_array($frequency, ['one_time', 'bullet'], true)) {
    //         $totalInterest = round(
    //             $principal * ($annualRoi / 100) * $durationInYears,
    //             2
    //         );

    //         $totalPayable = round(
    //             $principal + $totalInterest,
    //             2
    //         );

    //         return [
    //             'first_installment' => $this->applyEmiRounding(
    //                 $totalPayable,
    //                 $roundOffType,
    //                 $roundUpValue
    //             ),
    //             'number_of_installments' => 1,
    //             'total_interest' => $totalInterest,
    //             'total_payable' => $totalPayable,
    //             'is_one_time' => true,
    //         ];
    //     }

    //     $paymentsPerYear = $this->paymentsPerYear($frequency);

    //     $numberOfInstallments = max(
    //         1,
    //         (int) round($durationInYears * $paymentsPerYear)
    //     );

    //     $annualRate = $annualRoi / 100;

    //     if ($calculationMethod === 'flat_rate') {
    //         $totalInterest = round(
    //             $principal * $annualRate * $durationInYears,
    //             2
    //         );

    //         $totalPayable = round(
    //             $principal + $totalInterest,
    //             2
    //         );

    //         $firstInstallment = $totalPayable / $numberOfInstallments;
    //     } elseif (
    //         in_array(
    //             $calculationMethod,
    //             ['reducing_balance', 'daily_reducing'],
    //             true
    //         )
    //     ) {

    //         $periodicRate = $annualRate / $paymentsPerYear;

    //         if ($annualRoi == 0) {
    //             $firstInstallment =
    //                 $principal / $numberOfInstallments;
    //         } else {
    //             $power = pow(
    //                 1 + $periodicRate,
    //                 $numberOfInstallments
    //             );

    //             $firstInstallment =
    //                 $principal *
    //                 $periodicRate *
    //                 $power /
    //                 ($power - 1);
    //         }

    //         $totalPayable = round(
    //             $firstInstallment * $numberOfInstallments,
    //             2
    //         );

    //         $totalInterest = round(
    //             $totalPayable - $principal,
    //             2
    //         );
    //     } else {
    //         throw new Exception(
    //             "Unsupported calculation method: {$calculationMethod}."
    //         );
    //     }

    //     $roundedInstallment = $this->applyEmiRounding(
    //         $firstInstallment,
    //         $roundOffType,
    //         $roundUpValue
    //     );

    //     return [
    //         'first_installment' => $roundedInstallment,
    //         'number_of_installments' => $numberOfInstallments,
    //         'total_interest' => $totalInterest,
    //         'total_payable' => $totalPayable,
    //         'is_one_time' => false,
    //     ];
    // }

    private function calculateFirstInstallment(
    float $principal,
    float $annualRoi,
    int $tenure,
    string $tenureUnit,
    string $frequency,
    string $calculationMethod,
    ?string $roundOffType = 'none',
    float $roundUpValue = 0,
    bool $isOneTimeScheme = false   
): array {
    if ($principal <= 0) {
        throw new Exception('Loan amount must be greater than zero.');
    }

    if ($tenure <= 0) {
        throw new Exception('Loan tenure must be greater than zero.');
    }

    
    // BULLET LOAN ONLY - Annual ROI Calculation
    if ($frequency === 'bullet') {

        $durationInYears = $this->convertTenureToYears(
            $tenure,
            $tenureUnit
        );

        $totalInterest = round(
            $principal * ($annualRoi / 100) * $durationInYears,
            2
        );

        $totalPayable = round(
            $principal + $totalInterest,
            2
        );

        return [
            'first_installment' => $this->applyEmiRounding(
                $totalPayable,
                $roundOffType,
                $roundUpValue
            ),
            'number_of_installments' => 1,
            'total_interest' => $totalInterest,
            'total_payable' => $totalPayable,
            'is_one_time' => true,
        ];
    }




  
    if ($isOneTimeScheme) {
        $totalInterest = round(
            $principal * ($annualRoi / 100) * $tenure,
            2
        );

        $totalPayable = round(
            $principal + $totalInterest,
            2
        );

        return [
            'first_installment' => $this->applyEmiRounding(
                $totalPayable,
                $roundOffType,
                $roundUpValue
            ),
            'number_of_installments' => 1,
            'total_interest' => $totalInterest,
            'total_payable' => $totalPayable,
            'is_one_time' => true,
        ];
    }



    $durationInYears = $this->convertTenureToYears(
        $tenure,
        $tenureUnit
    );

    if (in_array($frequency, ['one_time', 'bullet'], true)) {
        $totalInterest = round(
            $principal * ($annualRoi / 100) * $durationInYears,
            2
        );

        $totalPayable = round(
            $principal + $totalInterest,
            2
        );

        return [
            'first_installment' => $this->applyEmiRounding(
                $totalPayable,
                $roundOffType,
                $roundUpValue
            ),
            'number_of_installments' => 1,
            'total_interest' => $totalInterest,
            'total_payable' => $totalPayable,
            'is_one_time' => true,
        ];
    }

    $paymentsPerYear = $this->paymentsPerYear($frequency);

    $numberOfInstallments = max(
        1,
        (int) round($durationInYears * $paymentsPerYear)
    );

    $annualRate = $annualRoi / 100;

    if ($calculationMethod === 'flat_rate') {
        $totalInterest = round(
            $principal * $annualRate * $durationInYears,
            2
        );

        $totalPayable = round(
            $principal + $totalInterest,
            2
        );

        $firstInstallment = $totalPayable / $numberOfInstallments;
    } elseif (
        in_array(
            $calculationMethod,
            ['reducing_balance', 'daily_reducing'],
            true
        )
    ) {
        $periodicRate = $annualRate / $paymentsPerYear;

        if ($annualRoi == 0) {
            $firstInstallment =
                $principal / $numberOfInstallments;
        } else {
            $power = pow(
                1 + $periodicRate,
                $numberOfInstallments
            );

            $firstInstallment =
                $principal *
                $periodicRate *
                $power /
                ($power - 1);
        }

        $totalPayable = round(
            $firstInstallment * $numberOfInstallments,
            2
        );

        $totalInterest = round(
            $totalPayable - $principal,
            2
        );
    } else {
        throw new Exception(
            "Unsupported calculation method: {$calculationMethod}."
        );
    }

    $roundedInstallment = $this->applyEmiRounding(
        $firstInstallment,
        $roundOffType,
        $roundUpValue
    );

    return [
        'first_installment' => $roundedInstallment,
        'number_of_installments' => $numberOfInstallments,
        'total_interest' => $totalInterest,
        'total_payable' => $totalPayable,
        'is_one_time' => false,
    ];
}

    private function paymentsPerYear(string $frequency): int
    {
        return match ($frequency) {
            'daily'       => 365,
            'weekly'      => 52,
            'bi_weekly'   => 26,
            'monthly'     => 12,
            'quarterly'   => 4,
            'half_yearly' => 2,
            'yearly'      => 1,

            default => throw new Exception(
                "Unsupported repayment frequency: {$frequency}."
            ),
        };
    }

    // private function convertTenureToYears(
    //     int $tenure,
    //     string $tenureUnit
    // ): float {
    //     return match ($tenureUnit) {
    //         'days'   => $tenure / 365,
    //         'weeks'  => $tenure / 52,
    //         'months' => $tenure / 12,
    //         'years'  => (float) $tenure,

    //         default => throw new Exception(
    //             "Unsupported tenure unit: {$tenureUnit}."
    //         ),
    //     };
    // }

    private function convertTenureToYears(
    int $tenure,
    string $tenureUnit
): float {
    return match ($tenureUnit) {
        'days'   => $tenure / 365,
        'weeks'  => $tenure / 52,
        'months' => $tenure / 12,
        'years'  => (float) $tenure,

   

        default => throw new Exception(
            "Unsupported tenure unit: {$tenureUnit}."
        ),
    };
}

   

    private function applyEmiRounding(
        float $amount,
        ?string $roundOffType,
        float $roundUpValue
    ): float {
        $roundOffType = strtolower(
            trim((string) $roundOffType)
        );


        $roundingValue = $roundUpValue > 0
            ? $roundUpValue
            : 1;

        $roundedAmount = match ($roundOffType) {
            'nearest' => round($amount / $roundingValue)
                * $roundingValue,

            'floor' => floor($amount / $roundingValue)
                * $roundingValue,

            'ceil' => ceil($amount / $roundingValue)
                * $roundingValue,

            'none', '' => $amount,

            default => $amount,
        };

        return round($roundedAmount, 2);
    }

    private function normalizeFrequency(?string $frequency): string
    {
        $value = strtolower(trim((string) $frequency));

        return match ($value) {
            'daily',
            'daily loan' => 'daily',

            'weekly',
            'weekly loan' => 'weekly',

            'bi_weekly',
            'bi-weekly',
            'biweekly',
            'by-weekly',
            'by-weekly loan',
            'bi-weekly loan' => 'bi_weekly',

            'monthly',
            'monthly loan' => 'monthly',

            'quarterly',
            'quarterly loan' => 'quarterly',

            'half_yearly',
            'half-yearly',
            'half yearly',
            'halfyearly' => 'half_yearly',

            'yearly',
            'annual',
            'yearly loan' => 'yearly',

            'onetime',
            'one_time',
            'one-time',
            'one time',
            'one time payment' => 'one_time',

            'bullet',
            'bullet loan' => 'bullet',

            default => throw new Exception(
                "Unsupported repayment frequency: {$frequency}."
            ),
        };
    }

    private function normalizeCalculationMethod(
        ?string $calculationMethod
    ): string {
        $value = strtolower(
            trim((string) $calculationMethod)
        );

        return match ($value) {
            'reducing_balance',
            'reducing-balance',
            'reducing balance' => 'reducing_balance',

            'flat_rate',
            'flat-rate',
            'flat rate' => 'flat_rate',

            'daily_reducing',
            'daily-reducing',
            'daily reducing' => 'daily_reducing',

            default => throw new Exception(
                "Unsupported calculation method: {$calculationMethod}."
            ),
        };
    }

    //--------------------------------------------------------------------------------------

    // private function isOneTimePayment($scheme): bool
    // {
    //     $frequency  = strtolower(trim((string) $scheme->repayment_frequency));
    //     $tenureUnit = strtolower(trim((string) $scheme->tenure_unit));

    //     $oneTimeFrequencies = [
    //         'onetime',
    //         'one_time',
    //         'one-time',
    //         'one time',
    //         'one time payment',
    //         'bullet',
    //         'single',
    //     ];

    //     if (in_array($frequency, $oneTimeFrequencies, true)) {
    //         return true;
    //     }


    //     return $frequency === 'daily' && $tenureUnit === 'days';
    // }

    private function isOneTimePayment($scheme): bool
{
    $frequency  = strtolower(trim((string) $scheme->repayment_frequency));
    $tenureUnit = strtolower(trim((string) $scheme->tenure_unit));

    $oneTimeFrequencies = [
        'onetime',
        'one_time',
        'one-time',
        'one time',
        'one time payment',
        'bullet',
        'single',
    ];

    $oneTimeTenureUnits = [
        'onetime',
        'one_time',
        'one-time',
        'one time',
    ];


    if (in_array($tenureUnit, $oneTimeTenureUnits, true)) {
        return true;
    }

    if (in_array($frequency, $oneTimeFrequencies, true)) {
        return true;
    }

    return false;
}


    private function buildCamSummary(
        BLLeadCreation $lead,
        $applicants,
        $references
    ): array {
        $totalApplicants = $applicants->count();
        $withDetails     = $applicants->filter(fn($a) => ! is_null($a['personal_details']))->count();
        $withBankDetails = $applicants->filter(fn($a) => count($a['bank_details']) > 0)->count();
        $withBusiness    = $applicants->filter(fn($a) => ! is_null($a['business_details']))->count();

        $totalRefs       = $references->count();
        $refsWithDetails = $references->filter(fn($r) => ! is_null($r['details']))->count();
        $refStatus = match (true) {
            $totalRefs === 0                 => 'NO_REFERENCES',
            $refsWithDetails === $totalRefs  => 'ALL_FILLED',
            $refsWithDetails > 0             => 'PARTIALLY_FILLED',
            default                          => 'PENDING',
        };

        $overallStatus = match (true) {
            $totalApplicants === 0            => 'NO_APPLICANTS',
            $withDetails < $totalApplicants   => 'APPLICANT_DETAILS_PENDING',
            $refStatus === 'PENDING'          => 'REFERENCE_PENDING',
            default                           => 'IN_PROGRESS',
        };

        return [
            'overall_status' => $overallStatus,

            'applicant_summary' => [
                'total'             => $totalApplicants,
                'with_details'      => $withDetails,
                'with_bank_details' => $withBankDetails,
                'with_business'     => $withBusiness,
            ],

            'reference_summary' => [
                'status'  => $refStatus,
                'total'   => $totalRefs,
                'filled'  => $refsWithDetails,
                'pending' => $totalRefs - $refsWithDetails,
            ],
        ];
    }
}


// namespace Modules\BLCam\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Illuminate\Http\Request;
// use App\Traits\ApiResponse;
// use Exception;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Validation\ValidationException;
// use Modules\BLApplicant\Models\BLApplicant;
// use Modules\BLApplicantRole\Models\BLApplicantRole;
// use Modules\BLApplicantRoleDetails\Models\BLApplicantRoleDetails;
// use Modules\BLBankDetails\Models\BLBankDetail;
// use Modules\BLCam\Models\BLCam;
// use Modules\BLCam\Transformers\BLCamResource;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\BLOfficeDetails\Models\BLOfficeDetail;
// use Modules\BLReference\Models\BLReference;
// use Modules\BLReferenceDetails\Models\BLReferenceDetails;
// use Modules\UserManagement\Models\User;
// use Modules\BLIMDDetailsVerification\Models\BLIMDDetailsVerification;
// use Modules\BLTeleVerification\Models\BLTeleVerification;
// use Modules\BLReferenceVerification\Models\BLReferenceVerification;
// use Modules\BLSchemeSelect\Models\BLSchemeSelect;
// use Modules\BLAmortization\Services\AmortizationCalculatorService;
// use Throwable;
// use Illuminate\Support\Str;

// class BLCamController extends Controller
// {
//     use ApiResponse;

//     public function __construct(
//         protected AmortizationCalculatorService $calculatorService
//     ) {}

//     public function show(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();

//             if (! $lead) {
//                 return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//             }

//             $schemeSelection = BLSchemeSelect::query()
//                 ->with('productScheme')
//                 ->where('lead_uuid', $lead_uuid)
//                 ->first();

//             $selectedScheme = $schemeSelection?->productScheme;

//             $applicants = BLApplicant::where('lead_uuid', $lead_uuid)
//                 ->with(['detail', 'businessDetail'])
//                 ->get()
//                 ->map(function ($applicant) use ($lead_uuid) {

//                     $detail         = $applicant->detail;
//                     $businessDetail = $applicant->businessDetail;

//                     $officeDetail = BLOfficeDetail::where('lead_uuid', $lead_uuid)
//                         ->where('applicant_id', $applicant->id)
//                         ->first();

//                     $bankDetails = BLBankDetail::where('lead_uuid', $lead_uuid)
//                         ->where('applicant_id', $applicant->id)
//                         ->get()
//                         ->map(fn($b) => [
//                             'id'                 => $b->id,
//                             'applicant_sno'      => $b->applicant_sno,
//                             'applicant_type'     => $b->applicant_type,
//                             'bank_name'          => $b->bank_name,
//                             'account_type'       => $b->account_type,
//                             'statement_file'     => $b->statement_file,
//                             'statement_password' => $b->statement_password,
//                             'status'             => $b->status,
//                             'remarks'            => $b->remarks,
//                         ]);

//                     $roles = BLApplicantRole::where('lead_uuid', $lead_uuid)
//                         ->where('applicant_id', $applicant->id)
//                         ->get()
//                         ->map(function ($role) use ($lead_uuid) {

//                             $roleDetails = BLApplicantRoleDetails::where('lead_uuid', $lead_uuid)
//                                 ->where('applicant_id', $role->applicant_id)
//                                 ->where('applicant_role_id', $role->id)
//                                 ->get()
//                                 ->map(fn($rd) => [
//                                     'id'               => $rd->id,
//                                     'full_name'        => $rd->full_name,
//                                     'mother_name'      => $rd->mother_name,
//                                     'date_of_birth'    => $rd->date_of_birth,
//                                     'mobile_number'    => $rd->mobile_number,
//                                     'gender'           => $rd->gender,
//                                     'pan_number'       => $rd->pan_number,
//                                     'residence_address' => $rd->residence_address,
//                                     'pincode'          => $rd->pincode,
//                                     'district'         => $rd->district,
//                                     'city'             => $rd->city,
//                                     'state'            => $rd->state,
//                                     'ownership_status' => $rd->ownership_status,
//                                 ]);

//                             return [
//                                 'id'             => $role->id,
//                                 'sno'            => $role->sno,
//                                 'applicant_sno'  => $role->applicant_sno,
//                                 'applicant_type' => $role->applicant_type,
//                                 'type'           => $role->type,
//                                 'role'           => $role->role,
//                                 'role_details'   => $roleDetails,
//                             ];
//                         });

//                     $BLIMDDetailsVerification = BLIMDDetailsVerification::where('lead_uuid', $lead_uuid)
//                         ->where('applicant_id', $applicant->id)
//                         ->first();

//                     // $teleVerification = BLTeleVerification::where('lead_uuid', $lead_uuid)
//                     //     ->where('applicant_sno', (string) $applicant->applicant_sno)
//                     //     ->first();

//                     $teleVerification = BLTeleVerification::query()
//                         ->with('verifiedBy')
//                         ->where('lead_uuid', $lead_uuid)
//                         ->where('applicant_sno', $applicant->applicant_sno)
//                         ->first();

//                     return [
//                         'id'              => $applicant->id,
//                         'applicant_sno'   => $applicant->applicant_sno,
//                         'applicant_types' => $applicant->applicant_types,

//                         'personal_details' => $detail ? [
//                             'id'                        => $detail->id,
//                             'full_name'                 => $detail->full_name,
//                             'gender'                    => $detail->gender,
//                             'date_of_birth'             => $detail->date_of_birth,
//                             'age'                       => $detail->age,
//                             'nationality'               => $detail->nationality,
//                             'religion'                  => $detail->religion,
//                             'caste'                     => $detail->caste,
//                             'marital_status'            => $detail->marital_status,
//                             'qualification'             => $detail->qualification,
//                             'pan_card_number'           => $detail->pan_card_number,
//                             'secondary_kyc_type'        => $detail->secondary_kyc_type,
//                             'secondary_kyc_number'      => $detail->secondary_kyc_number,
//                             'aadhaar_card_number'       => $detail->aadhaar_card_number,
//                             'email_address'             => $detail->email_address,
//                             'alternate_contact_number'  => $detail->alternate_contact_number,
//                             'employment_type'           => $detail->employment_type,
//                             'entity_type'               => $detail->entity_type,
//                             'applying_as'               => $detail->applying_as,
//                             'is_income_considered'      => $detail->is_income_considered,
//                             'father_full_name'          => $detail->father_full_name,
//                             'mother_full_name'          => $detail->mother_full_name,
//                             'spouse_full_name'          => $detail->spouse_full_name,
//                             'premise_ownership'         => $detail->premise_ownership,
//                             'numbers_of_family_members' => $detail->numbers_of_family_members,
//                             'numbers_of_earning_members' => $detail->numbers_of_earning_members,
//                             'numbers_dependents'        => $detail->numbers_dependents,
//                             'current_address'           => $detail->current_address,
//                             'current_pincode'           => $detail->current_pincode,
//                             'current_district'          => $detail->current_district,
//                             'current_city'              => $detail->current_city,
//                             'current_state'             => $detail->current_state,
//                             'current_staying_in_years'  => $detail->current_staying_in_years,
//                             'permanent_address'         => $detail->permanent_address,
//                             'permanent_pincode'         => $detail->permanent_pincode,
//                             'permanent_district'        => $detail->permanent_district,
//                             'permanent_city'            => $detail->permanent_city,
//                             'permanent_state'           => $detail->permanent_state,
//                             'same_as_current_address'   => $detail->same_as_current_address,
//                         ] : null,

//                         'business_details' => $businessDetail ? [
//                             'id'                        => $businessDetail->id,
//                             'business_name'             => $businessDetail->business_name,
//                             'nature_of_business'        => $businessDetail->nature_of_business,
//                             'incorporation_date'        => $businessDetail->incorporation_date,
//                             'pan_number'                => $businessDetail->pan_number,
//                             'monthly_sales'             => $businessDetail->monthly_sales,
//                             'office_address'            => $businessDetail->office_address,
//                             'pincode'                   => $businessDetail->pincode,
//                             'district'                  => $businessDetail->district,
//                             'city'                      => $businessDetail->city,
//                             'state'                     => $businessDetail->state,
//                             'business_premise_ownership' => $businessDetail->business_premise_ownership,
//                         ] : null,

//                         'office_details' => $officeDetail ? [
//                             'id'               => $officeDetail->id,
//                             'address'          => $officeDetail->address,
//                             'landmark'         => $officeDetail->landmark,
//                             'pincode'          => $officeDetail->pincode,
//                             'district'         => $officeDetail->district,
//                             'city'             => $officeDetail->city,
//                             'state'            => $officeDetail->state,
//                             'working_in_months' => $officeDetail->working_in_months,
//                         ] : null,

//                         'bank_details' => $bankDetails,

//                         'roles' => $roles,

//                         'imd_verification' => $BLIMDDetailsVerification ? [
//                             'id'           => $BLIMDDetailsVerification->id,
//                             'lead_uuid'    => $BLIMDDetailsVerification->lead_uuid,
//                             'lead_id'      => $BLIMDDetailsVerification->lead_id,
//                             'applicant_id' => $BLIMDDetailsVerification->applicant_id,
//                             'status'       => $BLIMDDetailsVerification->status,
//                             'remarks'      => $BLIMDDetailsVerification->remarks,
//                             'consent_given' => $BLIMDDetailsVerification->consent_given,
//                             'consent_at'   => $BLIMDDetailsVerification->consent_at?->toISOString(),
//                             'verified_by'  => $BLIMDDetailsVerification->verified_by,
//                             'verified_at'  => $BLIMDDetailsVerification->verified_at?->toISOString(),
//                             'created_at'   => $BLIMDDetailsVerification->created_at?->toISOString(),
//                             'updated_at'   => $BLIMDDetailsVerification->updated_at?->toISOString(),
//                         ] : null,

//                         'tele_verification' => $teleVerification ? [
//                             'id'                    => $teleVerification->id,
//                             'lead_uuid'             => $teleVerification->lead_uuid,
//                             'lead_id'               => $teleVerification->lead_id,
//                             'applicant_sno'         => $teleVerification->applicant_sno,
//                             'applicant_type'        => $teleVerification->applicant_type,

//                             'tvr_flag'              => $teleVerification->tvr_flag,
//                             'tvr_status'            => $teleVerification->tvr_status,
//                             'tvr_risk_assessment'   => $teleVerification->tvr_risk_assessment,
//                             'tvr_completed_at'      => $teleVerification->tvr_completed_at?->toISOString(),

//                             'q1_name_correct'       => $teleVerification->q1_name_correct,
//                             'q1_correct_name'       => $teleVerification->q1_correct_name,
//                             'q2_mobile_verified'    => $teleVerification->q2_mobile_verified,
//                             'q3_address_correct'    => $teleVerification->q3_address_correct,
//                             'q3_updated_address'    => $teleVerification->q3_updated_address,

//                             'q4_self_employed'      => $teleVerification->q4_self_employed,
//                             'q4_business_type'      => $teleVerification->q4_business_type,
//                             'q5_business_vintage'   => $teleVerification->q5_business_vintage,
//                             'q5_vintage_years'      => $teleVerification->q5_vintage_years,
//                             'q6_fulltime_business'  => $teleVerification->q6_fulltime_business,

//                             'q7_salaried'           => $teleVerification->q7_salaried,
//                             'q8_employer_name'      => $teleVerification->q8_employer_name,
//                             'q9_employment_type'    => $teleVerification->q9_employment_type,
//                             'q10_total_experience'  => $teleVerification->q10_total_experience,
//                             'q11_current_tenure'    => $teleVerification->q11_current_tenure,
//                             'q12_salary_bank'       => $teleVerification->q12_salary_bank,

//                             'q13_income_threshold'  => $teleVerification->q13_income_threshold,
//                             'q13_monthly_income'    => $teleVerification->q13_monthly_income,
//                             'q14_income_banking'    => $teleVerification->q14_income_banking,
//                             'q15_additional_income' => $teleVerification->q15_additional_income,
//                             'q15_income_source'     => $teleVerification->q15_income_source,
//                             'q15_income_amount'     => $teleVerification->q15_income_amount,

//                             'q16_married'           => $teleVerification->q16_married,
//                             'q17_spouse_income'     => $teleVerification->q17_spouse_income,
//                             'q17_spouse_income_nature' => $teleVerification->q17_spouse_income_nature,
//                             'q18_dependents'        => $teleVerification->q18_dependents,
//                             'q18_dependent_count'   => $teleVerification->q18_dependent_count,
//                             'q19_expenses'          => $teleVerification->q19_expenses,
//                             'q19_expense_amount'    => $teleVerification->q19_expense_amount,

//                             'q20_existing_loans'    => $teleVerification->q20_existing_loans,
//                             'q20_loan_details'      => $teleVerification->q20_loan_details,
//                             'q20_emi_amount'        => $teleVerification->q20_emi_amount,
//                             'q21_loan_defaults'     => $teleVerification->q21_loan_defaults,

//                             'q27_emi_aware'         => $teleVerification->q27_emi_aware,
//                             'q28_other_applications' => $teleVerification->q28_other_applications,

//                             'q29_consent'           => $teleVerification->q29_consent,
//                             'q30_information_true'  => $teleVerification->q30_information_true,

//                             'q31_tvr_status'        => $teleVerification->q31_tvr_status,
//                             'q32_risk_assessment'   => $teleVerification->q32_risk_assessment,
//                             'q33_caller_remarks'    => $teleVerification->q33_caller_remarks,
//                             'q34_recommended_action' => $teleVerification->q34_recommended_action,

//                             'consent_given'         => $teleVerification->consent_given,
//                             'consent_at'            => $teleVerification->consent_at?->toISOString(),
//                             // 'verified_by'           => $teleVerification->verified_by,
//                             'verified_by' => $teleVerification->verifiedBy?->user_name,
//                             'verified_at'           => $teleVerification->verified_at?->toISOString(),
//                             'ip_address'            => $teleVerification->ip_address,
//                             'created_at'            => $teleVerification->created_at?->toISOString(),
//                             'updated_at'            => $teleVerification->updated_at?->toISOString(),
//                         ] : null,
//                     ];
//                 });

//             $references = BLReference::where('lead_uuid', $lead_uuid)
//                 ->get()
//                 ->map(function ($ref) use ($lead_uuid) {

//                     $refDetails = BLReferenceDetails::where('lead_uuid', $lead_uuid)
//                         ->where('reference_id', $ref->id)
//                         ->first();

//                     $refVerification = BLReferenceVerification::where('lead_uuid', $lead_uuid)
//                         ->where('reference_id', $ref->id)
//                         ->first();

//                     return [
//                         'id'              => $ref->id,
//                         'reference_sno'   => $ref->reference_sno,
//                         'reference_types' => $ref->reference_types,

//                         'details' => $refDetails ? [
//                             'id'           => $refDetails->id,
//                             'full_name'    => $refDetails->full_name,
//                             'mobile_no'    => $refDetails->mobile_no,
//                             'email'        => $refDetails->email,
//                             'gender'       => $refDetails->gender,
//                             'relationship' => $refDetails->relationship,
//                             'address'      => $refDetails->address,
//                             'pincode'      => $refDetails->pincode,
//                             'district'     => $refDetails->district,
//                             'city'         => $refDetails->city,
//                             'state'        => $refDetails->state,
//                             'status'       => $refDetails->status,
//                             'remarks'      => $refDetails->remarks,
//                         ] : null,

//                         'verification' => $refVerification ? [
//                             'id'                  => $refVerification->id,
//                             'reference_id'        => $refVerification->reference_id,
//                             'reference_detail_id' => $refVerification->reference_detail_id,
//                             'status'              => $refVerification->status,
//                             'remarks'             => $refVerification->remarks,
//                             'known_since'         => $refVerification->Known_since,
//                             'created_at'          => $refVerification->created_at?->toISOString(),
//                             'updated_at'          => $refVerification->updated_at?->toISOString(),
//                         ] : null,
//                     ];
//                 });

//             $assignableUsers = User::query()
//                 ->select('id', 'user_name')
//                 ->where('is_active', true)
//                 ->where('id', '!=', auth()->id())
//                 ->when($request->filled('search'), function ($query) use ($request) {
//                     $query->where('user_name', 'like', '%' . $request->input('search') . '%');
//                 })
//                 ->orderBy('user_name', 'asc')
//                 ->get();

//             $camSummary = $this->buildCamSummary($lead, $applicants, $references);

//             // Flag for the frontend: is this scheme a one-time (payday) repayment?
//             $isOneTimePayment = $selectedScheme
//                 ? $this->isOneTimePayment($selectedScheme)
//                 : false;

//             return $this->success(
//                 [
//                     'lead' => [
//                         'uuid'          => $lead->uuid,
//                         'lead_id'       => $lead->lead_id,
//                         'loan_type'     => $lead->loan_type,
//                         'customer_type' => $lead->customer_type,
//                         'sub_loan_type' => $lead->sub_loan_type,
//                         'source_by'     => $lead->source_by,
//                         'email'         => $lead->email,
//                         'mobile_number' => $lead->mobile_number,
//                         'loan_amount'   => $lead->loan_amount,
//                         'loan_tenur'    => $lead->loan_tenur,
//                         'created_at'    => $lead->created_at?->toISOString(),
//                     ],

//                     'selected_scheme' => $selectedScheme ? [
//                         'uuid'        => $selectedScheme->uuid,
//                         'scheme_name' => $selectedScheme->scheme_name,

//                         'interest_rate' => $selectedScheme->interest_rate !== null
//                             ? (float) $selectedScheme->interest_rate
//                             : null,

//                         'tenure_value' => $selectedScheme->tenure_value !== null
//                             ? (int) $selectedScheme->tenure_value
//                             : null,

//                         'tenure_unit'         => $selectedScheme->tenure_unit,
//                         'repayment_frequency' => $selectedScheme->repayment_frequency,
//                         'calculation_method'  => $selectedScheme->calculation_method,

//                         'is_one_time_payment' => $isOneTimePayment,

//                         'minimum_pf' => $selectedScheme->minimum_pf !== null
//                             ? (float) $selectedScheme->minimum_pf
//                             : null,

//                         'maximum_pf' => $selectedScheme->maximum_pf !== null
//                             ? (float) $selectedScheme->maximum_pf
//                             : null,

//                         'lpf_rate' => $selectedScheme->lpf_rate !== null
//                             ? (float) $selectedScheme->lpf_rate
//                             : null,
//                     ] : null,

//                     'cam_defaults' => $selectedScheme ? [
//                         'roi' => $selectedScheme->interest_rate !== null
//                             ? (float) $selectedScheme->interest_rate
//                             : null,

//                         'loan_tenure' => $selectedScheme->tenure_value !== null
//                             ? (int) $selectedScheme->tenure_value
//                             : null,

//                         'minimum_pf_percentage' => $selectedScheme->minimum_pf !== null
//                             ? (float) $selectedScheme->minimum_pf
//                             : null,

//                         'maximum_pf_percentage' => $selectedScheme->maximum_pf !== null
//                             ? (float) $selectedScheme->maximum_pf
//                             : null,

//                         'tenure_unit' => $selectedScheme->tenure_unit,

//                         'is_one_time_payment' => $isOneTimePayment,
//                     ] : null,

//                     'cam_summary'      => $camSummary,
//                     'applicants'       => $applicants,
//                     'references'       => $references,
//                     'assignable_users' => $assignableUsers,
//                 ],
//                 'BL CAM data fetched successfully.',
//                 200,
//                 'BL_CAM_FETCHED_SUCCESSFULLY'
//             );
//         } catch (Throwable $e) {
//             return $this->error(
//                 'Something went wrong while fetching BL CAM data.',
//                 app()->environment('local') ? $e->getMessage() : null,
//                 500,
//                 'SERVER_ERROR'
//             );
//         }
//     }




//     public function store(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $lead = BLLeadCreation::query()
//                 ->where('uuid', $lead_uuid)
//                 ->first();

//             if (! $lead) {
//                 return $this->error(
//                     'Lead not found.',
//                     null,
//                     404,
//                     'LEAD_NOT_FOUND'
//                 );
//             }


//             $validatedData = $request->validate([
//                 'status' => [
//                     'required',
//                     'string',
//                     'in:Approve,Reject',
//                 ],

//                 'loan_amount' => [
//                     'required_if:status,Approve',
//                     'nullable',
//                     'numeric',
//                     'min:1',
//                 ],

//                 'loan_tenure' => [
//                     'required_if:status,Approve',
//                     'nullable',
//                     'integer',
//                     'min:1',
//                     'max:600',
//                 ],

//                 'roi' => [
//                     'required_if:status,Approve',
//                     'nullable',
//                     'numeric',
//                     'min:0',
//                     'max:100',
//                 ],

//                 'pf_percentage' => [
//                     'required_if:status,Approve',
//                     'nullable',
//                     'numeric',
//                     'between:0,100',
//                 ],

//                 'gst' => [
//                     'required_if:status,Approve',
//                     'nullable',
//                     'numeric',
//                     'min:0',
//                     'max:100',
//                 ],

//                 'forward_to_user_id' => [
//                     'nullable',
//                     'exists:users,id',
//                 ],

//                 'forward_to' => [
//                     'nullable',
//                     'string',
//                     'max:255',
//                 ],

//                 'remarks' => [
//                     'required_if:status,Reject',
//                     'nullable',
//                     'string',
//                     'max:1000',
//                 ],
//             ]);

//             $isApprove = strtolower($validatedData['status']) === 'approve';

//             if ($isApprove) {
//                 $alreadyApproved = BLCam::query()
//                     ->where('lead_uuid', $lead_uuid)
//                     ->where('status', 'Approve')
//                     ->exists();

//                 if ($alreadyApproved) {
//                     return $this->error(
//                         'This lead has already been approved.',
//                         null,
//                         422,
//                         'ALREADY_APPROVED'
//                     );
//                 }
//             }

//             $scheme = null;

//             if ($isApprove) {
//                 $schemeSelection = BLSchemeSelect::query()
//                     ->with('productScheme')
//                     ->where('lead_uuid', $lead_uuid)
//                     ->first();

//                 if (! $schemeSelection) {
//                     return $this->error(
//                         'Please select a product scheme before approving CAM.',
//                         null,
//                         422,
//                         'PRODUCT_SCHEME_NOT_SELECTED'
//                     );
//                 }

//                 $scheme = $schemeSelection->productScheme;

//                 if (! $scheme) {
//                     return $this->error(
//                         'Selected product scheme not found.',
//                         null,
//                         404,
//                         'PRODUCT_SCHEME_NOT_FOUND'
//                     );
//                 }

//                 if (! $scheme->is_active) {
//                     return $this->error(
//                         'Selected product scheme is inactive.',
//                         null,
//                         422,
//                         'PRODUCT_SCHEME_INACTIVE'
//                     );
//                 }

//                 if (
//                     $scheme->valid_from &&
//                     $scheme->valid_from->isAfter(now()->startOfDay())
//                 ) {
//                     return $this->error(
//                         'Selected product scheme is not active yet.',
//                         [
//                             'valid_from' => $scheme->valid_from->format('Y-m-d'),
//                         ],
//                         422,
//                         'PRODUCT_SCHEME_NOT_STARTED'
//                     );
//                 }

//                 if (
//                     $scheme->valid_till &&
//                     $scheme->valid_till->isBefore(now()->startOfDay())
//                 ) {
//                     return $this->error(
//                         'Selected product scheme has expired.',
//                         [
//                             'valid_till' => $scheme->valid_till->format('Y-m-d'),
//                         ],
//                         422,
//                         'PRODUCT_SCHEME_EXPIRED'
//                     );
//                 }

//                 if ($scheme->interest_rate === null) {
//                     return $this->error(
//                         'ROI is not configured in the selected scheme.',
//                         [
//                             'roi' => [
//                                 'Selected product scheme does not have ROI.',
//                             ],
//                         ],
//                         422,
//                         'SCHEME_ROI_MISSING'
//                     );
//                 }


//                 $postedRoi = round((float) $validatedData['roi'], 2);
//                 $schemeRoi = round((float) $scheme->interest_rate, 2);

//                 if ($postedRoi !== $schemeRoi) {
//                     return $this->error(
//                         'Posted ROI does not match the selected scheme ROI.',
//                         [
//                             'roi' => [
//                                 "ROI must be {$schemeRoi}%.",
//                             ],
//                         ],
//                         422,
//                         'ROI_DOES_NOT_MATCH_SCHEME'
//                     );
//                 }

//                 $tenureType = $scheme->tenure_type ?: 'fixed';
//                 $postedTenure = (int) $validatedData['loan_tenure'];


//                 if ($tenureType === 'fixed') {
//                     if (
//                         $scheme->tenure_value === null ||
//                         (int) $scheme->tenure_value < 1
//                     ) {
//                         return $this->error(
//                             'Fixed tenure is not configured in the selected scheme.',
//                             [
//                                 'loan_tenure' => [
//                                     'Selected scheme does not have a valid tenure.',
//                                 ],
//                             ],
//                             422,
//                             'SCHEME_FIXED_TENURE_MISSING'
//                         );
//                     }

//                     if ($postedTenure !== (int) $scheme->tenure_value) {
//                         return $this->error(
//                             'Posted tenure does not match the selected scheme tenure.',
//                             [
//                                 'loan_tenure' => [
//                                     'Tenure must be ' .
//                                         $scheme->tenure_value .
//                                         ' ' .
//                                         $scheme->tenure_unit .
//                                         '.',
//                                 ],
//                             ],
//                             422,
//                             'TENURE_DOES_NOT_MATCH_SCHEME'
//                         );
//                     }
//                 }


//                 if ($tenureType === 'range') {
//                     if (
//                         $scheme->minimum_tenure === null ||
//                         $scheme->maximum_tenure === null
//                     ) {
//                         return $this->error(
//                             'Tenure range is not configured in the selected scheme.',
//                             null,
//                             422,
//                             'SCHEME_TENURE_RANGE_MISSING'
//                         );
//                     }

//                     if (
//                         $postedTenure < (int) $scheme->minimum_tenure ||
//                         $postedTenure > (int) $scheme->maximum_tenure
//                     ) {
//                         return $this->error(
//                             'Loan tenure is outside the selected scheme range.',
//                             [
//                                 'loan_tenure' => [
//                                     'Tenure must be between ' .
//                                         $scheme->minimum_tenure .
//                                         ' and ' .
//                                         $scheme->maximum_tenure .
//                                         ' ' .
//                                         $scheme->tenure_unit .
//                                         '.',
//                                 ],
//                             ],
//                             422,
//                             'LOAN_TENURE_OUTSIDE_SCHEME_RANGE'
//                         );
//                     }
//                 }

//                 if (! in_array($tenureType, ['fixed', 'range'], true)) {
//                     return $this->error(
//                         'Selected scheme has an invalid tenure type.',
//                         null,
//                         422,
//                         'INVALID_SCHEME_TENURE_TYPE'
//                     );
//                 }

//                 $loanAmount = (float) $validatedData['loan_amount'];
//                 $pfPercentage = (float) $validatedData['pf_percentage'];

//                 if (
//                     $scheme->minimum_loan_amount !== null &&
//                     (float) $scheme->minimum_loan_amount > 0 &&
//                     $loanAmount < (float) $scheme->minimum_loan_amount
//                 ) {
//                     return $this->error(
//                         'Sanction amount is below the scheme minimum amount.',
//                         [
//                             'loan_amount' => [
//                                 'Minimum allowed amount is ' .
//                                     (float) $scheme->minimum_loan_amount .
//                                     '.',
//                             ],
//                         ],
//                         422,
//                         'LOAN_AMOUNT_BELOW_SCHEME_LIMIT'
//                     );
//                 }

//                 if (
//                     $scheme->maximum_loan_amount !== null &&
//                     (float) $scheme->maximum_loan_amount > 0 &&
//                     $loanAmount > (float) $scheme->maximum_loan_amount
//                 ) {
//                     return $this->error(
//                         'Sanction amount exceeds the scheme maximum amount.',
//                         [
//                             'loan_amount' => [
//                                 'Maximum allowed amount is ' .
//                                     (float) $scheme->maximum_loan_amount .
//                                     '.',
//                             ],
//                         ],
//                         422,
//                         'LOAN_AMOUNT_ABOVE_SCHEME_LIMIT'
//                     );
//                 }

//                 if (
//                     $scheme->minimum_pf !== null &&
//                     $pfPercentage < (float) $scheme->minimum_pf
//                 ) {
//                     return $this->error(
//                         'PF percentage is below the scheme minimum PF.',
//                         [
//                             'pf_percentage' => [
//                                 'Minimum allowed PF is ' .
//                                     (float) $scheme->minimum_pf .
//                                     '%.',
//                             ],
//                         ],
//                         422,
//                         'PF_BELOW_SCHEME_LIMIT'
//                     );
//                 }

//                 if (
//                     $scheme->maximum_pf !== null &&
//                     $pfPercentage > (float) $scheme->maximum_pf
//                 ) {
//                     return $this->error(
//                         'PF percentage exceeds the scheme maximum PF.',
//                         [
//                             'pf_percentage' => [
//                                 'Maximum allowed PF is ' .
//                                     (float) $scheme->maximum_pf .
//                                     '%.',
//                             ],
//                         ],
//                         422,
//                         'PF_ABOVE_SCHEME_LIMIT'
//                     );
//                 }
//             }

//             $currentUserName = auth()->check()
//                 ? auth()->user()->user_name
//                 : 'System';

//             $loan = DB::transaction(function () use (
//                 $validatedData,
//                 $lead_uuid,
//                 $currentUserName,
//                 $lead,
//                 $scheme,
//                 $isApprove
//             ) {
//                 $forwardToName = null;

//                 if (! empty($validatedData['forward_to_user_id'])) {
//                     $forwardUser = User::query()
//                         ->find($validatedData['forward_to_user_id']);

//                     $forwardToName = $forwardUser?->user_name;
//                 } elseif (! empty($validatedData['forward_to'])) {
//                     $forwardToName = $validatedData['forward_to'];
//                 }

//                 $loan = new BLCam();

//                 $loan->lead_uuid = $lead_uuid;
//                 $loan->status = $validatedData['status'];
//                 $loan->forward_to = $forwardToName;
//                 $loan->forward_by = $currentUserName;

//                 if (! $isApprove) {
//                     $loan->loan_amount = null;
//                     $loan->loan_tenure = null;
//                     $loan->roi = null;
//                     $loan->pf = null;
//                     $loan->gst = null;
//                     $loan->remarks = $validatedData['remarks'];
//                     $loan->save();

//                     return $loan;
//                 }

//                 $loanAmount = (float) $validatedData['loan_amount'];

//                 $interestRate = (float) $scheme->interest_rate;


//                 $loanTenure = ($scheme->tenure_type ?: 'fixed') === 'fixed'
//                     ? (int) $scheme->tenure_value
//                     : (int) $validatedData['loan_tenure'];

//                 $tenureUnit = strtolower(
//                     trim((string) $scheme->tenure_unit)
//                 );

//                 $repaymentFrequency = $this->normalizeFrequency(
//                     $scheme->repayment_frequency
//                 );

//                 $calculationMethod = $this->normalizeCalculationMethod(
//                     $scheme->calculation_method
//                 );

//                 $pfPercentage = (float) $validatedData['pf_percentage'];
//                 $gstPercentage = (float) $validatedData['gst'];

//                 $processingFee = round(
//                     ($loanAmount * $pfPercentage) / 100,
//                     2
//                 );

//                 $processingFeeGst = round(
//                     ($processingFee * $gstPercentage) / 100,
//                     2
//                 );


//                 $calculation = $this->calculateFirstInstallment(
//                     principal: $loanAmount,
//                     annualRoi: $interestRate,
//                     tenure: $loanTenure,
//                     tenureUnit: $tenureUnit,
//                     frequency: $repaymentFrequency,
//                     calculationMethod: $calculationMethod,
//                     roundOffType: $scheme->emi_round_off_type,
//                     roundUpValue: (float) ($scheme->emi_round_up_value ?? 0)
//                 );

//                 $firstInstallment = $calculation['first_installment'];

//                 $loan->loan_amount = $loanAmount;
//                 $loan->loan_tenure = $loanTenure;
//                 $loan->roi = $interestRate;
//                 $loan->pf = $pfPercentage;
//                 $loan->gst = $gstPercentage;

//                 $loan->remarks = $validatedData['remarks']
//                     ?? (
//                         $calculation['is_one_time']
//                         ? 'BL CAM approved with one-time repayment.'
//                         : 'BL CAM approved using selected scheme.'
//                     );

//                 $loan->save();


//                 \Modules\BLSaction\Models\BLSaction::create([
//                     'lead_uuid' => $lead_uuid,

//                     'sanction_number' =>
//                     'SAR-' .
//                         strtoupper(Str::random(8)) .
//                         '-' .
//                         now()->format('Y'),

//                     'sanctioned_amount' => $loanAmount,
//                     'interest_rate' => $interestRate,

//                     'tenure_months' => $loanTenure,
//                     // 'tenure_months' => $this->convertTenureToMonths(
//                     //     $loanTenure,
//                     //     $tenureUnit
//                     // ),

//                     'emi_amount' => $firstInstallment,

//                     'processing_fee' => round(
//                         $processingFee + $processingFeeGst,
//                         2
//                     ),


//                     'sanction_date' => now()->toDateString(),

//                     'sanction_expiry_date' => now()
//                         ->addDays(30)
//                         ->toDateString(),

//                     'loan_type' => $lead->loan_type ?? 'BL',

//                     'repayment_mode' => $calculation['is_one_time']
//                         ? 'ONE TIME'
//                         : strtoupper(
//                             str_replace('_', ' ', $repaymentFrequency)
//                         ),

//                     'status' => 'generated',

//                     'generated_by' => auth()->check()
//                         ? auth()->id()
//                         : null,
//                 ]);

//                 Log::info('BL CAM EMI calculated successfully.', [
//                     'lead_uuid' => $lead_uuid,
//                     'loan_amount' => $loanAmount,
//                     'roi' => $interestRate,
//                     'tenure' => $loanTenure,
//                     'tenure_unit' => $tenureUnit,
//                     'repayment_frequency' => $repaymentFrequency,
//                     'calculation_method' => $calculationMethod,
//                     'number_of_installments' =>
//                     $calculation['number_of_installments'],
//                     'first_installment' => $firstInstallment,
//                     'total_interest' => $calculation['total_interest'],
//                     'total_payable' => $calculation['total_payable'],
//                     'processing_fee' => $processingFee,
//                     'processing_fee_gst' => $processingFeeGst,
//                 ]);

//                 return $loan;
//             });

//             return (new BLCamResource($loan))
//                 ->additional([
//                     'status' => 'success',
//                     'request_id' => app('request_id') ?? null,
//                     'timestamp' => now()->toDateTimeString(),
//                     'message' => 'BL CAM decision created successfully.',
//                 ])
//                 ->response()
//                 ->setStatusCode(201);
//         } catch (ValidationException $e) {
//             return response()->json([
//                 'status' => 'error',
//                 'request_id' => app('request_id') ?? null,
//                 'timestamp' => now()->toDateTimeString(),
//                 'message' => 'Validation failed.',
//                 'errors' => $e->errors(),
//             ], 422);
//         } catch (Throwable $e) {
//             Log::error('BL CAM store failed.', [
//                 'lead_uuid' => $lead_uuid,
//                 'message' => $e->getMessage(),
//                 'file' => $e->getFile(),
//                 'line' => $e->getLine(),
//             ]);

//             return response()->json([
//                 'status' => 'error',
//                 'request_id' => app('request_id') ?? null,
//                 'timestamp' => now()->toDateTimeString(),
//                 'message' => app()->environment('local')
//                     ? $e->getMessage()
//                     : 'Something went wrong while processing CAM.',
//             ], 500);
//         }
//     }

//     public function getByLead($leadUuid): JsonResponse
//     {
//         try {
//             $decisions = BLCam::where('lead_uuid', $leadUuid)
//                 ->orderBy('created_at', 'desc')
//                 ->get();

//             return response()->json([
//                 'status'  => 'success',
//                 'message' => 'BL CAM decisions fetched successfully.',
//                 'data'    => BLCamResource::collection($decisions),
//             ], 200);
//         } catch (Exception $e) {
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => 'Something went wrong.',
//             ], 500);
//         }
//     }

//     public function showById(string $lead_uuid, int $id): JsonResponse
//     {
//         try {
//             $BLCam = BLCam::where('id', $id)
//                 ->where('lead_uuid', $lead_uuid)
//                 ->first();

//             if (! $BLCam) {
//                 return response()->json([
//                     'status'     => 'error',
//                     'request_id' => app('request_id') ?? null,
//                     'timestamp'  => now()->toDateTimeString(),
//                     'message'    => 'BL CAM record not found for the given ID.',
//                 ], 404);
//             }

//             return (new BLCamResource($BLCam))
//                 ->additional([
//                     'status'  => 'success',
//                     'message' => 'BL CAM record fetched successfully by ID.',
//                 ])
//                 ->response()
//                 ->setStatusCode(200);
//         } catch (Exception $e) {
//             Log::error('BL Show By ID Error: ' . $e->getMessage());

//             return response()->json([
//                 'status'     => 'error',
//                 'request_id' => app('request_id') ?? null,
//                 'timestamp'  => now()->toDateTimeString(),
//                 'message'    => 'An error occurred while fetching the record.',
//             ], 500);
//         }
//     }




//     private function calculateFirstInstallment(
//         float $principal,
//         float $annualRoi,
//         int $tenure,
//         string $tenureUnit,
//         string $frequency,
//         string $calculationMethod,
//         ?string $roundOffType = 'none',
//         float $roundUpValue = 0
//     ): array {
//         if ($principal <= 0) {
//             throw new Exception('Loan amount must be greater than zero.');
//         }

//         if ($tenure <= 0) {
//             throw new Exception('Loan tenure must be greater than zero.');
//         }

//         $durationInYears = $this->convertTenureToYears(
//             $tenure,
//             $tenureUnit
//         );


//         if (in_array($frequency, ['one_time', 'bullet'], true)) {
//             $totalInterest = round(
//                 $principal * ($annualRoi / 100) * $durationInYears,
//                 2
//             );

//             $totalPayable = round(
//                 $principal + $totalInterest,
//                 2
//             );

//             return [
//                 'first_installment' => $this->applyEmiRounding(
//                     $totalPayable,
//                     $roundOffType,
//                     $roundUpValue
//                 ),
//                 'number_of_installments' => 1,
//                 'total_interest' => $totalInterest,
//                 'total_payable' => $totalPayable,
//                 'is_one_time' => true,
//             ];
//         }

//         $paymentsPerYear = $this->paymentsPerYear($frequency);

//         $numberOfInstallments = max(
//             1,
//             (int) round($durationInYears * $paymentsPerYear)
//         );

//         $annualRate = $annualRoi / 100;

//         if ($calculationMethod === 'flat_rate') {
//             $totalInterest = round(
//                 $principal * $annualRate * $durationInYears,
//                 2
//             );

//             $totalPayable = round(
//                 $principal + $totalInterest,
//                 2
//             );

//             $firstInstallment = $totalPayable / $numberOfInstallments;
//         } elseif (
//             in_array(
//                 $calculationMethod,
//                 ['reducing_balance', 'daily_reducing'],
//                 true
//             )
//         ) {

//             $periodicRate = $annualRate / $paymentsPerYear;

//             if ($annualRoi == 0) {
//                 $firstInstallment =
//                     $principal / $numberOfInstallments;
//             } else {
//                 $power = pow(
//                     1 + $periodicRate,
//                     $numberOfInstallments
//                 );

//                 $firstInstallment =
//                     $principal *
//                     $periodicRate *
//                     $power /
//                     ($power - 1);
//             }

//             $totalPayable = round(
//                 $firstInstallment * $numberOfInstallments,
//                 2
//             );

//             $totalInterest = round(
//                 $totalPayable - $principal,
//                 2
//             );
//         } else {
//             throw new Exception(
//                 "Unsupported calculation method: {$calculationMethod}."
//             );
//         }

//         $roundedInstallment = $this->applyEmiRounding(
//             $firstInstallment,
//             $roundOffType,
//             $roundUpValue
//         );

//         return [
//             'first_installment' => $roundedInstallment,
//             'number_of_installments' => $numberOfInstallments,
//             'total_interest' => $totalInterest,
//             'total_payable' => $totalPayable,
//             'is_one_time' => false,
//         ];
//     }

//     private function paymentsPerYear(string $frequency): int
//     {
//         return match ($frequency) {
//             'daily'       => 365,
//             'weekly'      => 52,
//             'bi_weekly'   => 26,
//             'monthly'     => 12,
//             'quarterly'   => 4,
//             'half_yearly' => 2,
//             'yearly'      => 1,

//             default => throw new Exception(
//                 "Unsupported repayment frequency: {$frequency}."
//             ),
//         };
//     }

//     private function convertTenureToYears(
//         int $tenure,
//         string $tenureUnit
//     ): float {
//         return match ($tenureUnit) {
//             'days'   => $tenure / 365,
//             'weeks'  => $tenure / 52,
//             'months' => $tenure / 12,
//             'years'  => (float) $tenure,

//             default => throw new Exception(
//                 "Unsupported tenure unit: {$tenureUnit}."
//             ),
//         };
//     }

//     // private function convertTenureToMonths(
//     //     int $tenure,
//     //     string $tenureUnit
//     // ): int {
//     //     return match ($tenureUnit) {
//     //         'days'   => max(1, (int) ceil($tenure / 30)),
//     //         'weeks'  => max(1, (int) ceil(($tenure * 7) / 30)),
//     //         'months' => $tenure,
//     //         'years'  => $tenure * 12,

//     //         default => $tenure,
//     //     };
//     // }

//     private function applyEmiRounding(
//         float $amount,
//         ?string $roundOffType,
//         float $roundUpValue
//     ): float {
//         $roundOffType = strtolower(
//             trim((string) $roundOffType)
//         );


//         $roundingValue = $roundUpValue > 0
//             ? $roundUpValue
//             : 1;

//         $roundedAmount = match ($roundOffType) {
//             'nearest' => round($amount / $roundingValue)
//                 * $roundingValue,

//             'floor' => floor($amount / $roundingValue)
//                 * $roundingValue,

//             'ceil' => ceil($amount / $roundingValue)
//                 * $roundingValue,

//             'none', '' => $amount,

//             default => $amount,
//         };

//         return round($roundedAmount, 2);
//     }

//     private function normalizeFrequency(?string $frequency): string
//     {
//         $value = strtolower(trim((string) $frequency));

//         return match ($value) {
//             'daily',
//             'daily loan' => 'daily',

//             'weekly',
//             'weekly loan' => 'weekly',

//             'bi_weekly',
//             'bi-weekly',
//             'biweekly',
//             'by-weekly',
//             'by-weekly loan',
//             'bi-weekly loan' => 'bi_weekly',

//             'monthly',
//             'monthly loan' => 'monthly',

//             'quarterly',
//             'quarterly loan' => 'quarterly',

//             'half_yearly',
//             'half-yearly',
//             'half yearly',
//             'halfyearly' => 'half_yearly',

//             'yearly',
//             'annual',
//             'yearly loan' => 'yearly',

//             'onetime',
//             'one_time',
//             'one-time',
//             'one time',
//             'one time payment' => 'one_time',

//             'bullet',
//             'bullet loan' => 'bullet',

//             default => throw new Exception(
//                 "Unsupported repayment frequency: {$frequency}."
//             ),
//         };
//     }

//     private function normalizeCalculationMethod(
//         ?string $calculationMethod
//     ): string {
//         $value = strtolower(
//             trim((string) $calculationMethod)
//         );

//         return match ($value) {
//             'reducing_balance',
//             'reducing-balance',
//             'reducing balance' => 'reducing_balance',

//             'flat_rate',
//             'flat-rate',
//             'flat rate' => 'flat_rate',

//             'daily_reducing',
//             'daily-reducing',
//             'daily reducing' => 'daily_reducing',

//             default => throw new Exception(
//                 "Unsupported calculation method: {$calculationMethod}."
//             ),
//         };
//     }

//     //--------------------------------------------------------------------------------------

//     private function isOneTimePayment($scheme): bool
//     {
//         $frequency  = strtolower(trim((string) $scheme->repayment_frequency));
//         $tenureUnit = strtolower(trim((string) $scheme->tenure_unit));

//         $oneTimeFrequencies = [
//             'onetime',
//             'one_time',
//             'one-time',
//             'one time',
//             'one time payment',
//             'bullet',
//             'single',
//         ];

//         if (in_array($frequency, $oneTimeFrequencies, true)) {
//             return true;
//         }


//         return $frequency === 'daily' && $tenureUnit === 'days';
//     }


//     private function buildCamSummary(
//         BLLeadCreation $lead,
//         $applicants,
//         $references
//     ): array {
//         $totalApplicants = $applicants->count();
//         $withDetails     = $applicants->filter(fn($a) => ! is_null($a['personal_details']))->count();
//         $withBankDetails = $applicants->filter(fn($a) => count($a['bank_details']) > 0)->count();
//         $withBusiness    = $applicants->filter(fn($a) => ! is_null($a['business_details']))->count();

//         $totalRefs       = $references->count();
//         $refsWithDetails = $references->filter(fn($r) => ! is_null($r['details']))->count();
//         $refStatus = match (true) {
//             $totalRefs === 0                 => 'NO_REFERENCES',
//             $refsWithDetails === $totalRefs  => 'ALL_FILLED',
//             $refsWithDetails > 0             => 'PARTIALLY_FILLED',
//             default                          => 'PENDING',
//         };

//         $overallStatus = match (true) {
//             $totalApplicants === 0            => 'NO_APPLICANTS',
//             $withDetails < $totalApplicants   => 'APPLICANT_DETAILS_PENDING',
//             $refStatus === 'PENDING'          => 'REFERENCE_PENDING',
//             default                           => 'IN_PROGRESS',
//         };

//         return [
//             'overall_status' => $overallStatus,

//             'applicant_summary' => [
//                 'total'             => $totalApplicants,
//                 'with_details'      => $withDetails,
//                 'with_bank_details' => $withBankDetails,
//                 'with_business'     => $withBusiness,
//             ],

//             'reference_summary' => [
//                 'status'  => $refStatus,
//                 'total'   => $totalRefs,
//                 'filled'  => $refsWithDetails,
//                 'pending' => $totalRefs - $refsWithDetails,
//             ],
//         ];
//     }
// }
