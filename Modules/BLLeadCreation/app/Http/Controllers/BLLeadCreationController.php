<?php

namespace Modules\BLLeadCreation\Http\Controllers;



use App\Http\Controllers\Controller;

use App\Traits\ApiResponse;
use RuntimeException;

use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Cache;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Mail;

use Illuminate\Support\Facades\Schema;

use Illuminate\Support\Str;

use Illuminate\Validation\ValidationException;

use Modules\BLApplicant\Models\BLApplicant;

use Modules\BLApplicantDetails\Models\BLApplicantDetails;

use Modules\BLJourney\Models\BLJourney;

use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLLeadCreation\Services\BLReloanService;

use Modules\BLLeadCreation\Transformers\BLLeadCreationCollection;

use Modules\BLLeadCreation\Transformers\BLLeadCreationResource;

use Modules\BLReference\Models\BLReference;

use Modules\BLReferenceDetails\Models\BLReferenceDetails;

use Illuminate\Http\Response;

use Illuminate\Validation\Rule;

use Modules\BLCam\Models\BLCam;

use Modules\Customers\Models\Customer;

use Throwable;



class BLLeadCreationController extends Controller

{

    use ApiResponse;



    private const LEAD_DETAILS_UPDATE_ROLES = [

        'SUPER_ADMINISTRATIVE',

        'ADMINISTRATIVE',

        'HEAD_OFFICE_CREDIT_CHECKER',

        'HEAD_OFFICE_CREDIT_OPERATIONS',

        'HEAD_OFFICE_RISK_CONTROL_UNIT',

    ];



    public function assignedBranches(): JsonResponse

    {

        $user = Auth::user();



        if (! $user) {

            return $this->apiError(

                'Unauthenticated.',

                null,

                401,

                'UNAUTHENTICATED'

            );

        }



        $branches = $user->branches()

            ->select(

                'organization_branches.id',

                'organization_branches.name',

                'organization_branches.branch_code'

            )

            ->where('organization_branches.is_active', true)

            ->get()

            ->map(fn($branch) => [

                'id'          => (int) $branch->id,

                'name'        => $branch->name,

                'branch_code' => $branch->branch_code,

            ])

            ->values();



        /*

     * Purane single branch_id ko fallback rakha hai.

     */

        if ($branches->isEmpty() && $user->branch_id) {

            $defaultBranch = DB::table('organization_branches')

                ->where('id', $user->branch_id)

                ->whereNull('deleted_at')

                ->where('is_active', true)

                ->first([

                    'id',

                    'name',

                    'branch_code',

                ]);



            if ($defaultBranch) {

                $branches->push([

                    'id'          => (int) $defaultBranch->id,

                    'name'        => $defaultBranch->name,

                    'branch_code' => $defaultBranch->branch_code,

                ]);

            }

        }



        return $this->apiSuccess(

            [

                'branches' => $branches,

            ],

            'Assigned branches fetched successfully.',

            200,

            'ASSIGNED_BRANCHES_FETCHED'

        );

    }





    /*\*

     * Step 1: validate customer + send OTP.

     *

     * New customer:

     * - Aadhaar is not requested here.

     * - mobile must not already belong to a customer.

     *

     * Existing customer / re-loan:

     * - mobile + Aadhaar are required and must match the same customer.

     * - the customer can have any number of previous loans.

     */

    public function sendOtp(Request $request)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $request->validate([
            'email' => ['required_without:mobile_number', 'email'],
            'mobile_number' => [
                'required_without:email',
                'nullable',
                'regex:/^[0-9]{10}$/',
            ],
            'loan_type' => ['required', 'in:BL'],
            'customer_type' => ['required', Rule::in(['New', 'Existing'])],
            'aadhaar_card_number' => [
                Rule::requiredIf(fn () => $request->customer_type === 'Existing'),
                'nullable',
                'regex:/^[0-9]{12}$/',
            ],
            'sub_loan_type' => ['required', 'string', 'max:100'],
            'source_by' => ['required', 'string', 'max:100'],
            'loan_amount' => ['required', 'numeric', 'gt:0'],
            'loan_tenur' => ['required', 'integer', 'gt:0'],
            'branch_id' => ['nullable', 'integer', 'exists:organization_branches,id'],
        ], [
            'mobile_number.regex' => 'Mobile number must be exactly 10 digits.',
            'aadhaar_card_number.required' => 'Aadhaar number is required for an existing customer.',
            'aadhaar_card_number.regex' => 'Aadhaar number must be exactly 12 digits.',
        ]);

        $mobile = (string) $request->mobile_number;
        $customerType = (string) $request->customer_type;

        $sourceParticipant = null;
        $previousLead = null;

        if ($customerType === 'New') {
            $customerMobileExists = Customer::query()
                ->where('mobile_number', $mobile)
                ->exists();

            $participantMobileExists = BLApplicantDetails::query()
                ->where('alternate_contact_number', $mobile)
                ->exists();

            if ($customerMobileExists || $participantMobileExists) {
                return $this->apiError(
                    'Customer already exists. Please select Existing customer.',
                    [
                        'mobile_number' => [
                            'This mobile number is already registered with an existing Applicant, Co-applicant or Guarantor.',
                        ],
                    ],
                    422,
                    'CUSTOMER_ALREADY_EXISTS'
                );
            }
        }

        if ($customerType === 'Existing') {
            $sourceParticipant = app(BLReloanService::class)
                ->findSourceParticipant(
                    $mobile,
                    (string) $request->aadhaar_card_number
                );

            if (! $sourceParticipant) {
                return $this->apiError(
                    'Existing customer verification failed.',
                    [
                        'customer' => [
                            'Mobile number and Aadhaar number do not match the same Applicant, Co-applicant or Guarantor.',
                        ],
                    ],
                    422,
                    'EXISTING_CUSTOMER_NOT_FOUND'
                );
            }

            $previousLead = BLLeadCreation::query()
                ->where('uuid', $sourceParticipant->lead_uuid)
                ->first();

            if (! $previousLead) {
                return $this->apiError(
                    'Source loan was not found.',
                    null,
                    422,
                    'PREVIOUS_LEAD_NOT_FOUND'
                );
            }
        }

        $userBranchIds = $user->branches()
            ->pluck('organization_branches.id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if (empty($userBranchIds) && $user->branch_id) {
            $userBranchIds = [(int) $user->branch_id];
        }

        if (count($userBranchIds) > 1) {
            if (
                empty($request->branch_id)
                || ! in_array((int) $request->branch_id, $userBranchIds, true)
            ) {
                return $this->apiError(
                    'You are assigned to multiple branches. Please select which branch this lead belongs to.',
                    ['branches' => $userBranchIds],
                    422,
                    'BRANCH_SELECTION_REQUIRED'
                );
            }

            $branchId = (int) $request->branch_id;
        } else {
            $branchId = $userBranchIds[0] ?? null;
        }

        $identifier = $request->email ?? $request->mobile_number;
        $type = $request->email ? 'email' : 'mobile';
        $otp = $this->getDefaultOtp();

        $leadData = [
            'loan_type' => 'BL',
            'customer_type' => $customerType,
            'sub_loan_type' => $request->sub_loan_type,
            'source_by' => $request->source_by,
            'email' => $request->email ?? null,
            'mobile_number' => $mobile,
            'loan_amount' => $request->loan_amount,
            'loan_tenur' => $request->loan_tenur,
            'applicant_sno' => '0',
            'applicant_types' => 'applicant',
            'created_by' => $user->id,
            'branch_id' => $branchId,

            // Primary applicant save event will link the correct customer.
            'customer_id' => null,
            'customer_match_status' => 'pending',

            'is_reloan' => $customerType === 'Existing',
            'previous_lead_uuid' => $previousLead?->uuid,

            // Cache-only re-loan snapshot identifiers.
            'source_participant_detail_id' => $sourceParticipant?->id,
            'source_lead_uuid' => $sourceParticipant?->lead_uuid,
            'verification_aadhaar' => $customerType === 'Existing'
                ? (string) $request->aadhaar_card_number
                : null,
        ];

        Cache::put('lead_data_' . $identifier, $leadData, 300);

