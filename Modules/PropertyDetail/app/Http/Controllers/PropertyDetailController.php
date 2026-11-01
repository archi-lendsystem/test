<?php

namespace Modules\PropertyDetail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\SellerDetail\Models\SellerDetail;
use Modules\BLJourney\Exceptions\JourneyDefinitionException;
use Modules\BLJourney\Services\JourneyDefinitionService;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\PropertyDetail\Models\PropertyDetail;
use RuntimeException;
use Throwable;

class PropertyDetailController extends Controller
{
    use ApiResponse;

    private const DETAIL_FIELDS = [
        'pd_collateral_usage',
        'pd_collateral_type',
        'pd_property_categorization',
        'pd_collateral_status',
        'pd_property_ownership',
        'pd_property_type',
        'pd_occupancy_status',
        'pd_property_address',
        'pd_landmark',
        'pincode',
        'district',
        'city',
        'state',
    ];

    // public function getEligiblePropertyOwners(string $lead_uuid): JsonResponse
    // {
    //     $lead = $this->propertyJourneyLead($lead_uuid);

    //     if (! $lead) {
    //         return $this->propertyLeadNotFound();
    //     }

    //     $owners = $this->eligibleApplicants($lead)
    //         ->map(fn ($applicant) => [
    //             'applicant_id' => (int) $applicant->applicant_id,
    //             'applicant_sno' => $applicant->applicant_sno,
    //             'name' => $applicant->full_name,
    //             'type' => $this->applicantType($applicant),
    //         ])
    //         ->values();

    //     return $this->apiSuccess(
    //         $owners,
    //         'Eligible property owners fetched successfully.',
    //         200,
    //         'ELIGIBLE_PROPERTY_OWNERS_FETCHED'
    //     );
    // }

