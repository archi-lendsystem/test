<?php

namespace Modules\BLLeadCreation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
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
use Modules\Product\Models\Product;
use Modules\Customers\Models\Customer;
use Modules\BLJourney\Exceptions\JourneyDefinitionException;
use Modules\BLJourney\Services\JourneyDefinitionService;
use Modules\Seller\Models\Seller;
use Modules\SellerDetail\Models\SellerDetail;
use Modules\PropertyDetail\Models\PropertyDetail;
use Modules\Seller\Transformers\SellerResource;
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
        $this->audit('info', 'request_started', ['stage' => 'assignedBranches']);
        try {
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
        } catch (ValidationException $e) {
            $this->audit('warning', 'validation_exception', ['stage' => 'assignedBranches', 'fields' => array_keys($e->errors())]);
            throw $e;
        } catch (Throwable $e) {
            $this->auditException($e, 'assignedBranches');
            throw $e;
        }
    }

    public function sendOtp(Request $request)
    {
        $this->audit('info', 'request_started', ['stage' => 'sendOtp']);
        try {
        $user = Auth::user();

        if (! $user) {
            $this->audit('warning', 'response_error', ['stage' => 'sendOtp', 'http_status' => 401, 'code' => 'UNAUTHENTICATED']);
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $request->validate([
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(function ($query) {
                    $query->where('status', true)->whereNull('deleted_at');
                }),
            ],
        ]);

        $product = Product::query()
            ->whereKey((int) $request->product_id)
            ->where('status', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $product) {
            return $this->apiError(
                'Selected product is invalid or inactive.',
                ['product_id' => ['Please select an active product.']],
                422,
                'INVALID_PRODUCT'
            );
        }

        app(JourneyDefinitionService::class)->forProduct($product);

        $allowedSubLoanTypes = $this->allowedSubLoanTypesForProduct($product->code);

        $request->validate([
            'email'         => ['required_without:mobile_number', 'email'],
            'mobile_number' => ['required_without:email', 'string', 'size:10'],
            'customer_type' => ['required', Rule::in(['New', 'Existing'])],
            'aadhaar_card_number' => [Rule::requiredIf(fn () => $request->customer_type === 'Existing'), 'nullable', 'regex:/^[0-9]{12}$/'],
            'sub_loan_type' => ['required', 'string', Rule::in($allowedSubLoanTypes)],
            'source_by'     => ['required', 'string', 'max:100'],
            'loan_amount'   => ['required', 'numeric'],
            'loan_tenur'    => ['required', 'integer'],
            'branch_id'     => ['nullable', 'integer', 'exists:organization_branches,id'],
        ], [
            'sub_loan_type.in' => sprintf(
                'Selected sub loan type is not allowed for %s (%s).',
                $product->name,
                $product->code
            ),
        ]);

        // Reloan belongs to the selected product only; never clone a different product journey.
        $sourceParticipant = null;
        if ($request->customer_type === 'Existing') {
          Log::channel('bl_audit')->info('RELOAN_LOOKUP_STARTED', [
    'product_id' => (int) $product->id,
    'customer_type' => $request->customer_type,
    'user_id' => Auth::id(),
]);

try {
    $sourceParticipant = app(BLReloanService::class)
        ->findSourceParticipant(
            (string) $request->mobile_number,
            (string) $request->aadhaar_card_number,
            (int) $product->id
        );

    Log::channel('bl_audit')->info('RELOAN_LOOKUP_COMPLETED', [
        'found' => $sourceParticipant !== null,
        'product_id' => (int) $product->id,
    ]);

} catch (Throwable $e) {

    Log::channel('bl_audit')->error('RELOAN_LOOKUP_FAILED', [
        'exception_class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    throw $e;
}
            if (! $sourceParticipant) {
                return $this->apiError(
                    'No matching participant was found for this loan product.',
                    ['customer' => ['Mobile and Aadhaar must belong to the same Applicant, Co-applicant or Guarantor in this product.']],
                    422, 'EXISTING_CUSTOMER_NOT_FOUND'
                );
            }
        } elseif ($request->customer_type === 'New') {
            $registered = Customer::query()->where('mobile_number', (string) $request->mobile_number)->exists()
                || BLApplicantDetails::query()->where('alternate_contact_number', (string) $request->mobile_number)->exists();
            if ($registered) {
                return $this->apiError('Customer already exists. Select Existing customer.',
                    ['mobile_number' => ['This mobile number is already registered.']], 422, 'CUSTOMER_ALREADY_EXISTS');
            }
        }

        $identifier = $request->email ?? $request->mobile_number;
        $type       = $request->email ? 'email' : 'mobile';

        $otp = $this->getDefaultOtp();

        // A lead belongs to one branch, decided now, not derived later from
        // whoever created it. If the creator only has one branch, use it
        // silently; if they have several, they must pick one.

        $userBranchIds = $user->branches()->pluck('organization_branches.id')->toArray();
        if (empty($userBranchIds) && $user->branch_id) {
            $userBranchIds = [(int) $user->branch_id];
        }

        if (count($userBranchIds) > 1) {
            if (empty($request->branch_id) || ! in_array((int) $request->branch_id, $userBranchIds, true)) {
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

        $leadData                    = $request->except(['email', 'mobile_number']);
        $leadData['email']           = $request->email ?? null;
        $leadData['mobile_number']   = $request->mobile_number ?? null;
        $leadData['applicant_sno']   = '0';
        $leadData['applicant_types'] = 'applicant';
        $leadData['created_by']      = $user->id;
        $leadData['branch_id']       = $branchId;
        $leadData['product_id']      = (int) $product->id;
        // Keep loan_type for backward compatibility with existing BL flow.
        // It is always derived from the selected product; frontend value is not trusted.
        $leadData['loan_type']       = strtoupper((string) $product->code);
        $leadData['is_reloan'] = $request->customer_type === 'Existing';
        $leadData['previous_lead_uuid'] = $sourceParticipant?->lead_uuid;
        // Cache-only metadata; never persist Aadhaar verification input on the lead.
        $leadData['source_participant_detail_id'] = $sourceParticipant?->id;
        $leadData['source_lead_uuid'] = $sourceParticipant?->lead_uuid;
        $leadData['verification_aadhaar'] = $request->customer_type === 'Existing'
            ? (string) $request->aadhaar_card_number : null;
        unset($leadData['aadhaar_card_number']);

        Cache::put('lead_data_' . $identifier, $leadData, 300);

        return $this->apiSuccess(
            [
                'identifier' => $identifier,
                'type'       => $type,
                'otp'        => $otp,
            ],
            "OTP sent successfully to {$type}.",
            200,
            'OTP_SENT_SUCCESSFULLY'
        );
        } catch (ValidationException $e) {
            $this->audit('warning', 'validation_exception', ['stage' => 'sendOtp', 'fields' => array_keys($e->errors())]);
            throw $e;
        } catch (Throwable $e) {
            $this->auditException($e, 'sendOtp');
            throw $e;
        }
    }

    public function verifyOtpAndStore(Request $request): JsonResponse
    {
        $this->audit('info', 'request_started', ['stage' => 'verifyOtpAndStore']);
        try {
            $request->validate([
                'email'         => ['required_without:mobile_number', 'email'],
                'mobile_number' => ['required_without:email', 'string', 'size:10'],
                'otp'           => ['required', 'string', 'size:6'],
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

            $cachedEmail  = $leadData['email'] ?? null;
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

            $product = Product::query()
                ->whereKey((int) ($leadData['product_id'] ?? 0))
                ->where('status', true)
                ->whereNull('deleted_at')
                ->first();

            if (! $product) {
                Cache::forget('lead_data_' . $identifier);

                return $this->apiError(
                    'Selected product is no longer available. Please start the lead again.',
                    ['product_id' => ['Selected product is invalid or inactive.']],
                    422,
                    'INVALID_PRODUCT'
                );
            }

            app(JourneyDefinitionService::class)->forProduct($product);

            $allowedSubLoanTypes = $this->allowedSubLoanTypesForProduct($product->code);

            if (! in_array((string) ($leadData['sub_loan_type'] ?? ''), $allowedSubLoanTypes, true)) {
                Cache::forget('lead_data_' . $identifier);

                return $this->apiError(
                    'Selected sub loan type is not allowed for the selected product.',
                    [
                        'sub_loan_type' => [
                            sprintf(
                                'Allowed sub loan types for %s (%s): %s',
                                $product->name,
                                $product->code,
                                implode(', ', $allowedSubLoanTypes)
                            ),
                        ],
                    ],
                    422,
                    'INVALID_SUB_LOAN_TYPE'
                );
            }

            $sourceParticipant = null;
            if (($leadData['customer_type'] ?? '') === 'Existing') {
                $sourceParticipant = app(BLReloanService::class)->revalidateSourceParticipant(
                    (int) ($leadData['source_participant_detail_id'] ?? 0),
                    (string) ($leadData['source_lead_uuid'] ?? ''),
                    (string) ($leadData['mobile_number'] ?? ''),
                    (string) ($leadData['verification_aadhaar'] ?? ''),
                    (int) $product->id
                );
                if (! $sourceParticipant) {
                    Cache::forget('lead_data_' . $identifier);
                    return $this->apiError('Source participant verification failed. Please start again.',
                        null, 422, 'EXISTING_CUSTOMER_REVALIDATION_FAILED');
                }
            }

            $nullableFields = [
                'created_by',
                'customer_type',
                'previous_lead_uuid',
                'source_participant_detail_id',
                'source_lead_uuid',
                'verification_aadhaar',
                'sub_loan_type',
                'source_by',
                'loan_amount',
                'loan_tenur',
                'applicant_types',
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

            $leadData['product_id']    = (int) $product->id;
            $leadData['lead_id']       = $this->generateLeadId($product->code);
            $leadData['loan_type']     = strtoupper((string) $product->code);
            $leadData['applicant_sno'] = '0';

            if (! isset($leadData['applicant_types']) || $leadData['applicant_types'] === null) {
                $leadData['applicant_types'] = 'applicant';
            }

            $validator = validator($leadData, [
                'lead_id'         => ['required', 'unique:b_l_lead_creations,lead_id'],
                'created_by'      => ['nullable', 'exists:users,id'],
                'branch_id'       => ['nullable', 'integer', 'exists:organization_branches,id'],
                'product_id'      => ['required', 'integer', 'exists:products,id'],
                'loan_type'       => ['required', Rule::in([strtoupper((string) $product->code)])],
                'customer_type'   => ['required', Rule::in(['New', 'Existing'])],
                'is_reloan' => ['required', 'boolean'],
                'previous_lead_uuid' => ['nullable', 'uuid', 'exists:b_l_lead_creations,uuid'],
                'sub_loan_type'   => ['required', 'string', Rule::in($allowedSubLoanTypes)],
                'source_by'       => ['nullable', 'string', 'max:100'],
                'email'           => ['nullable', 'email'],
                'mobile_number'   => ['required_without:email', 'nullable', 'string', 'size:10'],
                'loan_amount'     => ['nullable', 'numeric', 'min:0'],
                'loan_tenur'      => ['nullable', 'integer', 'min:1'],
                'applicant_sno'   => ['required', 'string'],
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
            // Source identifiers are trusted only from the server-side OTP cache.
            $sourceId = (int) ($leadData['source_participant_detail_id'] ?? 0);
            $sourceUuid = (string) ($leadData['source_lead_uuid'] ?? '');
            $sourceAadhaar = (string) ($leadData['verification_aadhaar'] ?? '');


            return DB::transaction(function () use ($validated, $identifier, $sourceId, $sourceUuid, $sourceAadhaar) {

                $validated['uuid'] = (string) Str::uuid();

                $lead = BLLeadCreation::create($validated);

          
               $defaultSeller = null;
$defaultSellerDetail = null;

if ((int) $lead->product_id === 4) {

    /*
    |--------------------------------------------------------------------------
    | Default Seller
    |--------------------------------------------------------------------------
    */
    $defaultSeller = Seller::create([
        'lead_uuid'  => $lead->uuid,
        'lead_id'    => $lead->lead_id,
        'product_id' => $lead->product_id,
        'created_by' => $lead->created_by,
        'seller_sno' => 1,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Default blank Seller Detail
    |--------------------------------------------------------------------------
    */
    $defaultSellerDetail = SellerDetail::create([
        'seller_id'  => $defaultSeller->id,
        'lead_uuid'  => $lead->uuid,
        'product_id' => $lead->product_id,
    ]);
}

                $applicant = null;
                $blApplicantDetail = null;
                if ($lead->customer_type === 'Existing') {
                    $matched = app(BLReloanService::class)->revalidateSourceParticipant(
                        $sourceId, $sourceUuid, (string) $lead->mobile_number,
                        $sourceAadhaar, (int) $lead->product_id
                    );
                    if (! $matched) {
                        throw new \RuntimeException('The source participant changed during verification.');
                    }
                    $snapshot = app(BLReloanService::class)->cloneParticipantsForReloan(
                        $lead, $matched, $validated['created_by'] ?? null
                    );
                    $applicant = collect($snapshot['applicants'])->first(fn ($a) =>
                        strtolower(trim((string) $a->applicant_types)) === 'applicant');
                    $blApplicantDetail = collect($snapshot['details'])->first(fn ($d) =>
                        $applicant && (int) $d->applicant_id === (int) $applicant->id);
                    if (! $applicant || ! $blApplicantDetail) {
                        throw new \RuntimeException('Reloan primary applicant snapshot was not created.');
                    }
                } else {
                    $applicant = BLApplicant::create([
                        'lead_uuid' => $lead->uuid,
                        'product_id' => $lead->product_id,
                        'created_by' => $validated['created_by'] ?? null,
                        'lead_id' => $lead->lead_id,
                        'applicant_sno' => $validated['applicant_sno'],
                        'applicant_types' => $validated['applicant_types'] ?? 'applicant',
                    ]);
                    $blApplicantDetail = BLApplicantDetails::create([
                        'lead_uuid' => $lead->uuid,
                        'product_id' => $lead->product_id,
                        'created_by' => $validated['created_by'] ?? null,
                        'lead_id' => $lead->lead_id,
                        'applicant_id' => $applicant->id,
                        'applicant_sno' => $applicant->applicant_sno,
                        'applicant_type' => $applicant->applicant_types,
                        'email_address' => $validated['email'] ?? null,
                        'alternate_contact_number' => $validated['mobile_number'] ?? null,
                    ]);
                }

                // LAP aur HL dono ke liye Property Detail base record create karo.
$propertyDetail = null;

if (in_array((int) $lead->product_id, [1, 4], true)) {
    $propertyDetail = PropertyDetail::firstOrCreate(
        [
            'lead_uuid' => $lead->uuid,
        ],
        [
            'lead_id'    => $lead->lead_id,
            'product_id' => $lead->product_id,
            // 'created_by' => $lead->created_by,
        ]
    );
}

                $referenceTypes = ['Reference', 'Reference'];

                if (! BLReference::where('lead_uuid', $lead->uuid)->exists()) {
                foreach ($referenceTypes as $index => $refType) {
                    $sno = $index + 1;

                    $reference = BLReference::create([
                        'lead_uuid'       => $lead->uuid,
                          'product_id'      => $lead->product_id,
                        'created_by'      => $validated['created_by'] ?? null,
                        'lead_id'         => $lead->lead_id,
                        'reference_sno'   => (string) $sno,
                        'reference_types' => $refType,
                    ]);

                    BLReferenceDetails::create([
                        'lead_uuid'      => $lead->uuid,
                          'product_id'      => $lead->product_id,
                        'created_by'     => $validated['created_by'] ?? null,
                        'lead_id'        => $lead->lead_id,
                        'reference_id'   => $reference->id,
                        'reference_sno'  => $sno,
                        'reference_type' => $refType,
                        'full_name'      => null,
                        'mobile_no'      => null,
                        'email'          => null,
                        'gender'         => null,
                        'relationship'   => null,
                        'address'        => null,
                        'pincode'        => null,
                        'district'       => null,
                        'city'           => null,
                        'state'          => null,
                        'status'         => 'Pending',
                        'remarks'        => null,
                    ]);
                }

                } // Default references only if none were cloned.

                Cache::forget('lead_data_' . $identifier);

                return $this->apiSuccess(
                    [
                        'lead'              => new BLLeadCreationResource($lead->load(['creator', 'product'])),
                        'lead_uuid'         => $lead->uuid,
                        'is_reloan' => (bool) $lead->is_reloan,
                        'previous_lead_uuid' => $lead->previous_lead_uuid,
                        'next_form'         => match ((int) $lead->product_id) {
                            1 => 'property_detail',
                            4 => 'seller_detail',
                            default => null,
                        },
                        'applicant'         => $applicant,
                        'applicant_detail'  => $blApplicantDetail,
        'property_detail' => $propertyDetail,
                        'seller'            => $defaultSeller
                            ? new SellerResource($defaultSeller->load('sellerDetail'))
                            : null,

                            'seller_detail' => $defaultSellerDetail,
                        'references'        => BLReference::where('lead_uuid', $lead->uuid)->get(),
                        'reference_details' => BLReferenceDetails::where('lead_uuid', $lead->uuid)->get(),
                    ],
                    'Lead, applicant and references created successfully.',
                    201,
                    'LEAD_CREATED_SUCCESSFULLY'
                );
            });
        } catch (ValidationException $e) {
            $this->audit('warning', 'validation_exception', ['fields' => array_keys($e->errors())]);
            return $this->apiError(
                'Validation failed.',
                $e->errors(),
                422,
                'VALIDATION_ERROR'
            );
        } catch (JourneyDefinitionException $e) {
            $this->auditException($e, 'journey_definition');
            return $this->apiError(
                $e->getMessage(),
                null,
                $e->getStatusCode(),
                $e->errorCode
            );
        } catch (Throwable $e) {
            $this->auditException($e, 'controller_caught');
            return $this->apiError(
                'Something went wrong while creating lead.',
                $e->getMessage(),
                500,
                'SERVER_ERROR'
            );
        }
    }

    public function updateEditableDetails(
        Request $request,
        string $lead_uuid
    ): JsonResponse {
        $this->audit('info', 'request_started', ['stage' => 'updateEditableDetails']);
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
            $this->audit('warning', 'validation_exception', ['fields' => array_keys($e->errors())]);
            return $this->apiError(
                'Validation failed.',
                $e->errors(),
                422,
                'VALIDATION_ERROR'
            );
        } catch (Throwable $e) {
            $this->auditException($e, 'controller_caught');
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
        $this->audit('info', 'request_started', ['stage' => 'resendOtp']);
        try {
        $request->validate([
            'email'         => ['required_without:mobile_number', 'email'],
            'mobile_number' => ['required_without:email', 'string', 'size:10'],
        ]);

        $identifier = $request->email ?? $request->mobile_number;
        $type       = $request->has('email') ? 'email' : 'mobile';

        $leadData = Cache::get('lead_data_' . $identifier);

        if (! $leadData) {
            $this->audit('warning', 'response_error', ['stage' => 'resendOtp', 'http_status' => 404, 'code' => 'PENDING_LEAD_NOT_FOUND']);
            return response()->json([
                'success'     => false,
                'status_code' => Response::HTTP_NOT_FOUND,
                'request_id'  => app('request_id'),
                'timestamp'   => now()->format('Y-m-d H:i:s'),
                'message'     => 'No pending lead found. Please start over.',
            ], 404);
        }

        $otp = $this->getDefaultOtp();
        $this->audit('info', 'response_success', ['stage' => 'resendOtp', 'http_status' => 200]);

        $this->audit('info', 'response_success', ['stage' => 'show', 'http_status' => 200]);
        return response()->json([
            'success'     => true,
            'status_code' => Response::HTTP_OK,
            'request_id'  => app('request_id'),
            'timestamp'   => now()->format('Y-m-d H:i:s'),
            'message'     => "OTP for {$type}",
            'otp'         => $otp,
        ]);
        } catch (ValidationException $e) {
            $this->audit('warning', 'validation_exception', ['stage' => 'resendOtp', 'fields' => array_keys($e->errors())]);
            throw $e;
        } catch (Throwable $e) {
            $this->auditException($e, 'resendOtp');
            throw $e;
        }
    }

    public function index(Request $request)
    {
        $this->audit('info', 'request_started', ['stage' => 'index']);
        try {
        $query = BLLeadCreation::with(['creator', 'product']);

        $query->when($request->lead_id, fn($q) => $q->where('lead_id', $request->lead_id));
        $query->when($request->mobile_number, fn($q) => $q->where('mobile_number', $request->mobile_number));
        $query->when($request->product_id, fn($q) => $q->where('product_id', $request->product_id));

        $data = $query
            ->orderByRaw('CAST(SUBSTRING_INDEX(lead_id, "-", -1) AS UNSIGNED) ASC')
            ->paginate(10);

        $this->audit('info', 'response_success', ['stage' => 'index', 'http_status' => 200, 'count' => $data->count()]);
        return new BLLeadCreationCollection($data);
        } catch (ValidationException $e) {
            $this->audit('warning', 'validation_exception', ['stage' => 'index', 'fields' => array_keys($e->errors())]);
            throw $e;
        } catch (Throwable $e) {
            $this->auditException($e, 'index');
            throw $e;
        }
    }

    public function show(string $lead_uuid)
    {
        $this->audit('info', 'request_started', ['stage' => 'show']);
        try {
        $data = BLLeadCreation::with(['creator', 'product'])
            ->where('uuid', $lead_uuid)
            ->first();

        if (! $data) {
            $this->audit('warning', 'response_error', ['stage' => 'show', 'http_status' => 404, 'code' => 'LEAD_NOT_FOUND']);
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
            'next_form'   => match ((int) $data->product_id) {
                1 => 'property_detail',
                4 => 'seller_detail',
                default => null,
            },
        ]);
        } catch (ValidationException $e) {
            $this->audit('warning', 'validation_exception', ['stage' => 'show', 'fields' => array_keys($e->errors())]);
            throw $e;
        } catch (Throwable $e) {
            $this->auditException($e, 'show');
            throw $e;
        }
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
            'previous_stage_id' => null,
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

    /**
     * Audit logging is deliberately best-effort: a misconfigured log channel must
     * never change loan creation outcomes. Never log OTP, Aadhaar, mobile or email.
     */
    private function audit(string $level, string $event, array $context = []): void
    {
        try {
            $request = request();
            $safe = [
                'event' => $event,
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => Auth::id(),
                'product_id' => $request->input('product_id'),
                'customer_type' => $request->input('customer_type'),
                'request_id' => app()->bound('request_id') ? app('request_id') : null,
            ];
            Log::channel('bl_audit')->log($level, 'BL lead creation: '.$event, array_merge($safe, $context));
        } catch (Throwable $loggingError) {
            // Avoid changing the response if logging storage/channel is unavailable.
        }
    }

    private function redactAuditText(string $value): string
    {
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[REDACTED_EMAIL]', $value);
        $value = preg_replace('/(?<!\d)\d{12}(?!\d)/', '[REDACTED_AADHAAR]', $value);
        return preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/', '[REDACTED_MOBILE]', $value);
    }

    private function auditException(Throwable $e, string $stage): void
    {
        $this->audit('error', 'exception', [
            'stage' => $stage,
            'exception_class' => get_class($e),
            'exception_message' => $this->redactAuditText($e->getMessage()),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'trace' => $this->redactAuditText($e->getTraceAsString()),
        ]);
    }

    private function apiSuccess(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = 200,
        string $code = 'SUCCESS'
    ): JsonResponse {
        $this->audit('info', 'response_success', ['http_status' => $statusCode, 'code' => $code]);
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
        $this->audit('warning', 'response_error', ['http_status' => $statusCode, 'code' => $code, 'message' => $message]);
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

    private function allowedSubLoanTypesForProduct(string $productCode): array
    {
        $allSubLoanTypes = array_keys(
            config('product_configuration.sub_loan_types', [
                'weekly'    => 'Weekly Loan',
                'bi_weekly' => 'Bi-Weekly Loan',
                'monthly'   => 'Monthly Loan',
                'daily'     => 'Daily Loan',
                'bullet'    => 'Bullet Loan',
                'payday'    => 'Payday Loan',
            ])
        );

        $defaultRules = [
            'LAP' => ['monthly'],
            'HL'  => ['monthly'],
            'BL'  => ['weekly', 'bi_weekly', 'monthly', 'daily', 'bullet', 'payday'],
            'PL'  => ['weekly', 'bi_weekly', 'monthly', 'daily', 'bullet', 'payday'],
        ];

        $rules = config('product_configuration.product_sub_loan_types', $defaultRules);
        $allowed = $rules[strtoupper(trim($productCode))] ?? [];

        return array_values(array_intersect($allowed, $allSubLoanTypes));
    }

    private function generateLeadId(string $productCode): string
    {
        $today = now()->format('Ymd');
        $code = strtoupper(trim($productCode));
        $prefix = $code . '-' . $today . '-';

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
// use Illuminate\Validation\Rule;
// use Modules\BLCam\Models\BLCam;
// use Modules\Product\Models\Product;
// use Modules\BLJourney\Exceptions\JourneyDefinitionException;
// use Modules\BLJourney\Services\JourneyDefinitionService;
// use Modules\Seller\Models\Seller;
// use Modules\SellerDetail\Models\SellerDetail;
// use Modules\PropertyDetail\Models\PropertyDetail;
// use Modules\Seller\Transformers\SellerResource;
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
//             'product_id' => [
//                 'required',
//                 'integer',
//                 Rule::exists('products', 'id')->where(function ($query) {
//                     $query->where('status', true)->whereNull('deleted_at');
//                 }),
//             ],
//         ]);

//         $product = Product::query()
//             ->whereKey((int) $request->product_id)
//             ->where('status', true)
//             ->whereNull('deleted_at')
//             ->first();

//         if (! $product) {
//             return $this->apiError(
//                 'Selected product is invalid or inactive.',
//                 ['product_id' => ['Please select an active product.']],
//                 422,
//                 'INVALID_PRODUCT'
//             );
//         }

//         app(JourneyDefinitionService::class)->forProduct($product);

//         $allowedSubLoanTypes = $this->allowedSubLoanTypesForProduct($product->code);

//         $request->validate([
//             'email'         => ['required_without:mobile_number', 'email'],
//             'mobile_number' => ['required_without:email', 'string', 'size:10'],
//             'customer_type' => ['required', 'string', 'max:100'],
//             'sub_loan_type' => ['required', 'string', Rule::in($allowedSubLoanTypes)],
//             'source_by'     => ['required', 'string', 'max:100'],
//             'loan_amount'   => ['required', 'numeric'],
//             'loan_tenur'    => ['required', 'integer'],
//             'branch_id'     => ['nullable', 'integer', 'exists:organization_branches,id'],
//         ], [
//             'sub_loan_type.in' => sprintf(
//                 'Selected sub loan type is not allowed for %s (%s).',
//                 $product->name,
//                 $product->code
//             ),
//         ]);

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
//         $leadData['product_id']      = (int) $product->id;
//         // Keep loan_type for backward compatibility with existing BL flow.
//         // It is always derived from the selected product; frontend value is not trusted.
//         $leadData['loan_type']       = strtoupper((string) $product->code);

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

//             $product = Product::query()
//                 ->whereKey((int) ($leadData['product_id'] ?? 0))
//                 ->where('status', true)
//                 ->whereNull('deleted_at')
//                 ->first();

//             if (! $product) {
//                 Cache::forget('lead_data_' . $identifier);

//                 return $this->apiError(
//                     'Selected product is no longer available. Please start the lead again.',
//                     ['product_id' => ['Selected product is invalid or inactive.']],
//                     422,
//                     'INVALID_PRODUCT'
//                 );
//             }

//             app(JourneyDefinitionService::class)->forProduct($product);

//             $allowedSubLoanTypes = $this->allowedSubLoanTypesForProduct($product->code);

//             if (! in_array((string) ($leadData['sub_loan_type'] ?? ''), $allowedSubLoanTypes, true)) {
//                 Cache::forget('lead_data_' . $identifier);

//                 return $this->apiError(
//                     'Selected sub loan type is not allowed for the selected product.',
//                     [
//                         'sub_loan_type' => [
//                             sprintf(
//                                 'Allowed sub loan types for %s (%s): %s',
//                                 $product->name,
//                                 $product->code,
//                                 implode(', ', $allowedSubLoanTypes)
//                             ),
//                         ],
//                     ],
//                     422,
//                     'INVALID_SUB_LOAN_TYPE'
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

//             $leadData['product_id']    = (int) $product->id;
//             $leadData['lead_id']       = $this->generateLeadId($product->code);
//             $leadData['loan_type']     = strtoupper((string) $product->code);
//             $leadData['applicant_sno'] = '0';

//             if (! isset($leadData['applicant_types']) || $leadData['applicant_types'] === null) {
//                 $leadData['applicant_types'] = 'applicant';
//             }

//             $validator = validator($leadData, [
//                 'lead_id'         => ['required', 'unique:b_l_lead_creations,lead_id'],
//                 'created_by'      => ['nullable', 'exists:users,id'],
//                 'branch_id'       => ['nullable', 'integer', 'exists:organization_branches,id'],
//                 'product_id'      => ['required', 'integer', 'exists:products,id'],
//                 'loan_type'       => ['required', Rule::in([strtoupper((string) $product->code)])],
//                 'customer_type'   => ['nullable', 'string', 'max:100'],
//                 'sub_loan_type'   => ['required', 'string', Rule::in($allowedSubLoanTypes)],
//                 'source_by'       => ['nullable', 'string', 'max:100'],
//                 'email'           => ['nullable', 'email'],
//                 'mobile_number'   => ['required_without:email', 'nullable', 'string', 'size:10'],
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

          
//                $defaultSeller = null;
// $defaultSellerDetail = null;

// if ((int) $lead->product_id === 4) {

//     /*
//     |--------------------------------------------------------------------------
//     | Default Seller
//     |--------------------------------------------------------------------------
//     */
//     $defaultSeller = Seller::create([
//         'lead_uuid'  => $lead->uuid,
//         'lead_id'    => $lead->lead_id,
//         'product_id' => $lead->product_id,
//         'created_by' => $lead->created_by,
//         'seller_sno' => 1,
//     ]);

//     /*
//     |--------------------------------------------------------------------------
//     | Default blank Seller Detail
//     |--------------------------------------------------------------------------
//     */
//     $defaultSellerDetail = SellerDetail::create([
//         'seller_id'  => $defaultSeller->id,
//         'lead_uuid'  => $lead->uuid,
//         'product_id' => $lead->product_id,
//     ]);
// }

//                 $applicant = BLApplicant::create([
//                     'lead_uuid'       => $lead->uuid,
//                       'product_id'      => $lead->product_id,
//                     'created_by'      => $validated['created_by'] ?? null,
//                     'lead_id'         => $lead->lead_id,
//                     'applicant_sno'   => $validated['applicant_sno'],
//                     'applicant_types' => $validated['applicant_types'] ?? 'applicant',
//                 ]);

//                 $blApplicantDetail = BLApplicantDetails::create([
//                     'lead_uuid'                => $lead->uuid,
//                       'product_id'      => $lead->product_id,
//                     'created_by'               => $validated['created_by'] ?? null,
//                     'lead_id'                  => $lead->lead_id,
//                     'applicant_id'             => $applicant->id,
//                     'applicant_sno'            => $applicant->applicant_sno,
//                     'applicant_type'           => $applicant->applicant_types,
//                     'email_address'           => $validated['email'] ?? null,
//                     'alternate_contact_number' => $validated['mobile_number'] ?? null,
//                 ]);

//                 // LAP aur HL dono ke liye Property Detail base record create karo.
// $propertyDetail = null;

// if (in_array((int) $lead->product_id, [1, 4], true)) {
//     $propertyDetail = PropertyDetail::firstOrCreate(
//         [
//             'lead_uuid' => $lead->uuid,
//         ],
//         [
//             'lead_id'    => $lead->lead_id,
//             'product_id' => $lead->product_id,
//             // 'created_by' => $lead->created_by,
//         ]
//     );
// }

//                 $referenceTypes = ['Reference', 'Reference'];

//                 foreach ($referenceTypes as $index => $refType) {
//                     $sno = $index + 1;

//                     $reference = BLReference::create([
//                         'lead_uuid'       => $lead->uuid,
//                           'product_id'      => $lead->product_id,
//                         'created_by'      => $validated['created_by'] ?? null,
//                         'lead_id'         => $lead->lead_id,
//                         'reference_sno'   => (string) $sno,
//                         'reference_types' => $refType,
//                     ]);

//                     BLReferenceDetails::create([
//                         'lead_uuid'      => $lead->uuid,
//                           'product_id'      => $lead->product_id,
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
//                         'lead'              => new BLLeadCreationResource($lead->load(['creator', 'product'])),
//                         'lead_uuid'         => $lead->uuid,
//                         'next_form'         => match ((int) $lead->product_id) {
//                             1 => 'property_detail',
//                             4 => 'seller_detail',
//                             default => null,
//                         },
//                         'applicant'         => $applicant,
//                         'applicant_detail'  => $blApplicantDetail,
//         'property_detail' => $propertyDetail,
//                         'seller'            => $defaultSeller
//                             ? new SellerResource($defaultSeller->load('sellerDetail'))
//                             : null,

//                             'seller_detail' => $defaultSellerDetail,
//                         'references'        => BLReference::where('lead_uuid', $lead->uuid)->get(),
//                         'reference_details' => BLReferenceDetails::where('lead_uuid', $lead->uuid)->get(),
//                     ],
//                     'Lead, applicant and references created successfully.',
//                     201,
//                     'LEAD_CREATED_SUCCESSFULLY'
//                 );
//             });
//         } catch (ValidationException $e) {
//             return $this->apiError(
//                 'Validation failed.',
//                 $e->errors(),
//                 422,
//                 'VALIDATION_ERROR'
//             );
//         } catch (JourneyDefinitionException $e) {
//             return $this->apiError(
//                 $e->getMessage(),
//                 null,
//                 $e->getStatusCode(),
//                 $e->errorCode
//             );
//         } catch (Throwable $e) {
//             return $this->apiError(
//                 'Something went wrong while creating lead.',
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
//         $query = BLLeadCreation::with(['creator', 'product']);

//         $query->when($request->lead_id, fn($q) => $q->where('lead_id', $request->lead_id));
//         $query->when($request->mobile_number, fn($q) => $q->where('mobile_number', $request->mobile_number));
//         $query->when($request->product_id, fn($q) => $q->where('product_id', $request->product_id));

//         $data = $query
//             ->orderByRaw('CAST(SUBSTRING_INDEX(lead_id, "-", -1) AS UNSIGNED) ASC')
//             ->paginate(10);

//         return new BLLeadCreationCollection($data);
//     }

//     public function show(string $lead_uuid)
//     {
//         $data = BLLeadCreation::with(['creator', 'product'])
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
//             'next_form'   => match ((int) $data->product_id) {
//                 1 => 'property_detail',
//                 4 => 'seller_detail',
//                 default => null,
//             },
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
//             'previous_stage_id' => null,
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

//     private function allowedSubLoanTypesForProduct(string $productCode): array
//     {
//         $allSubLoanTypes = array_keys(
//             config('product_configuration.sub_loan_types', [
//                 'weekly'    => 'Weekly Loan',
//                 'bi_weekly' => 'Bi-Weekly Loan',
//                 'monthly'   => 'Monthly Loan',
//                 'daily'     => 'Daily Loan',
//                 'bullet'    => 'Bullet Loan',
//                 'payday'    => 'Payday Loan',
//             ])
//         );

//         $defaultRules = [
//             'LAP' => ['monthly'],
//             'HL'  => ['monthly'],
//             'BL'  => ['weekly', 'bi_weekly', 'monthly', 'daily', 'bullet', 'payday'],
//             'PL'  => ['weekly', 'bi_weekly', 'monthly', 'daily', 'bullet', 'payday'],
//         ];

//         $rules = config('product_configuration.product_sub_loan_types', $defaultRules);
//         $allowed = $rules[strtoupper(trim($productCode))] ?? [];

//         return array_values(array_intersect($allowed, $allSubLoanTypes));
//     }

//     private function generateLeadId(string $productCode): string
//     {
//         $today = now()->format('Ymd');
//         $code = strtoupper(trim($productCode));
//         $prefix = $code . '-' . $today . '-';

//         $last = BLLeadCreation::where('lead_id', 'like', $prefix . '%')
//             ->orderByRaw('CAST(SUBSTRING_INDEX(lead_id, "-", -1) AS UNSIGNED) DESC')
//             ->value('lead_id');

//         $nextNumber = $last
//             ? (int) Str::afterLast($last, '-') + 1
//             : 1001;

//         return $prefix . $nextNumber;
//     }
// }