        return $this->apiSuccess(
            [
                'identifier' => $identifier,
                'type' => $type,
                'otp' => $otp,
            ],
            "OTP sent successfully to {$type}.",
            200,
            'OTP_SENT_SUCCESSFULLY'
        );
    }



    /*\*

     * Step 2: verify OTP + create a brand-new lead.

     * Existing customers are linked to the same customer_id while a new

     * applicant/applicant-detail row is created for every new loan.

     */

    public function verifyOtpAndStore(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required_without:mobile_number', 'email'],
                'mobile_number' => ['required_without:email', 'string', 'size:10'],
                'otp' => ['required', 'string', 'size:6'],
            ]);

            $identifier = $request->email ?? $request->mobile_number;
            $defaultOtp = $this->getDefaultOtp();

            if ($request->otp !== $defaultOtp) {
                return $this->apiError(
                    'Invalid OTP.',
                    null,
                    400,
                    'INVALID_OR_EXPIRED_OTP'
                );
            }

            $leadData = Cache::get('lead_data_' . $identifier);

            if (! $leadData) {
                return $this->apiError(
                    'Lead data not found. Please resend OTP.',
                    null,
                    404,
                    'LEAD_DATA_NOT_FOUND'
                );
            }

            if (empty($leadData['email']) && empty($leadData['mobile_number'])) {
                Cache::forget('lead_data_' . $identifier);

                return $this->apiError(
                    'Incomplete lead data. Please resend OTP to start over.',
                    null,
                    400,
                    'INCOMPLETE_LEAD_DATA'
                );
            }

            $cachedEmail = $leadData['email'] ?? null;
            $cachedMobile = $leadData['mobile_number'] ?? null;

            if ($cachedEmail !== $identifier && $cachedMobile !== $identifier) {
                Cache::forget('lead_data_' . $identifier);

                return $this->apiError(
                    'Contact information mismatch. Please resend OTP.',
                    null,
                    400,
                    'CONTACT_MISMATCH'
                );
            }

            $sourceParticipant = null;

            if (($leadData['customer_type'] ?? null) === 'Existing') {
                $sourceParticipant = app(BLReloanService::class)
                    ->revalidateSourceParticipant(
                        (int) ($leadData['source_participant_detail_id'] ?? 0),
                        (string) ($leadData['source_lead_uuid'] ?? ''),
                        (string) ($leadData['mobile_number'] ?? ''),
                        (string) ($leadData['verification_aadhaar'] ?? '')
                    );

                if (! $sourceParticipant) {
                    Cache::forget('lead_data_' . $identifier);

                    return $this->apiError(
                        'Existing participant verification is no longer valid. Please start again.',
                        null,
                        422,
                        'EXISTING_CUSTOMER_REVALIDATION_FAILED'
                    );
                }
            }

            $nullableFields = [
                'created_by',
                'customer_id',
                'customer_match_status',
                'customer_type',
                'previous_lead_uuid',
                'sub_loan_type',
                'source_by',
                'loan_amount',
                'loan_tenur',
                'applicant_types',
                'source_participant_detail_id',
                'source_lead_uuid',
                'verification_aadhaar',
            ];

            foreach ($nullableFields as $field) {
                if (isset($leadData[$field]) && $leadData[$field] === '') {
                    $leadData[$field] = null;
                }
            }

            if (isset($leadData['loan_amount']) && $leadData['loan_amount'] !== null) {
                $leadData['loan_amount'] = (float) $leadData['loan_amount'];
            }

            if (isset($leadData['loan_tenur']) && $leadData['loan_tenur'] !== null) {
                $leadData['loan_tenur'] = (int) $leadData['loan_tenur'];
            }

            $leadData['lead_id'] = $this->generateLeadId();
            $leadData['loan_type'] = 'BL';
            $leadData['applicant_sno'] = '0';

            if (! isset($leadData['applicant_types']) || $leadData['applicant_types'] === null) {
                $leadData['applicant_types'] = 'applicant';
            }

            $validator = validator($leadData, [
                'lead_id' => ['required', 'unique:b_l_lead_creations,lead_id'],
                'created_by' => ['nullable', 'exists:users,id'],
                'branch_id' => ['nullable', 'integer', 'exists:organization_branches,id'],
                'customer_id' => ['nullable', 'exists:customers,id'],
                'customer_match_status' => ['required', 'string', 'max:50'],
                'loan_type' => ['required', 'in:BL'],
                'customer_type' => ['required', Rule::in(['New', 'Existing'])],
                'is_reloan' => ['required', 'boolean'],
                'previous_lead_uuid' => ['nullable', 'uuid', 'exists:b_l_lead_creations,uuid'],
                'source_participant_detail_id' => [
                    Rule::requiredIf(fn () => ($leadData['customer_type'] ?? null) === 'Existing'),
                    'nullable',
                    'integer',
                    'exists:b_l_applicant_details,id',
                ],
                'source_lead_uuid' => [
                    Rule::requiredIf(fn () => ($leadData['customer_type'] ?? null) === 'Existing'),
                    'nullable',
                    'uuid',
                    'exists:b_l_lead_creations,uuid',
                ],
                'verification_aadhaar' => [
                    Rule::requiredIf(fn () => ($leadData['customer_type'] ?? null) === 'Existing'),
                    'nullable',
                    'regex:/^[0-9]{12}$/',
                ],
                'sub_loan_type' => ['nullable', 'string', 'max:100'],
                'source_by' => ['nullable', 'string', 'max:100'],
                'email' => ['nullable', 'email'],
                'mobile_number' => [
                    'required_without:email',
                    'nullable',
                    'regex:/^[0-9]{10}$/',
                ],
                'loan_amount' => ['nullable', 'numeric', 'gt:0'],
                'loan_tenur' => ['nullable', 'integer', 'min:1'],
                'applicant_sno' => ['required', 'string'],
                'applicant_types' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                Cache::forget('lead_data_' . $identifier);

                return $this->apiError(
                    'Lead data validation failed.',
                    $validator->errors(),
                    422,
                    'LEAD_VALIDATION_FAILED'
                );
            }

            $validated = $validator->validate();

            $sourceParticipantDetailId = $validated['source_participant_detail_id'] ?? null;
            $sourceLeadUuid = $validated['source_lead_uuid'] ?? null;
            $verificationAadhaar = $validated['verification_aadhaar'] ?? null;

            unset(
                $validated['source_participant_detail_id'],
                $validated['source_lead_uuid'],
                $validated['verification_aadhaar']
            );

            return DB::transaction(function () use (
                $validated,
                $identifier,
                $sourceParticipantDetailId,
                $sourceLeadUuid,
                $verificationAadhaar
            ) {
                $validated['uuid'] = (string) Str::uuid();

                $lead = BLLeadCreation::create($validated);

                $createdApplicants = collect();
                $createdDetails = collect();
                $primaryApplicant = null;
                $primaryDetail = null;

                if ($lead->customer_type === 'Existing') {
                    $sourceParticipant = app(BLReloanService::class)
                        ->revalidateSourceParticipant(
                            (int) $sourceParticipantDetailId,
                            (string) $sourceLeadUuid,
                            (string) $lead->mobile_number,
                            (string) $verificationAadhaar
                        );

                    if (! $sourceParticipant) {
                        throw new \RuntimeException(
                            'Source participant could not be verified during re-loan creation.'
                        );
                    }

                    $cloneResult = app(BLReloanService::class)
                        ->cloneParticipantsForReloan(
                            $lead,
                            $sourceParticipant,
                            $validated['created_by'] ?? null
                        );

                    $createdApplicants = collect($cloneResult['applicants']);
                    $createdDetails = collect($cloneResult['details']);

                    $primaryApplicant = $createdApplicants->first(
                        fn ($applicant) => strtolower(trim((string) $applicant->applicant_types)) === 'applicant'
                    );

                    if ($primaryApplicant) {
                        $primaryDetail = $createdDetails->first(
                            fn ($detail) => (int) $detail->applicant_id === (int) $primaryApplicant->id
                        );
                    }

                    $lead->refresh();

                    if (! $lead->customer_id) {
                        throw new \RuntimeException(
                            'New primary applicant could not be linked to a customer.'
                        );
                    }
                } else {
                    $primaryApplicant = BLApplicant::query()->create([
                        'lead_uuid' => $lead->uuid,
                        'created_by' => $validated['created_by'] ?? null,
                        'lead_id' => $lead->lead_id,
                        'applicant_sno' => 0,
                        'applicant_types' => 'applicant',
                    ]);

                    $primaryDetail = BLApplicantDetails::query()->create([
                        'lead_uuid' => $lead->uuid,
                        'created_by' => $validated['created_by'] ?? null,
                        'lead_id' => $lead->lead_id,
                        'applicant_id' => $primaryApplicant->id,
                        'applicant_sno' => 0,
                        'applicant_type' => 'applicant',
                        'email_address' => $validated['email'] ?? null,
                        'alternate_contact_number' => $validated['mobile_number'] ?? null,
                        'is_co_applicant' => false,
                    ]);

                    $createdApplicants = collect([$primaryApplicant]);
                    $createdDetails = collect([$primaryDetail]);
                }

                // $referenceTypes = ['Reference', 'Reference'];

                // foreach ($referenceTypes as $index => $refType) {
                //     $sno = $index + 1;

                //     $reference = BLReference::create([
                //         'lead_uuid' => $lead->uuid,
                //         'created_by' => $validated['created_by'] ?? null,
                //         'lead_id' => $lead->lead_id,
                //         'reference_sno' => (string) $sno,
                //         'reference_types' => $refType,
                //     ]);

                //     BLReferenceDetails::create([
                //         'lead_uuid' => $lead->uuid,
                //         'created_by' => $validated['created_by'] ?? null,
                //         'lead_id' => $lead->lead_id,
                //         'reference_id' => $reference->id,
                //         'reference_sno' => $sno,
                //         'reference_type' => $refType,
                //         'full_name' => null,
                //         'mobile_no' => null,
                //         'email' => null,
                //         'gender' => null,
                //         'relationship' => null,
                //         'address' => null,
                //         'pincode' => null,
                //         'district' => null,
                //         'city' => null,
                //         'state' => null,
                //         'status' => 'Pending',
                //         'remarks' => null,
                //     ]);
                // }

                /*
|--------------------------------------------------------------------------
| Default References
|--------------------------------------------------------------------------
|
| Re-loan me BLReloanService previous references clone kar chuki hogi.
| Agar cloned references available nahi hain tabhi 2 blank references banao.
|
*/
$existingReferenceCount = BLReference::query()
    ->where('lead_uuid', $lead->uuid)
    ->count();

if ($existingReferenceCount === 0) {
    $referenceTypes = [
        'Reference',
        'Reference',
    ];

    foreach ($referenceTypes as $index => $refType) {
        $sno = $index + 1;

        $reference = BLReference::query()->create([
            'lead_uuid' => $lead->uuid,
            'created_by' => $validated['created_by'] ?? null,
            'lead_id' => $lead->lead_id,
            'reference_sno' => (string) $sno,
            'reference_types' => $refType,
        ]);

        BLReferenceDetails::query()->create([
            'lead_uuid' => $lead->uuid,
            'created_by' => $validated['created_by'] ?? null,
            'lead_id' => $lead->lead_id,
            'reference_id' => $reference->id,
            'reference_sno' => $sno,
            'reference_type' => $refType,
            'full_name' => null,
            'mobile_no' => null,
            'email' => null,
            'gender' => null,
            'relationship' => null,
            'address' => null,
            'pincode' => null,
            'district' => null,
            'city' => null,
            'state' => null,
            'status' => 'Pending',
            'remarks' => null,
        ]);
    }
}

                Cache::forget('lead_data_' . $identifier);

                return $this->apiSuccess(
                    [
                        'lead' => new BLLeadCreationResource($lead->fresh()->load('creator')),
                        'applicant' => $primaryApplicant,
                        'applicant_detail' => $primaryDetail?->fresh(),
                        'applicants' => $createdApplicants->map(fn ($item) => $item->fresh())->values(),
                        'applicant_details' => $createdDetails->map(fn ($item) => $item->fresh())->values(),
                        'is_reloan' => (bool) $lead->is_reloan,
                        'previous_lead_uuid' => $lead->previous_lead_uuid,
                        'references' => BLReference::where('lead_uuid', $lead->uuid)->get(),
                        'reference_details' => BLReferenceDetails::where('lead_uuid', $lead->uuid)->get(),
                    ],
                    $lead->is_reloan
                        ? 'Re-loan lead and all participant snapshots created successfully.'
                        : 'Lead, applicant and references created successfully.',
                    201,
                    'BL_LEAD_CREATED_SUCCESSFULLY'
                );
            });
        } catch (ValidationException $e) {
            return $this->apiError(
                'Validation failed.',
                $e->errors(),
                422,
                'VALIDATION_ERROR'
            );
        } catch (Throwable $e) {
            report($e);

            return $this->apiError(
                'Something went wrong while creating BL lead.',
                app()->environment('local') ? $e->getMessage() : null,
                500,
                'SERVER_ERROR'
            );
        }
    }



    public function updateEditableDetails(

        Request $request,

        string $lead_uuid

    ): JsonResponse {

        try {

            $user = $request->user();



            if (! $user) {

                return $this->apiError(

                    'Unauthenticated.',

                    null,

                    401,

                    'UNAUTHENTICATED'

                );

            }



            if (! $this->canUpdateLeadDetails($user)) {

                return $this->apiError(

                    'You are not authorized to update lead details.',

                    null,

                    403,

                    'LEAD_UPDATE_FORBIDDEN'

                );

            }





            if ($request->has('loan_amount')) {

                $isCamApproved = BLCam::query()

                    ->where('lead_uuid', $lead_uuid)

                    ->whereRaw(

                        'LOWER(TRIM(status)) IN (?, ?)',

                        ['approve', 'approved']

                    )

                    ->exists();



                if ($isCamApproved) {

                    return $this->apiError(

                        'CAM is already approved. Loan amount cannot be updated.',

                        null,

                        422,

                        'LOAN_AMOUNT_UPDATE_NOT_ALLOWED'

                    );

                }

            }



            if (

                ! $request->has('loan_amount') &&

                ! $request->has('branch_id')

            ) {

                return $this->apiError(

                    'Loan amount or branch is required.',

                    null,

                    422,

                    'NO_UPDATE_DATA'

                );

            }



            $validated = $request->validate([

                'loan_amount' => [

                    'sometimes',

                    'required',

                    'numeric',

                    'gt:0',

                    'max:999999999999.99',

                ],



                'branch_id' => [

                    'sometimes',

                    'required',

                    'integer',

                    Rule::exists('organization_branches', 'id')

                        ->where(function ($query) {

                            $query

                                ->where('is_active', true)

                                ->whereNull('deleted_at');

                        }),

                ],

            ]);



            /*

         * Branch update ho rahi hai to selected branch logged-in

         * user ki assigned branches mein honi chahiye.

         */

            if (array_key_exists('branch_id', $validated)) {

                $assignedBranchIds = $user->branches()

                    ->pluck('organization_branches.id')

                    ->map(fn($branchId) => (int) $branchId)

                    ->all();



                /*

             * Purane users ke single branch_id ka fallback.

             */

                if (

                    empty($assignedBranchIds) &&

                    ! empty($user->branch_id)

                ) {

                    $assignedBranchIds = [

                        (int) $user->branch_id,

                    ];

                }



                if (

                    ! in_array(

                        (int) $validated['branch_id'],

                        $assignedBranchIds,

                        true

                    )

                ) {

                    return $this->apiError(

                        'Selected branch is not assigned to you.',

                        [

                            'branch_id' => [

                                'Please select one of your assigned branches.',

                            ],

                        ],

                        422,

                        'BRANCH_NOT_ASSIGNED'

                    );

                }

            }



            $updatedLead = DB::transaction(

                function () use ($lead_uuid, $validated) {

                    $lead = BLLeadCreation::query()

                        ->where('uuid', $lead_uuid)

                        ->lockForUpdate()

                        ->first();



                    if (! $lead) {

                        return null;

                    }



                    $updateData = [];



                    if (

                        array_key_exists(

                            'loan_amount',

                            $validated

                        )

                    ) {

                        $updateData['loan_amount'] =

                            $validated['loan_amount'];

                    }



                    if (

                        array_key_exists(

                            'branch_id',

                            $validated

                        )

                    ) {

                        $updateData['branch_id'] =

                            $validated['branch_id'];

                    }



                    $lead->update($updateData);



                    return $lead->fresh(['branch']);

                }

            );



            if (! $updatedLead) {

                return $this->apiError(

                    'Lead not found.',

                    null,

                    404,

                    'LEAD_NOT_FOUND'

                );

            }



            return $this->apiSuccess(

                [

                    'lead' => [

                        'uuid'        => $updatedLead->uuid,

                        'lead_id'     => $updatedLead->lead_id,

                        'loan_amount' => $updatedLead->loan_amount,

                        'branch_id'   => $updatedLead->branch_id,



                        'branch' => $updatedLead->branch

                            ? [

                                'id' => (int) $updatedLead->branch->id,

                                'name' => $updatedLead->branch->name,

                                'branch_code' =>

                                $updatedLead->branch->branch_code,

                            ]

                            : null,

                    ],



                    'updated_by' => [

                        'id' => (int) $user->id,

                    ],

                ],

                'Loan amount updated successfully.',

                200,

                'LEAD_DETAILS_UPDATED'

            );

        } catch (ValidationException $e) {

            return $this->apiError(

                'Validation failed.',

                $e->errors(),

                422,

                'VALIDATION_ERROR'

            );

        } catch (Throwable $e) {

            return $this->apiError(

                'Something went wrong while updating lead details.',

                null,

                500,

                'SERVER_ERROR'

            );

        }

    }





    public function resendOtp(Request $request)

    {

        $request->validate([

            'email'         => ['required_without:mobile_number', 'email'],

            'mobile_number' => ['required_without:email', 'string', 'size:10'],

        ]);



        $identifier = $request->email ?? $request->mobile_number;

        $type       = $request->has('email') ? 'email' : 'mobile';



        $leadData = Cache::get('lead_data_' . $identifier);



        if (! $leadData) {

            return response()->json([

                'success'     => false,

                'status_code' => Response::HTTP_NOT_FOUND,

                'request_id'  => app('request_id'),

                'timestamp'   => now()->format('Y-m-d H:i:s'),

                'message'     => 'No pending lead found. Please start over.',

            ], 404);

        }





        $otp = $this->getDefaultOtp();







        return response()->json([

            'success'     => true,

            'status_code' => Response::HTTP_OK,

            'request_id'  => app('request_id'),

            'timestamp'   => now()->format('Y-m-d H:i:s'),

            'message'     => "OTP for {$type}",

            'otp'         => $otp,

        ]);

    }





    public function index(Request $request)

    {

        $query = BLLeadCreation::with('creator');



        $query->when($request->lead_id, fn($q) => $q->where('lead_id', $request->lead_id));

        $query->when($request->mobile_number, fn($q) => $q->where('mobile_number', $request->mobile_number));



        $data = $query

            ->orderByRaw('CAST(SUBSTRING_INDEX(lead_id, "-", -1) AS UNSIGNED) ASC')

            ->paginate(10);



        return new BLLeadCreationCollection($data);

    }





    public function show(string $lead_uuid)

    {

        $data = BLLeadCreation::with('creator')

            ->where('uuid', $lead_uuid)

            ->first();



        if (! $data) {

            return response()->json([

                'success'     => false,

                'status_code' => Response::HTTP_NOT_FOUND,

                'request_id'  => app('request_id'),

                'timestamp'   => now()->format('Y-m-d H:i:s'),

                'message'     => 'Lead not found.',

            ], 404);

        }



        return response()->json([

            'success'     => true,

            'status_code' => Response::HTTP_OK,

            'request_id'  => app('request_id'),

            'timestamp'   => now()->format('Y-m-d H:i:s'),

            'message'     => 'Lead retrieved successfully.',

            'data'        => new BLLeadCreationResource($data),

        ]);

    }





    private function canUpdateLeadDetails($user): bool

    {

        return $user->roles()

            ->whereIn(

                'roles.code',

                self::LEAD_DETAILS_UPDATE_ROLES

            )

            ->where('roles.status', true)

            ->whereNull('roles.deleted_at')

            ->exists();

    }



    private function getDefaultOtp(): string

    {

        return '478393';

    }





    private function createFirstBLJourney(

        BLLeadCreation $lead,

        array $validated,

        Request $request

    ): BLJourney {

        BLJourney::where('lead_uuid', $lead->uuid)

            ->where('is_latest', true)

            ->update(['is_latest' => false]);



        $workflowStage  = null;

        $workflowStatus = null;



        if (Schema::hasTable('bl_workflow_stages')) {

            $workflowStage = DB::table('bl_workflow_stages')

                ->where('stage_code', 'LEAD_CREATED')

                ->first();

        }



        if ($workflowStage && Schema::hasTable('bl_workflow_statuses')) {

            $workflowStatus = DB::table('bl_workflow_statuses')

                ->where('stage_id', $workflowStage->id)

                ->whereIn('status_code', ['DRAFT', 'SUBMITTED'])

                ->orderByRaw("FIELD(status_code, 'DRAFT', 'SUBMITTED')")

                ->first();

        }



        $authUser = $request->user();



        $payload = [

            'lead_uuid'          => $lead->uuid,

            'lead_id'            => $lead->lead_id,

            'workflow_stage_id'  => $workflowStage->id ?? null,

            'workflow_status_id' => $workflowStatus->id ?? null,

            'previous_stage_id'  => null,

            'action_performed'   => 'LEAD_CREATED',

            'performed_by_role'  => $authUser->user_role ?? $authUser->user_designation ?? $authUser->user_type ?? null,

            'transition_type'    => 'INITIATED',

            'stage'              => $workflowStage->stage_name ?? 'Lead Created',

            'status'             => $workflowStatus->status_name ?? 'Journey Started',

            'team'               => $workflowStage->team_name ?? 'Sales',

            'action_type'        => 'LEAD_CREATED',

            'remarks'            => 'Loan journey started after OTP verification.',

            'user_id'            => $authUser->id ?? $validated['created_by'] ?? null,

            'user_name'          => $authUser->user_name ?? $authUser->name ?? null,

            'is_latest'          => true,

            'ip_address'         => $request->ip(),

            'device_info'        => $request->header('User-Agent'),

            'created_by'         => $validated['created_by'] ?? null,

            'is_completed'       => false,

        ];



        return BLJourney::create(

            $this->onlyExistingColumns('bl_journeys', $payload)

        );

    }



    private function onlyExistingColumns(string $table, array $payload): array

    {

        return collect($payload)

            ->filter(fn($value, $key) => Schema::hasColumn($table, $key))

            ->toArray();

    }



    private function apiSuccess(

        mixed $data = null,

        string $message = 'Success',

        int $statusCode = 200,

        string $code = 'SUCCESS'

    ): JsonResponse {

        return response()->json([

            'success'     => true,

            'status_code' => $statusCode,

            'request_id'  => app('request_id'),

            'timestamp'   => now()->format('Y-m-d H:i:s'),

            'code'        => $code,

            'message'     => $message,

            'data'        => $data,

        ], $statusCode);

    }



    private function apiError(

        string $message = 'Something went wrong.',

        mixed $data = null,

        int $statusCode = 400,

        string $code = 'ERROR'

    ): JsonResponse {

        return response()->json([

            'success'     => false,

            'status_code' => $statusCode,

            'code'        => $code,

            'request_id'  => app('request_id'),

            'timestamp'   => now()->format('Y-m-d H:i:s'),

            'message'     => $message,

            'data'        => $data,

        ], $statusCode);

    }







    private function generateLeadId(): string

    {

        $today  = now()->format('Ymd');

        $prefix = 'BL-' . $today . '-';



        $last = BLLeadCreation::where('lead_id', 'like', $prefix . '%')

            ->orderByRaw('CAST(SUBSTRING_INDEX(lead_id, "-", -1) AS UNSIGNED) DESC')

            ->value('lead_id');



        $nextNumber = $last

            ? (int) Str::afterLast($last, '-') + 1

            : 1001;



        return $prefix . $nextNumber;

    }

}