    public function getEligiblePropertyOwners(string $lead_uuid): JsonResponse
{
    $lead = $this->propertyJourneyLead($lead_uuid);

    if (! $lead) {
        return $this->propertyLeadNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | LAP
    |--------------------------------------------------------------------------
    | Applicant / Co-applicant marked Property Owner = Yes
    */
    if ((int) $lead->product_id === 1) {

        $owners = $this->eligibleApplicants($lead)
            ->map(fn ($applicant) => [
                'applicant_id' => (int) $applicant->applicant_id,
                'applicant_sno' => $applicant->applicant_sno,
                'name' => $applicant->full_name,
                'type' => $this->applicantType($applicant),
            ])
            ->values();

        return $this->apiSuccess(
            $owners,
            'Eligible property owners fetched successfully.',
            200,
            'ELIGIBLE_PROPERTY_OWNERS_FETCHED'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | HL
    |--------------------------------------------------------------------------
    | All sellers having seller_name
    */
    if ((int) $lead->product_id === 4) {

        $owners = SellerDetail::query()
            ->where('lead_uuid', $lead->uuid)
            ->where('product_id', $lead->product_id)
            ->whereNotNull('seller_name')
            ->where('seller_name', '<>', '')
            ->with('seller')
            ->get()
            ->sortBy(fn ($detail) => $detail->seller?->seller_sno ?? 999999)
            ->values()
            ->map(fn ($detail) => [
                'seller_id' => (int) $detail->seller_id,
                'seller_sno' => $detail->seller?->seller_sno,
                'name' => $detail->seller_name,
                'type' => 'seller',
            ]);

        return $this->apiSuccess(
            $owners,
            'Eligible property owners fetched successfully.',
            200,
            'ELIGIBLE_PROPERTY_OWNERS_FETCHED'
        );
    }

    return $this->apiSuccess(
        [],
        'No eligible property owners found.',
        200,
        'ELIGIBLE_PROPERTY_OWNERS_FETCHED'
    );
}

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate(
                $this->detailRules(true) + [
                    'lead_uuid' => ['required', 'uuid'],
                ],
                $this->detailMessages()
            );

            $lead = $this->propertyJourneyLead($validated['lead_uuid']);

            if (! $lead) {
                return $this->propertyLeadNotFound();
            }

            if (PropertyDetail::where('lead_uuid', $lead->uuid)->exists()) {
                return $this->apiError(
                    'Property detail already exists for this lead. Use PUT to update it.',
                    null,
                    409,
                    'PROPERTY_DETAIL_ALREADY_EXISTS'
                );
            }

            $owners = $this->resolveOwners(
                $lead,
                $validated['pd_property_owner']
            );

            if ($owners['invalid_ids'] !== []) {
                return $this->invalidOwners($owners['invalid_ids']);
            }

            $paperPath = null;

            try {
                // File optional hai. JSON request bina file ke valid hai.
                if ($request->hasFile('pd_property_paper')) {
                    $paperPath = $this->uploadPropertyPaper(
                        $request->file('pd_property_paper'),
                        $lead->lead_id
                    );
                }

                $detail = DB::transaction(function () use (
                    $validated,
                    $lead,
                    $owners,
                    $paperPath
                ) {
                    $detail = new PropertyDetail();

                    $detail->fill($this->detailValues($validated));
                    $detail->lead_uuid = $lead->uuid;
                    $detail->lead_id = $lead->lead_id;
                    $detail->product_id = $lead->product_id;
                    $detail->created_by = Auth::id();
                    $detail->pd_property_owner = $owners['owners'];
                    $detail->pd_property_paper = $paperPath;

                    $detail->save();

                    return $detail;
                });

                return $this->apiSuccess(
                    $detail,
                    'Property detail created successfully.',
                    201,
                    'PROPERTY_DETAIL_CREATED'
                );
            } catch (Throwable $e) {
                if ($paperPath !== null) {
                    try {
                        Storage::disk('private')->delete($paperPath);
                    } catch (Throwable $cleanupError) {
                        report($cleanupError);
                    }
                }

                throw $e;
            }
        } catch (ValidationException $e) {
            return $this->apiError(
                'Validation failed.',
                $e->errors(),
                422,
                'VALIDATION_ERROR'
            );
        } catch (JourneyDefinitionException $e) {
            return $this->journeyDefinitionError($e);
        } catch (Throwable $e) {
            // Database error bhi yahan log hoga; use file error nahi bolenge.
            report($e);

            return $this->apiError(
                'Could not save property detail.',
                null,
                500,
                'SERVER_ERROR'
            );
        }
    }

    public function show(string $lead_uuid): JsonResponse
    {
        $lead = $this->propertyJourneyLead($lead_uuid);

        if (! $lead) {
            return $this->propertyLeadNotFound();
        }

        $detail = PropertyDetail::where('lead_uuid', $lead->uuid)->first();

        if (! $detail) {
            return $this->detailNotFound();
        }

        // Names aur eligibility hamesha applicant details se refresh karein.
        $selected = collect($detail->pd_property_owner ?? [])
            ->map(fn ($owner) => [
                'applicant_id' => $owner['applicant_id'] ?? null,
            ])
            ->all();

        $detail->pd_property_owner = $this->resolveOwners(
            $lead,
            $selected
        )['owners'];

        return $this->apiSuccess(
            $detail,
            'Property detail fetched.',
            200,
            'PROPERTY_DETAIL_FETCHED'
        );
    }

    public function document(string $lead_uuid)
    {
        $lead = $this->propertyJourneyLead($lead_uuid);

        if (! $lead) {
            abort(404, 'Lead not found.');
        }

        $detail = PropertyDetail::where('lead_uuid', $lead->uuid)->first();

        if (! $detail || empty($detail->pd_property_paper)) {
            abort(404, 'Property paper not found.');
        }

        $path = $detail->pd_property_paper;

        if (Storage::disk('private')->exists($path)) {
            return Storage::disk('private')->response($path);
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->response($path);
        }

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->response($path);
        }

        abort(404, 'Property paper file does not exist on disk.');
    }