// namespace Modules\BLLeadCreation\Http\Controllers;

// use App\Http\Controllers\Controller;
// use App\Traits\ApiResponse;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Facades\Schema;
// use Illuminate\Support\Str;
// use Illuminate\Validation\ValidationException;
// use Modules\BLApplicant\Models\BLApplicant;
// use Modules\BLApplicantDetails\Models\BLApplicantDetails;
// use Modules\BLJourney\Models\BLJourney;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\BLLeadCreation\Transformers\BLLeadCreationCollection;
// use Modules\BLLeadCreation\Transformers\BLLeadCreationResource;
// use Modules\BLReference\Models\BLReference;
// use Modules\BLReferenceDetails\Models\BLReferenceDetails;
// use Illuminate\Http\Response;
// use Throwable;

// class BLLeadCreationController extends Controller
// {
//     use ApiResponse;

//     /**
//      * Step 1: Send OTP
//      */
//     public function sendOtp(Request $request)
//     {
//         $user = Auth::user();

//         if (! $user) {
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Unauthenticated',
//             ], 401);
//         }

//         $request->validate([
//             'email' => ['required_without:mobile_number', 'email'],
//             'mobile_number' => ['required_without:email', 'string', 'size:10'],
//             'loan_type' => ['required', 'in:BL'],
//             'customer_type' => ['required', 'string', 'max:100'],
//             'sub_loan_type' => ['required', 'string', 'max:100'],
//             'source_by' => ['required', 'string', 'max:100'],
//             'loan_amount' => ['required', 'numeric'],
//             'loan_tenur' => ['required', 'integer'],
//         ]);

//         $identifier = $request->email ?? $request->mobile_number;
//         $type = $request->email ? 'email' : 'mobile';

//         $otp = random_int(100000, 999999);

//         Cache::put('otp_'.$identifier, $otp, 300);

//         $leadData = $request->except(['email', 'mobile_number']);
//         $leadData['email'] = $request->email ?? null;
//         $leadData['mobile_number'] = $request->mobile_number ?? null;
//         $leadData['applicant_sno'] = '0';
//         $leadData['applicant_types'] = 'applicant';
//         $leadData['created_by'] = $user->id;

//         Cache::put('lead_data_'.$identifier, $leadData, 300);

//         if ($type == 'email') {
//             Mail::send(
//                 'blleadcreation::emails.BLOtp',
//                 ['otp' => $otp],
//                 function ($message) use ($identifier) {
//                     $message->to($identifier)
//                         ->subject('Your OTP for Lead Verification');
//                 }
//             );
//         }

//         return $this->apiSuccess(
//             [
//                 'identifier' => $identifier,
//                 'type' => $type,
//                 'otp' => app()->environment('local') ? $otp : null,
//             ],
//             "OTP sent successfully to {$type}.",
//             200,
//             'OTP_SENT_SUCCESSFULLY'
//         );
//     }

//     /**
//      * Step 2: Verify OTP + Create Lead + Applicant + ApplicantDetail  + References
//      */
//     public function verifyOtpAndStore(Request $request): JsonResponse
//     {
//         try {
//             $request->validate([
//                 'email' => ['required_without:mobile_number', 'email'],
//                 'mobile_number' => ['required_without:email', 'string', 'size:10'],
//                 'otp' => ['required', 'string', 'size:6'],
//             ]);

//             $identifier = $request->email ?? $request->mobile_number;

//             /*
//             |------------------------------------------------------------------
//             | 1. Verify OTP
//             |------------------------------------------------------------------
//             */
//             $cachedOtp = Cache::get('otp_'.$identifier);

//             if (! $cachedOtp || $cachedOtp != $request->otp) {
//                 return $this->apiError(
//                     'Invalid or expired OTP.',
//                     null,
//                     400,
//                     'INVALID_OR_EXPIRED_OTP'
//                 );
//             }

//             /*
//             |------------------------------------------------------------------
//             | 2. Get Cached Lead Data
//             |------------------------------------------------------------------
//             */
//             $leadData = Cache::get('lead_data_'.$identifier);

//             if (! $leadData) {
//                 return $this->apiError(
//                     'Lead data not found. Please resend OTP.',
//                     null,
//                     404,
//                     'LEAD_DATA_NOT_FOUND'
//                 );
//             }

//             /*
//             |------------------------------------------------------------------
//             | 3. Validate Cached Contact
//             |------------------------------------------------------------------
//             */
//             if (empty($leadData['email']) && empty($leadData['mobile_number'])) {
//                 Cache::forget('otp_'.$identifier);
//                 Cache::forget('lead_data_'.$identifier);

//                 return $this->apiError(
//                     'Incomplete lead data. Please resend OTP to start over.',
//                     null,
//                     400,
//                     'INCOMPLETE_LEAD_DATA'
//                 );
//             }

//             $cachedEmail = $leadData['email'] ?? null;
//             $cachedMobile = $leadData['mobile_number'] ?? null;

//             if ($cachedEmail !== $identifier && $cachedMobile !== $identifier) {
//                 Cache::forget('otp_'.$identifier);
//                 Cache::forget('lead_data_'.$identifier);

//                 return $this->apiError(
//                     'Contact information mismatch. Please resend OTP.',
//                     null,
//                     400,
//                     'CONTACT_MISMATCH'
//                 );
//             }

//             /*
//             |------------------------------------------------------------------
//             | 4. Normalize Nullable Fields
//             |------------------------------------------------------------------
//             */
//             $nullableFields = [
//                 'created_by',
//                 'customer_type',
//                 'sub_loan_type',
//                 'source_by',
//                 'loan_amount',
//                 'loan_tenur',
//                 'applicant_types',
//             ];

//             foreach ($nullableFields as $field) {
//                 if (isset($leadData[$field]) && $leadData[$field] === '') {
//                     $leadData[$field] = null;
//                 }
//             }

//             if (isset($leadData['loan_amount']) && $leadData['loan_amount'] !== null) {
//                 $leadData['loan_amount'] = (float) $leadData['loan_amount'];
//             }

//             if (isset($leadData['loan_tenur']) && $leadData['loan_tenur'] !== null) {
//                 $leadData['loan_tenur'] = (int) $leadData['loan_tenur'];
//             }