    public function update(
        Request $request,
        string $lead_uuid
    ): JsonResponse {
        try {
            $validated = $request->validate(
                $this->detailRules(false),
                $this->detailMessages()
            );

            $lead = $this->propertyJourneyLead($lead_uuid);

            if (! $lead) {
                return $this->propertyLeadNotFound();
            }

            $detail = PropertyDetail::where(
                'lead_uuid',
                $lead->uuid
            )->first();

            if (! $detail) {
                return $this->detailNotFound();
            }

            $owners = null;

            if (array_key_exists('pd_property_owner', $validated)) {
                $owners = $this->resolveOwners(
                    $lead,
                    $validated['pd_property_owner']
                );

                if ($owners['invalid_ids'] !== []) {
                    return $this->invalidOwners(
                        $owners['invalid_ids']
                    );
                }
            }

            $newPaper = null;
            $oldPaper = $detail->pd_property_paper;

            try {
                if ($request->hasFile('pd_property_paper')) {
                    $newPaper = $this->uploadPropertyPaper(
                        $request->file('pd_property_paper'),
                        $lead->lead_id
                    );
                }

                DB::transaction(function () use (
                    $detail,
                    $validated,
                    $newPaper,
                    $owners
                ) {
                    $detail->fill(
                        $this->detailValues($validated)
                    );

                    if ($owners !== null) {
                        $detail->pd_property_owner =
                            $owners['owners'];
                    }

                    if ($newPaper !== null) {
                        $detail->pd_property_paper =
                            $newPaper;
                    }

                    $detail->save();
                });
            } catch (Throwable $e) {
                if ($newPaper !== null) {
                    try {
                        Storage::disk('private')
                            ->delete($newPaper);
                    } catch (Throwable $cleanupError) {
                        report($cleanupError);
                    }
                }

                throw $e;
            }

            if (
                $newPaper !== null
                && $oldPaper
                && $oldPaper !== $newPaper
            ) {
                try {
                    Storage::disk('private')
                        ->delete($oldPaper);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return $this->apiSuccess(
                $detail->refresh(),
                'Property detail updated successfully.',
                200,
                'PROPERTY_DETAIL_UPDATED'
            );
        } catch (ValidationException $e) {
            return $this->apiError(
                'Validation failed.',
                $e->errors(),
                422,
                'VALIDATION_ERROR'
            );
        } catch (JourneyDefinitionException $e) {
            return $this->journeyDefinitionError($e);
        } catch (Throwable $e) {
            report($e);

            return $this->apiError(
                'Could not update property detail.',
                null,
                500,
                'SERVER_ERROR'
            );
        }
    }

    public function finalValidation(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'lead_uuid' => ['required', 'uuid'],
            ]);

            $lead = $this->propertyJourneyLead(
                $validated['lead_uuid']
            );

            if (! $lead) {
                return $this->propertyLeadNotFound();
            }

            $detail = PropertyDetail::where(
                'lead_uuid',
                $lead->uuid
            )->first();

            if (! $detail) {
                return $this->detailNotFound();
            }

            $selected = collect(
                $detail->pd_property_owner ?? []
            )
                ->map(fn ($owner) => [
                    'applicant_id' =>
                        $owner['applicant_id'] ?? null,
                ])
                ->all();

            $owners = $this->resolveOwners(
                $lead,
                $selected
            );

            if (
                $selected === []
                || $owners['owners'] === []
                || $owners['invalid_ids'] !== []
            ) {
                return $this->invalidOwners(
                    $owners['invalid_ids']
                );
            }

            $missing = collect(self::DETAIL_FIELDS)
                ->filter(fn ($field) =>
                    blank($detail->{$field})
                )
                ->values()
                ->all();

            if ($missing !== []) {
                return $this->apiError(
                    'Property detail is incomplete.',
                    ['missing_fields' => $missing],
                    422,
                    'PROPERTY_DETAIL_INCOMPLETE'
                );
            }

            return $this->apiSuccess(
                [
                    'lead_uuid' => $lead->uuid,
                    'property_detail_id' => $detail->id,
                ],
                'Property detail validated successfully.',
                200,
                'PROPERTY_DETAIL_VALIDATED'
            );
        } catch (ValidationException $e) {
            return $this->apiError(
                'Validation failed.',
                $e->errors(),
                422,
                'VALIDATION_ERROR'
            );
        }
    }

    private function propertyJourneyLead(
        string $uuid
    ): ?BLLeadCreation {
        $lead = BLLeadCreation::with('product')
            ->where('uuid', $uuid)
            ->first();

        if (! $lead || ! $lead->product) {
            return null;
        }

        $journey = app(JourneyDefinitionService::class)
            ->forLead($lead);

        return in_array(
            'property',
            array_column($journey['steps'], 'key'),
            true
        ) ? $lead : null;
    }



    private function eligibleApplicants(
    BLLeadCreation $lead
) {
    return BLApplicantDetails::query()
        ->where('lead_uuid', $lead->uuid)
        ->where('product_id', $lead->product_id)

        // Sirf Yes wale property owners
        ->whereRaw(
            "LOWER(TRIM(property_owner)) = ?",
            ['yes']
        )

        // Applicant + Co-applicant
        ->where(function ($query) {
            $query
                ->whereRaw(
                    "LOWER(REPLACE(REPLACE(applicant_type, '_', '-'), ' ', '-')) IN (?, ?)",
                    ['applicant', 'co-applicant']
                )
                ->orWhere('is_co_applicant', true);
        })

        ->whereNotNull('full_name')
        ->where('full_name', '<>', '')

        ->orderBy('applicant_sno')

        ->get([
            'applicant_id',
            'applicant_sno',
            'full_name',
            'applicant_type',
            'is_co_applicant',
            'property_owner',
        ]);
}

    private function resolveOwners(
        BLLeadCreation $lead,
        array $selected
    ): array {
        $ids = collect($selected)
            ->pluck('applicant_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $eligible = $this->eligibleApplicants($lead)
            ->keyBy('applicant_id');

        $invalid = $ids
            ->reject(fn ($id) => $eligible->has($id))
            ->values()
            ->all();

        $owners = $ids
            ->filter(fn ($id) => $eligible->has($id))
            ->map(function ($id) use ($eligible) {
                $applicant = $eligible->get($id);

                return [
                    'applicant_id' =>
                        (int) $applicant->applicant_id,
                    'name' => $applicant->full_name,
                    'type' =>
                        $this->applicantType($applicant),
                ];
            })
            ->values()
            ->all();

        return [
            'owners' => $owners,
            'invalid_ids' => $invalid,
        ];
    }



    private function applicantType(
    BLApplicantDetails $applicant
): string {
    $type = strtolower(
        trim((string) $applicant->applicant_type)
    );

    $type = str_replace(
        ['_', ' '],
        '-',
        $type
    );

    if (
        $type === 'co-applicant'
        || (bool) $applicant->is_co_applicant
    ) {
        return 'co-applicant';
    }

    return 'applicant';
}

    private function detailRules(
        bool $creating
    ): array {
        $rules = [
            'pd_property_owner' => [
                $creating ? 'required' : 'sometimes',
                'array',
                'min:1',
            ],
            'pd_property_owner.*.applicant_id' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
            'pd_property_paper' => [
                'sometimes',
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:10240',
            ],
        ];

        foreach (self::DETAIL_FIELDS as $field) {
            $rules[$field] = $creating
                ? ['required', 'string']
                : ['sometimes', 'nullable', 'string'];
        }

        return $rules;
    }

    private function detailValues(
        array $validated
    ): array {
        return array_intersect_key(
            $validated,
            array_flip(self::DETAIL_FIELDS)
        );
    }

    private function detailMessages(): array
    {
        return [
            'pd_property_paper.uploaded' =>
                'The property paper could not be uploaded. Please try again.',
            'pd_property_paper.file' =>
                'The property paper must be a file.',
            'pd_property_paper.mimes' =>
                'The property paper must be a PDF, JPG, JPEG, or PNG.',
            'pd_property_paper.max' =>
                'The property paper must not exceed 10 MB.',
        ];
    }

    private function uploadPropertyPaper(
        \Illuminate\Http\UploadedFile $file,
        string $leadId
    ): string {
        $extension = strtolower(
            $file->extension()
        );

        $folder =
            'product/bl/'
            . $leadId
            . '/document/propertypaper';

        $path = $file->storeAs(
            $folder,
            'bl-property-paper-'
                . Str::uuid()
                . '.'
                . $extension,
            'private'
        );

        if ($path === false) {
            throw new RuntimeException(
                'Failed to store property paper.'
            );
        }

        return $path;
    }

    private function invalidOwners(
        array $ids
    ): JsonResponse {
        return $this->apiError(
            'Select only applicants or co-applicants marked as property owners.',
            ['invalid_applicant_ids' => $ids],
            422,
            'INVALID_PROPERTY_OWNERS'
        );
    }

    private function propertyLeadNotFound(): JsonResponse
    {
        return $this->apiError(
            'Property journey lead not found.',
            null,
            404,
            'LAP_LEAD_NOT_FOUND'
        );
    }

    private function detailNotFound(): JsonResponse
    {
        return $this->apiError(
            'Property detail not found.',
            null,
            404,
            'PROPERTY_DETAIL_NOT_FOUND'
        );
    }

    private function journeyDefinitionError(
        JourneyDefinitionException $e
    ): JsonResponse {
        return $this->apiError(
            $e->getMessage(),
            null,
            $e->getStatusCode(),
            $e->errorCode
        );
    }

    private function apiSuccess(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = 200,
        string $code = 'SUCCESS'
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'status_code' => $statusCode,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    private function apiError(
        string $message = 'Something went wrong.',
        mixed $data = null,
        int $statusCode = 400,
        string $code = 'ERROR'
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'status_code' => $statusCode,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }
}