//             /*
//             |------------------------------------------------------------------
//             | 5. Force BL Lead Defaults
//             |------------------------------------------------------------------
//             */
//             $leadData['lead_id'] = 'BL-'.now()->format('Ymd').'-'.rand(1000, 9999);
//             $leadData['loan_type'] = 'BL';
//             $leadData['applicant_sno'] = '0';

//             if (! isset($leadData['applicant_types']) || $leadData['applicant_types'] === null) {
//                 $leadData['applicant_types'] = 'applicant';
//             }

//             /*
//             |------------------------------------------------------------------
//             | 6. Final Validation Before DB Insert
//             |------------------------------------------------------------------
//             */
//             $validator = validator($leadData, [
//                 'lead_id' => ['required', 'unique:b_l_lead_creations,lead_id'],
//                 'created_by' => ['nullable', 'exists:users,id'],
//                 'loan_type' => ['required', 'in:BL'],
//                 'customer_type' => ['nullable', 'string', 'max:100'],
//                 'sub_loan_type' => ['nullable', 'string', 'max:100'],
//                 'source_by' => ['nullable', 'string', 'max:100'],
//                 'email' => ['nullable', 'email'],
//                 'mobile_number' => ['required_without:email', 'nullable', 'string', 'size:10'],
//                 'loan_amount' => ['nullable', 'numeric', 'min:0'],
//                 'loan_tenur' => ['nullable', 'integer', 'min:1'],
//                 'applicant_sno' => ['required', 'string'],
//                 'applicant_types' => ['nullable', 'string'],
//             ]);

//             if ($validator->fails()) {
//                 Cache::forget('otp_'.$identifier);
//                 Cache::forget('lead_data_'.$identifier);

//                 return $this->apiError(
//                     'Lead data validation failed.',
//                     $validator->errors(),
//                     422,
//                     'LEAD_VALIDATION_FAILED'
//                 );
//             }

//             $validated = $validator->validate();

//             /*
//             |------------------------------------------------------------------
//             | 7. Transaction: Lead + Journey + Applicant  + References
//             |------------------------------------------------------------------
//             */
//             return DB::transaction(function () use ($validated, $identifier) {
//                 $validated['uuid'] = (string) Str::uuid();


//                 $lead = BLLeadCreation::create($validated);

//                 $applicant = BLApplicant::create([
//                     'lead_uuid' => $lead->uuid,
//                     'created_by' => $validated['created_by'] ?? null,
//                     'lead_id' => $lead->lead_id,
//                     'applicant_sno' => $validated['applicant_sno'],
//                     'applicant_types' => $validated['applicant_types'] ?? 'applicant',
//                 ]);


//                 $blApplicantDetail = BLApplicantDetails::create([
//                     'lead_uuid' => $lead->uuid,
//                     'created_by' => $validated['created_by'] ?? null,
//                     'lead_id' => $lead->lead_id,
//                     'applicant_id' => $applicant->id,
//                     'applicant_sno' => $applicant->applicant_sno,
//                     'applicant_type' => $applicant->applicant_types,
//                     'email_address' => $validated['email'] ?? null,
//                     'alternate_contact_number' => $validated['mobile_number'] ?? null,
//                 ]);


//                 $referenceTypes = ['Reference', 'Reference'];

//                 foreach ($referenceTypes as $index => $refType) {
//                     $sno = $index + 1;

//                     $reference = BLReference::create([
//                         'lead_uuid' => $lead->uuid,
//                         'created_by' => $validated['created_by'] ?? null,
//                         'lead_id' => $lead->lead_id,
//                         'reference_sno' => (string) $sno,
//                         'reference_types' => $refType,
//                     ]);

//                     BLReferenceDetails::create([
//                         'lead_uuid' => $lead->uuid,
//                         'created_by' => $validated['created_by'] ?? null,
//                         'lead_id' => $lead->lead_id,
//                         'reference_id' => $reference->id,
//                         'reference_sno' => $sno,
//                         'reference_type' => $refType,
//                         'full_name' => null,
//                         'mobile_no' => null,
//                         'email' => null,
//                         'gender' => null,
//                         'relationship' => null,
//                         'address' => null,
//                         'pincode' => null,
//                         'district' => null,
//                         'city' => null,
//                         'state' => null,
//                         'status' => 'Pending',
//                         'remarks' => null,
//                     ]);
//                 }


//                 Cache::forget('otp_'.$identifier);
//                 Cache::forget('lead_data_'.$identifier);


//                 return $this->apiSuccess(
//                     [
//                         'lead' => new BLLeadCreationResource($lead->load('creator')),
//                         // 'journey'           => $journey,
//                         'applicant' => $applicant,
//                         'applicant_detail' => $blApplicantDetail,
//                         // 'property'          => $property,    
//                         // 'property_detail'   => $propertyDetail,
//                         'references' => BLReference::where('lead_uuid', $lead->uuid)->get(),
//                         'reference_details' => BLReferenceDetails::where('lead_uuid', $lead->uuid)->get(),
//                     ],
//                     'Lead, first journey step, applicant and references created successfully.',
//                     201,
//                     'BL_LEAD_CREATED_SUCCESSFULLY'
//                 );
//             });

//         } catch (ValidationException $e) {
//             return $this->apiError(
//                 'Validation failed.',
//                 $e->errors(),
//                 422,
//                 'VALIDATION_ERROR'
//             );
//         } catch (Throwable $e) {
//             return $this->apiError(
//                 'Something went wrong while creating BL lead.',
//                 $e->getMessage(),
//                 500,
//                 'SERVER_ERROR'
//             );
//         }
//     }

//     /**
//      * Resend OTP
//      */
//     public function resendOtp(Request $request)
//     {
//         $request->validate([
//             'email' => ['required_without:mobile_number', 'email'],
//             'mobile_number' => ['required_without:email', 'string', 'size:10'],
//         ]);

//         $identifier = $request->email ?? $request->mobile_number;
//         $type = $request->has('email') ? 'email' : 'mobile';

//         $leadData = Cache::get('lead_data_'.$identifier);
//         if (! $leadData) {
//             return response()->json([
//                 'success' => false,
//                 'status_code' => Response::HTTP_NOT_FOUND,
//                 'request_id' => app('request_id'),
//                 'timestamp' => now()->format('Y-m-d H:i:s'),
//                 'message' => 'No pending lead found. Please start over.',
//             ], 404);
//         }

//         $otp = rand(100000, 999999);
//         Cache::put('otp_'.$identifier, $otp, 300);

//         if ($type == 'email') {
//             Mail::send('Emails.BLOtp', ['otp' => $otp], function ($message) use ($identifier) {
//                 $message->to($identifier)->subject('Your New OTP for Lead Verification');
//             });
//         }

//         return response()->json([
//             'success' => true,
//             'status_code' => Response::HTTP_NOT_FOUND,
//                 'request_id' => app('request_id'),
//                 'timestamp' => now()->format('Y-m-d H:i:s'),
//             'message' => "New OTP sent successfully to {$type}",
//             'otp' => app()->environment('local') ? $otp : null,
//         ]);
//     }

//     /**
//      * Index - List all BL Leads
//      */
//     public function index(Request $request)
//     {
//         $query = BLLeadCreation::with('creator');

//         $query->when($request->lead_id, fn ($q) => $q->where('lead_id', $request->lead_id));
//         $query->when($request->mobile_number, fn ($q) => $q->where('mobile_number', $request->mobile_number));

//         $data = $query
//             ->orderByRaw('CAST(SUBSTRING_INDEX(lead_id, "-", -1) AS UNSIGNED) ASC')
//             ->paginate(10);

//         return new BLLeadCreationCollection($data);
//     }

//     /**
//      * Show single BL Lead
//      */
//     public function show(string $lead_uuid)
//     {
//         $data = BLLeadCreation::with('creator')
//             ->where('uuid', $lead_uuid)
//             ->first();

//         if (! $data) {
//             return response()->json([
//                 'success' => false,
//                 'status_code' => Response::HTTP_NOT_FOUND,
//                 'request_id' => app('request_id'),
//                 'timestamp' => now()->format('Y-m-d H:i:s'),
//                 'message' => 'Lead not found.',
//             ], 404);
//         }

//         return response()->json([
//             'success' => true,
//             'status_code' => Response::HTTP_NOT_FOUND,
//                 'request_id' => app('request_id'),
//                 'timestamp' => now()->format('Y-m-d H:i:s'),
//             'message' => 'Lead retrieved successfully.',
//             'data' => new BLLeadCreationResource($data),
//         ]);
//     }

//     /**
//      * Create First BL Journey Step
//      */
//     private function createFirstBLJourney(
//         BLLeadCreation $lead,
//         array $validated,
//         Request $request
//     ): BLJourney {
//         BLJourney::where('lead_uuid', $lead->uuid)
//             ->where('is_latest', true)
//             ->update(['is_latest' => false]);

//         $workflowStage = null;
//         $workflowStatus = null;

//         if (Schema::hasTable('bl_workflow_stages')) {
//             $workflowStage = DB::table('bl_workflow_stages')
//                 ->where('stage_code', 'LEAD_CREATED')
//                 ->first();
//         }

//         if ($workflowStage && Schema::hasTable('bl_workflow_statuses')) {
//             $workflowStatus = DB::table('bl_workflow_statuses')
//                 ->where('stage_id', $workflowStage->id)
//                 ->whereIn('status_code', ['DRAFT', 'SUBMITTED'])
//                 ->orderByRaw("FIELD(status_code, 'DRAFT', 'SUBMITTED')")
//                 ->first();
//         }

//         $authUser = $request->user();

//         $payload = [
//             'lead_uuid' => $lead->uuid,
//             'lead_id' => $lead->lead_id,
//             'workflow_stage_id' => $workflowStage->id ?? null,
//             'workflow_status_id' => $workflowStatus->id ?? null,
//             'previous_stage_id' => null,
//             'action_performed' => 'LEAD_CREATED',
//             'performed_by_role' => $authUser->user_role
//                 ?? $authUser->user_designation
//                 ?? $authUser->user_type
//                 ?? null,
//             'transition_type' => 'INITIATED',
//             'stage' => $workflowStage->stage_name ?? 'Lead Created',
//             'status' => $workflowStatus->status_name ?? 'Journey Started',
//             'team' => $workflowStage->team_name ?? 'Sales',
//             'action_type' => 'LEAD_CREATED',
//             'remarks' => 'Loan journey started after OTP verification.',
//             'user_id' => $authUser->id ?? $validated['created_by'] ?? null,
//             'user_name' => $authUser->user_name ?? $authUser->name ?? null,
//             'is_latest' => true,
//             'ip_address' => $request->ip(),
//             'device_info' => $request->header('User-Agent'),
//             'created_by' => $validated['created_by'] ?? null,
//             'is_completed' => false,
//         ];

//         return BLJourney::create(
//             $this->onlyExistingColumns('bl_journeys', $payload)
//         );
//     }

//     private function onlyExistingColumns(string $table, array $payload): array
//     {
//         return collect($payload)
//             ->filter(fn ($value, $key) => Schema::hasColumn($table, $key))
//             ->toArray();
//     }

//     private function apiSuccess(
//         mixed $data = null,
//         string $message = 'Success',
//         int $statusCode = 200,
//         string $code = 'SUCCESS'
//     ): JsonResponse {
//         return response()->json([
//             'success' => true,
//             'status_code' => $statusCode,

//                 'request_id' => app('request_id'),
//                 'timestamp' => now()->format('Y-m-d H:i:s'),
//             'code' => $code,
//             'message' => $message,
//             'data' => $data,
//         ], $statusCode);
//     }

//     private function apiError(
//         string $message = 'Something went wrong.',
//         mixed $data = null,
//         int $statusCode = 400,
//         string $code = 'ERROR'
//     ): JsonResponse {
//         return response()->json([
//             'success' => false,
//             'status_code' => $statusCode,
//             'code' => $code,

//                 'request_id' => app('request_id'),
//                 'timestamp' => now()->format('Y-m-d H:i:s'),
//             'message' => $message,
//             'data' => $data,
//         ], $statusCode);
//     }

//     private function generateLeadId(): string
//     {
//         $today = now()->format('Ymd');
//         $prefix = 'BL-'.$today.'-';

//         $last = BLLeadCreation::where('lead_id', 'like', $prefix.'%')
//             ->orderByRaw('CAST(SUBSTRING_INDEX(lead_id, "-", -1) AS UNSIGNED) DESC')
//             ->value('lead_id');

//         $nextNumber = $last
//             ? (int) Str::afterLast($last, '-') + 1
//             : 1001;

//         return $prefix.$nextNumber;
//     }
// }




// namespace Modules\BLLeadCreation\Http\Controllers;

// use App\Http\Controllers\Controller;
// use App\Traits\ApiResponse;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Facades\Schema;
// use Illuminate\Support\Str;
// use Illuminate\Validation\ValidationException;
// use Modules\BLApplicant\Models\BLApplicant;
// use Modules\BLApplicantDetails\Models\BLApplicantDetails;
// use Modules\BLJourney\Models\BLJourney;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\BLLeadCreation\Transformers\BLLeadCreationCollection;
// use Modules\BLLeadCreation\Transformers\BLLeadCreationResource;
// use Modules\BLReference\Models\BLReference;
// use Modules\BLReferenceDetails\Models\BLReferenceDetails;
// use Illuminate\Http\Response;
// use Illuminate\Validation\Rule;
// use Modules\BLCam\Models\BLCam;
// use Throwable;

// class BLLeadCreationController extends Controller
// {
//     use ApiResponse;

//     private const LEAD_DETAILS_UPDATE_ROLES = [
//         'SUPER_ADMINISTRATIVE',
//         'ADMINISTRATIVE',
//         'HEAD_OFFICE_CREDIT_CHECKER',
//         'HEAD_OFFICE_CREDIT_OPERATIONS',
//         'HEAD_OFFICE_RISK_CONTROL_UNIT',
//     ];

//     public function assignedBranches(): JsonResponse
//     {
//         $user = Auth::user();

//         if (! $user) {
//             return $this->apiError(
//                 'Unauthenticated.',
//                 null,
//                 401,
//                 'UNAUTHENTICATED'
//             );
//         }

//         $branches = $user->branches()
//             ->select(
//                 'organization_branches.id',
//                 'organization_branches.name',
//                 'organization_branches.branch_code'
//             )
//             ->where('organization_branches.is_active', true)
//             ->get()
//             ->map(fn($branch) => [
//                 'id'          => (int) $branch->id,
//                 'name'        => $branch->name,
//                 'branch_code' => $branch->branch_code,
//             ])
//             ->values();

//         /*
//      * Purane single branch_id ko fallback rakha hai.
//      */
//         if ($branches->isEmpty() && $user->branch_id) {
//             $defaultBranch = DB::table('organization_branches')
//                 ->where('id', $user->branch_id)
//                 ->whereNull('deleted_at')
//                 ->where('is_active', true)
//                 ->first([
//                     'id',
//                     'name',
//                     'branch_code',
//                 ]);

//             if ($defaultBranch) {
//                 $branches->push([
//                     'id'          => (int) $defaultBranch->id,
//                     'name'        => $defaultBranch->name,
//                     'branch_code' => $defaultBranch->branch_code,
//                 ]);
//             }
//         }

//         return $this->apiSuccess(
//             [
//                 'branches' => $branches,
//             ],
//             'Assigned branches fetched successfully.',
//             200,
//             'ASSIGNED_BRANCHES_FETCHED'
//         );
//     }


//     public function sendOtp(Request $request)
//     {
//         $user = Auth::user();

//         if (! $user) {
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Unauthenticated',
//             ], 401);
//         }

//         $request->validate([
//             'email'         => ['required_without:mobile_number', 'email'],
//             // 'mobile_number' => ['required_without:email', 'string', 'size:10'],
//                 'mobile_number' => [
//         'required_without:email',
//         'nullable',
//         'regex:/^[0-9]{10}$/',
//         'unique:b_l_lead_creations,mobile_number',
//     ],
//             'loan_type'     => ['required', 'in:BL'],
//             'customer_type' => ['required', 'string', 'max:100'],
//             'sub_loan_type' => ['required', 'string', 'max:100'],
//             'source_by'     => ['required', 'string', 'max:100'],
//             'loan_amount'   => ['required', 'numeric'],
//             'loan_tenur'    => ['required', 'integer'],
//             'branch_id'     => ['nullable', 'integer', 'exists:organization_branches,id'],
//         ]
//         , [
//     'mobile_number.unique' => 'This mobile number is already registered with another lead.',
//     'mobile_number.regex' => 'Mobile number must be exactly 10 digits.',
// ]);

//         $identifier = $request->email ?? $request->mobile_number;
//         $type       = $request->email ? 'email' : 'mobile';

//         $otp = $this->getDefaultOtp();

//         // A lead belongs to one branch, decided now, not derived later from
//         // whoever created it. If the creator only has one branch, use it
//         // silently; if they have several, they must pick one.

//         $userBranchIds = $user->branches()->pluck('organization_branches.id')->toArray();
//         if (empty($userBranchIds) && $user->branch_id) {
//             $userBranchIds = [(int) $user->branch_id];
//         }

//         if (count($userBranchIds) > 1) {
//             if (empty($request->branch_id) || ! in_array((int) $request->branch_id, $userBranchIds, true)) {
//                 return $this->apiError(
//                     'You are assigned to multiple branches. Please select which branch this lead belongs to.',
//                     ['branches' => $userBranchIds],
//                     422,
//                     'BRANCH_SELECTION_REQUIRED'
//                 );
//             }
//             $branchId = (int) $request->branch_id;
//         } else {
//             $branchId = $userBranchIds[0] ?? null;
//         }

//         $leadData                    = $request->except(['email', 'mobile_number']);
//         $leadData['email']           = $request->email ?? null;
//         $leadData['mobile_number']   = $request->mobile_number ?? null;
//         $leadData['applicant_sno']   = '0';
//         $leadData['applicant_types'] = 'applicant';
//         $leadData['created_by']      = $user->id;
//         $leadData['branch_id']       = $branchId;

//         Cache::put('lead_data_' . $identifier, $leadData, 300);



//         return $this->apiSuccess(
//             [
//                 'identifier' => $identifier,
//                 'type'       => $type,
//                 'otp'        => $otp,
//             ],
//             "OTP sent successfully to {$type}.",
//             200,
//             'OTP_SENT_SUCCESSFULLY'
//         );
//     }

//     public function verifyOtpAndStore(Request $request): JsonResponse
//     {
//         try {
//             $request->validate([
//                 'email'         => ['required_without:mobile_number', 'email'],
//                 'mobile_number' => ['required_without:email', 'string', 'size:10'],
//                 'otp'           => ['required', 'string', 'size:6'],
//             ]);

//             $identifier = $request->email ?? $request->mobile_number;


//             $defaultOtp = $this->getDefaultOtp();

//             if ($request->otp !== $defaultOtp) {
//                 return $this->apiError(
//                     'Invalid OTP.',
//                     null,
//                     400,
//                     'INVALID_OR_EXPIRED_OTP'
//                 );
//             }


//             $leadData = Cache::get('lead_data_' . $identifier);

//             if (! $leadData) {
//                 return $this->apiError(
//                     'Lead data not found. Please resend OTP.',
//                     null,
//                     404,
//                     'LEAD_DATA_NOT_FOUND'
//                 );
//             }


//             if (empty($leadData['email']) && empty($leadData['mobile_number'])) {
//                 Cache::forget('lead_data_' . $identifier);

//                 return $this->apiError(
//                     'Incomplete lead data. Please resend OTP to start over.',
//                     null,
//                     400,
//                     'INCOMPLETE_LEAD_DATA'
//                 );
//             }

//             $cachedEmail  = $leadData['email'] ?? null;
//             $cachedMobile = $leadData['mobile_number'] ?? null;

//             if ($cachedEmail !== $identifier && $cachedMobile !== $identifier) {
//                 Cache::forget('lead_data_' . $identifier);

//                 return $this->apiError(
//                     'Contact information mismatch. Please resend OTP.',
//                     null,
//                     400,
//                     'CONTACT_MISMATCH'
//                 );
//             }

//             $nullableFields = [
//                 'created_by',
//                 'customer_type',
//                 'sub_loan_type',
//                 'source_by',
//                 'loan_amount',
//                 'loan_tenur',
//                 'applicant_types',
//             ];

//             foreach ($nullableFields as $field) {
//                 if (isset($leadData[$field]) && $leadData[$field] === '') {
//                     $leadData[$field] = null;
//                 }
//             }

//             if (isset($leadData['loan_amount']) && $leadData['loan_amount'] !== null) {
//                 $leadData['loan_amount'] = (float) $leadData['loan_amount'];
//             }

//             if (isset($leadData['loan_tenur']) && $leadData['loan_tenur'] !== null) {
//                 $leadData['loan_tenur'] = (int) $leadData['loan_tenur'];
//             }


//             $leadData['lead_id']       = 'BL-' . now()->format('Ymd') . '-' . rand(1000, 9999);
//             $leadData['loan_type']     = 'BL';
//             $leadData['applicant_sno'] = '0';

//             if (! isset($leadData['applicant_types']) || $leadData['applicant_types'] === null) {
//                 $leadData['applicant_types'] = 'applicant';
//             }


//             $validator = validator($leadData, [
//                 'lead_id'         => ['required', 'unique:b_l_lead_creations,lead_id'],
//                 'created_by'      => ['nullable', 'exists:users,id'],
//                 'branch_id'       => ['nullable', 'integer', 'exists:organization_branches,id'],
//                 'loan_type'       => ['required', 'in:BL'],
//                 'customer_type'   => ['nullable', 'string', 'max:100'],
//                 'sub_loan_type'   => ['nullable', 'string', 'max:100'],
//                 'source_by'       => ['nullable', 'string', 'max:100'],
//                 'email'           => ['nullable', 'email'],
//                 // 'mobile_number'   => ['required_without:email', 'nullable', 'string', 'size:10'],
//                   'mobile_number' => [
//         'required_without:email',
//         'nullable',
//         'regex:/^[0-9]{10}$/',
//         'unique:b_l_lead_creations,mobile_number',
//     ],
//                 'loan_amount'     => ['nullable', 'numeric', 'min:0'],
//                 'loan_tenur'      => ['nullable', 'integer', 'min:1'],
//                 'applicant_sno'   => ['required', 'string'],
//                 'applicant_types' => ['nullable', 'string'],
//             ]);

//             if ($validator->fails()) {
//                 Cache::forget('lead_data_' . $identifier);

//                 return $this->apiError(
//                     'Lead data validation failed.',
//                     $validator->errors(),
//                     422,
//                     'LEAD_VALIDATION_FAILED'
//                 );
//             }

//             $validated = $validator->validate();


//             return DB::transaction(function () use ($validated, $identifier) {

//                 $validated['uuid'] = (string) Str::uuid();

//                 $lead = BLLeadCreation::create($validated);

//                 $applicant = BLApplicant::create([
//                     'lead_uuid'       => $lead->uuid,
//                     'created_by'      => $validated['created_by'] ?? null,
//                     'lead_id'         => $lead->lead_id,
//                     'applicant_sno'   => $validated['applicant_sno'],
//                     'applicant_types' => $validated['applicant_types'] ?? 'applicant',
//                 ]);

//                 $blApplicantDetail = BLApplicantDetails::create([
//                     'lead_uuid'                => $lead->uuid,
//                     'created_by'               => $validated['created_by'] ?? null,
//                     'lead_id'                  => $lead->lead_id,
//                     'applicant_id'             => $applicant->id,
//                     'applicant_sno'            => $applicant->applicant_sno,
//                     'applicant_type'           => $applicant->applicant_types,
//                     'email_address'            => $validated['email'] ?? null,
//                     'alternate_contact_number' => $validated['mobile_number'] ?? null,
//                 ]);

//                 $referenceTypes = ['Reference', 'Reference'];

//                 foreach ($referenceTypes as $index => $refType) {
//                     $sno = $index + 1;

//                     $reference = BLReference::create([
//                         'lead_uuid'       => $lead->uuid,
//                         'created_by'      => $validated['created_by'] ?? null,
//                         'lead_id'         => $lead->lead_id,
//                         'reference_sno'   => (string) $sno,
//                         'reference_types' => $refType,
//                     ]);

//                     BLReferenceDetails::create([
//                         'lead_uuid'      => $lead->uuid,
//                         'created_by'     => $validated['created_by'] ?? null,
//                         'lead_id'        => $lead->lead_id,
//                         'reference_id'   => $reference->id,
//                         'reference_sno'  => $sno,
//                         'reference_type' => $refType,
//                         'full_name'      => null,
//                         'mobile_no'      => null,
//                         'email'          => null,
//                         'gender'         => null,
//                         'relationship'   => null,
//                         'address'        => null,
//                         'pincode'        => null,
//                         'district'       => null,
//                         'city'           => null,
//                         'state'          => null,
//                         'status'         => 'Pending',
//                         'remarks'        => null,
//                     ]);
//                 }

//                 Cache::forget('lead_data_' . $identifier);



//                 return $this->apiSuccess(
//                     [
//                         'lead'              => new BLLeadCreationResource($lead->load('creator')),
//                         'applicant'         => $applicant,
//                         'applicant_detail'  => $blApplicantDetail,
//                         'references'        => BLReference::where('lead_uuid', $lead->uuid)->get(),
//                         'reference_details' => BLReferenceDetails::where('lead_uuid', $lead->uuid)->get(),
//                     ],
//                     'Lead, applicant and references created successfully.',
//                     201,
//                     'BL_LEAD_CREATED_SUCCESSFULLY'
//                 );
//             });
//         } catch (ValidationException $e) {
//             return $this->apiError(
//                 'Validation failed.',
//                 $e->errors(),
//                 422,
//                 'VALIDATION_ERROR'
//             );
//         } catch (Throwable $e) {
//             return $this->apiError(
//                 'Something went wrong while creating BL lead.',
//                 $e->getMessage(),
//                 500,
//                 'SERVER_ERROR'
//             );
//         }
//     }



//     public function updateEditableDetails(
//         Request $request,
//         string $lead_uuid
//     ): JsonResponse {
//         try {
//             $user = $request->user();

//             if (! $user) {
//                 return $this->apiError(
//                     'Unauthenticated.',
//                     null,
//                     401,
//                     'UNAUTHENTICATED'
//                 );
//             }

//             if (! $this->canUpdateLeadDetails($user)) {
//                 return $this->apiError(
//                     'You are not authorized to update lead details.',
//                     null,
//                     403,
//                     'LEAD_UPDATE_FORBIDDEN'
//                 );
//             }


//             if ($request->has('loan_amount')) {
//                 $isCamApproved = BLCam::query()
//                     ->where('lead_uuid', $lead_uuid)
//                     ->whereRaw(
//                         'LOWER(TRIM(status)) IN (?, ?)',
//                         ['approve', 'approved']
//                     )
//                     ->exists();

//                 if ($isCamApproved) {
//                     return $this->apiError(
//                         'CAM is already approved. Loan amount cannot be updated.',
//                         null,
//                         422,
//                         'LOAN_AMOUNT_UPDATE_NOT_ALLOWED'
//                     );
//                 }
//             }

//             if (
//                 ! $request->has('loan_amount') &&
//                 ! $request->has('branch_id')
//             ) {
//                 return $this->apiError(
//                     'Loan amount or branch is required.',
//                     null,
//                     422,
//                     'NO_UPDATE_DATA'
//                 );
//             }

//             $validated = $request->validate([
//                 'loan_amount' => [
//                     'sometimes',
//                     'required',
//                     'numeric',
//                     'gt:0',
//                     'max:999999999999.99',
//                 ],

//                 'branch_id' => [
//                     'sometimes',
//                     'required',
//                     'integer',
//                     Rule::exists('organization_branches', 'id')
//                         ->where(function ($query) {
//                             $query
//                                 ->where('is_active', true)
//                                 ->whereNull('deleted_at');
//                         }),
//                 ],
//             ]);

//             /*
//          * Branch update ho rahi hai to selected branch logged-in
//          * user ki assigned branches mein honi chahiye.
//          */
//             if (array_key_exists('branch_id', $validated)) {
//                 $assignedBranchIds = $user->branches()
//                     ->pluck('organization_branches.id')
//                     ->map(fn($branchId) => (int) $branchId)
//                     ->all();

//                 /*
//              * Purane users ke single branch_id ka fallback.
//              */
//                 if (
//                     empty($assignedBranchIds) &&
//                     ! empty($user->branch_id)
//                 ) {
//                     $assignedBranchIds = [
//                         (int) $user->branch_id,
//                     ];
//                 }

//                 if (
//                     ! in_array(
//                         (int) $validated['branch_id'],
//                         $assignedBranchIds,
//                         true
//                     )
//                 ) {
//                     return $this->apiError(
//                         'Selected branch is not assigned to you.',
//                         [
//                             'branch_id' => [
//                                 'Please select one of your assigned branches.',
//                             ],
//                         ],
//                         422,
//                         'BRANCH_NOT_ASSIGNED'
//                     );
//                 }
//             }

//             $updatedLead = DB::transaction(
//                 function () use ($lead_uuid, $validated) {
//                     $lead = BLLeadCreation::query()
//                         ->where('uuid', $lead_uuid)
//                         ->lockForUpdate()
//                         ->first();

//                     if (! $lead) {
//                         return null;
//                     }

//                     $updateData = [];

//                     if (
//                         array_key_exists(
//                             'loan_amount',
//                             $validated
//                         )
//                     ) {
//                         $updateData['loan_amount'] =
//                             $validated['loan_amount'];
//                     }

//                     if (
//                         array_key_exists(
//                             'branch_id',
//                             $validated
//                         )
//                     ) {
//                         $updateData['branch_id'] =
//                             $validated['branch_id'];
//                     }

//                     $lead->update($updateData);

//                     return $lead->fresh(['branch']);
//                 }
//             );

//             if (! $updatedLead) {
//                 return $this->apiError(
//                     'Lead not found.',
//                     null,
//                     404,
//                     'LEAD_NOT_FOUND'
//                 );
//             }

//             return $this->apiSuccess(
//                 [
//                     'lead' => [
//                         'uuid'        => $updatedLead->uuid,
//                         'lead_id'     => $updatedLead->lead_id,
//                         'loan_amount' => $updatedLead->loan_amount,
//                         'branch_id'   => $updatedLead->branch_id,

//                         'branch' => $updatedLead->branch
//                             ? [
//                                 'id' => (int) $updatedLead->branch->id,
//                                 'name' => $updatedLead->branch->name,
//                                 'branch_code' =>
//                                 $updatedLead->branch->branch_code,
//                             ]
//                             : null,
//                     ],

//                     'updated_by' => [
//                         'id' => (int) $user->id,
//                     ],
//                 ],
//                 'Loan amount updated successfully.',
//                 200,
//                 'LEAD_DETAILS_UPDATED'
//             );
//         } catch (ValidationException $e) {
//             return $this->apiError(
//                 'Validation failed.',
//                 $e->errors(),
//                 422,
//                 'VALIDATION_ERROR'
//             );
//         } catch (Throwable $e) {
//             return $this->apiError(
//                 'Something went wrong while updating lead details.',
//                 null,
//                 500,
//                 'SERVER_ERROR'
//             );
//         }
//     }


//     public function resendOtp(Request $request)
//     {
//         $request->validate([
//             'email'         => ['required_without:mobile_number', 'email'],
//             'mobile_number' => ['required_without:email', 'string', 'size:10'],
//         ]);

//         $identifier = $request->email ?? $request->mobile_number;
//         $type       = $request->has('email') ? 'email' : 'mobile';

//         $leadData = Cache::get('lead_data_' . $identifier);

//         if (! $leadData) {
//             return response()->json([
//                 'success'     => false,
//                 'status_code' => Response::HTTP_NOT_FOUND,
//                 'request_id'  => app('request_id'),
//                 'timestamp'   => now()->format('Y-m-d H:i:s'),
//                 'message'     => 'No pending lead found. Please start over.',
//             ], 404);
//         }


//         $otp = $this->getDefaultOtp();



//         return response()->json([
//             'success'     => true,
//             'status_code' => Response::HTTP_OK,
//             'request_id'  => app('request_id'),
//             'timestamp'   => now()->format('Y-m-d H:i:s'),
//             'message'     => "OTP for {$type}",
//             'otp'         => $otp,
//         ]);
//     }


//     public function index(Request $request)
//     {
//         $query = BLLeadCreation::with('creator');

//         $query->when($request->lead_id, fn($q) => $q->where('lead_id', $request->lead_id));
//         $query->when($request->mobile_number, fn($q) => $q->where('mobile_number', $request->mobile_number));

//         $data = $query
//             ->orderByRaw('CAST(SUBSTRING_INDEX(lead_id, "-", -1) AS UNSIGNED) ASC')
//             ->paginate(10);

//         return new BLLeadCreationCollection($data);
//     }


//     public function show(string $lead_uuid)
//     {
//         $data = BLLeadCreation::with('creator')
//             ->where('uuid', $lead_uuid)
//             ->first();

//         if (! $data) {
//             return response()->json([
//                 'success'     => false,
//                 'status_code' => Response::HTTP_NOT_FOUND,
//                 'request_id'  => app('request_id'),
//                 'timestamp'   => now()->format('Y-m-d H:i:s'),
//                 'message'     => 'Lead not found.',
//             ], 404);
//         }

//         return response()->json([
//             'success'     => true,
//             'status_code' => Response::HTTP_OK,
//             'request_id'  => app('request_id'),
//             'timestamp'   => now()->format('Y-m-d H:i:s'),
//             'message'     => 'Lead retrieved successfully.',
//             'data'        => new BLLeadCreationResource($data),
//         ]);
//     }


//     private function canUpdateLeadDetails($user): bool
//     {
//         return $user->roles()
//             ->whereIn(
//                 'roles.code',
//                 self::LEAD_DETAILS_UPDATE_ROLES
//             )
//             ->where('roles.status', true)
//             ->whereNull('roles.deleted_at')
//             ->exists();
//     }

//     private function getDefaultOtp(): string
//     {
//         return '478393';
//     }


//     private function createFirstBLJourney(
//         BLLeadCreation $lead,
//         array $validated,
//         Request $request
//     ): BLJourney {
//         BLJourney::where('lead_uuid', $lead->uuid)
//             ->where('is_latest', true)
//             ->update(['is_latest' => false]);

//         $workflowStage  = null;
//         $workflowStatus = null;

//         if (Schema::hasTable('bl_workflow_stages')) {
//             $workflowStage = DB::table('bl_workflow_stages')
//                 ->where('stage_code', 'LEAD_CREATED')
//                 ->first();
//         }

//         if ($workflowStage && Schema::hasTable('bl_workflow_statuses')) {
//             $workflowStatus = DB::table('bl_workflow_statuses')
//                 ->where('stage_id', $workflowStage->id)
//                 ->whereIn('status_code', ['DRAFT', 'SUBMITTED'])
//                 ->orderByRaw("FIELD(status_code, 'DRAFT', 'SUBMITTED')")
//                 ->first();
//         }

//         $authUser = $request->user();

//         $payload = [
//             'lead_uuid'          => $lead->uuid,
//             'lead_id'            => $lead->lead_id,
//             'workflow_stage_id'  => $workflowStage->id ?? null,
//             'workflow_status_id' => $workflowStatus->id ?? null,
//             'previous_stage_id'  => null,
//             'action_performed'   => 'LEAD_CREATED',
//             'performed_by_role'  => $authUser->user_role ?? $authUser->user_designation ?? $authUser->user_type ?? null,
//             'transition_type'    => 'INITIATED',
//             'stage'              => $workflowStage->stage_name ?? 'Lead Created',
//             'status'             => $workflowStatus->status_name ?? 'Journey Started',
//             'team'               => $workflowStage->team_name ?? 'Sales',
//             'action_type'        => 'LEAD_CREATED',
//             'remarks'            => 'Loan journey started after OTP verification.',
//             'user_id'            => $authUser->id ?? $validated['created_by'] ?? null,
//             'user_name'          => $authUser->user_name ?? $authUser->name ?? null,
//             'is_latest'          => true,
//             'ip_address'         => $request->ip(),
//             'device_info'        => $request->header('User-Agent'),
//             'created_by'         => $validated['created_by'] ?? null,
//             'is_completed'       => false,
//         ];

//         return BLJourney::create(
//             $this->onlyExistingColumns('bl_journeys', $payload)
//         );
//     }

//     private function onlyExistingColumns(string $table, array $payload): array
//     {
//         return collect($payload)
//             ->filter(fn($value, $key) => Schema::hasColumn($table, $key))
//             ->toArray();
//     }

//     private function apiSuccess(
//         mixed $data = null,
//         string $message = 'Success',
//         int $statusCode = 200,
//         string $code = 'SUCCESS'
//     ): JsonResponse {
//         return response()->json([
//             'success'     => true,
//             'status_code' => $statusCode,
//             'request_id'  => app('request_id'),
//             'timestamp'   => now()->format('Y-m-d H:i:s'),
//             'code'        => $code,
//             'message'     => $message,
//             'data'        => $data,
//         ], $statusCode);
//     }

//     private function apiError(
//         string $message = 'Something went wrong.',
//         mixed $data = null,
//         int $statusCode = 400,
//         string $code = 'ERROR'
//     ): JsonResponse {
//         return response()->json([
//             'success'     => false,
//             'status_code' => $statusCode,
//             'code'        => $code,
//             'request_id'  => app('request_id'),
//             'timestamp'   => now()->format('Y-m-d H:i:s'),
//             'message'     => $message,
//             'data'        => $data,
//         ], $statusCode);
//     }



//     private function generateLeadId(): string
//     {
//         $today  = now()->format('Ymd');
//         $prefix = 'BL-' . $today . '-';

//         $last = BLLeadCreation::where('lead_id', 'like', $prefix . '%')
//             ->orderByRaw('CAST(SUBSTRING_INDEX(lead_id, "-", -1) AS UNSIGNED) DESC')
//             ->value('lead_id');

//         $nextNumber = $last
//             ? (int) Str::afterLast($last, '-') + 1
//             : 1001;

//         return $prefix . $nextNumber;
//     }
// }
