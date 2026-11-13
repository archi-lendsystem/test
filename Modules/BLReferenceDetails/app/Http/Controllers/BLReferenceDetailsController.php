<?php

namespace Modules\BLReferenceDetails\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\BLJourney\Models\BLJourney;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReference\Models\BLReference;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;
use Modules\BLReferenceDetails\Transformers\BLReferenceDetailsResource;

class BLReferenceDetailsController extends Controller
{
    use ApiResponse;

    public function update(Request $request, string $lead_uuid, int $reference_id): JsonResponse
    {
        try {
            $detail = BLReferenceDetails::where('lead_uuid', $lead_uuid)
                ->where('reference_id', $reference_id)
                ->first();

            $validated = $request->validate([
                'lead_uuid'        => $detail ? 'sometimes|nullable|string|max:255' : 'required|string|max:255',
                'reference_sno'  => $detail ? 'sometimes|nullable|integer' : 'required|integer',
                'reference_type' => $detail ? 'sometimes|nullable|string|max:255' : 'required|string|max:255',

                'full_name'      => 'required|string|max:255',
                'mobile_no'      => 'required|string|max:15',
                'email'          => 'required|email|max:255',

                'gender'         => 'required|string|max:255',
                'relationship'   => 'required|string|max:255',
                'address'        => 'required|string|max:255',
                'pincode'        => 'required|string|max:20',
                'district'       => 'required|string|max:255',
                'city'           => 'required|string|max:255',
                'state'          => 'required|string|max:255',
                'status'         => 'sometimes|nullable|string|max:255',
                'remarks'        => 'sometimes|nullable|string|max:255',
            ]);

            $validated['lead_uuid'] = $lead_uuid;
            
            $validated['reference_id'] = $reference_id;

            if ($detail) {
                $detail->update($validated);

                return $this->apiSuccess(
                    [
                        'request' => [
                            'lead_uuid' => $lead_uuid,
                            'reference_id' => $reference_id,
                        ],
                        'reference_detail' => new BLReferenceDetailsResource($detail->fresh()),
                    ],
                    'BL Reference Detail updated successfully.',
                    200,
                    'REFERENCE_DETAIL_UPDATED'
                );
            }

            $detail = BLReferenceDetails::create($validated);

            return $this->apiSuccess(
                [
                    'request' => [
                        'lead_uuid' => $lead_uuid,
                        'reference_id' => $reference_id,
                    ],
                    'reference_detail' => new BLReferenceDetailsResource($detail),
                ],
                'BL Reference Detail created successfully.',
                201,
                'REFERENCE_DETAIL_CREATED'
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->apiError(
                'Validation failed.',
                $e->errors(),
                422,
                'VALIDATION_ERROR'
            );

        } catch (Throwable $e) {
            return $this->apiError(
                'Something went wrong while saving reference detail.',
                app()->environment('local') ? $e->getMessage() : null,
                500,
                'SERVER_ERROR'
            );
        }
    }


    public function show(string $lead_uuid, int $reference_id): JsonResponse
    {
        try {
            $details = BLReferenceDetails::where('lead_uuid', $lead_uuid)
                ->where('reference_id', $reference_id)
                ->orderBy('reference_sno')
                ->get();

            if ($details->isEmpty()) {
                return $this->apiError(
                    'BL Reference Detail not found.',
                    [
                        'lead_uuid' => $lead_uuid,
                        'reference_id' => $reference_id,
                    ],
                    404,
                    'REFERENCE_DETAIL_NOT_FOUND'
                );
            }

            return $this->apiSuccess(
                [
                    'request' => [
                        'lead_uuid' => $lead_uuid,
                        'reference_id' => $reference_id,
                    ],
                    'reference_details' => BLReferenceDetailsResource::collection($details),
                ],
                'BL Reference Details retrieved successfully.',
                200,
                'REFERENCE_DETAILS_FETCHED'
            );

        } catch (Throwable $e) {
            return $this->apiError(
                'Something went wrong while fetching reference detail.',
                app()->environment('local') ? $e->getMessage() : null,
                500,
                'SERVER_ERROR'
            );
        }
    }


    public function finalValidation(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'lead_uuid' => ['required', 'uuid', 'exists:b_l_lead_creations,uuid'],
            ]);

            $leadUuid = $validated['lead_uuid'];

            $lead = BLLeadCreation::where('uuid', $leadUuid)->first();

            if (!$lead) {
                return $this->apiError(
                    'Lead not found.',
                    null,
                    404,
                    'LEAD_NOT_FOUND'
                );
            }

            $referenceDetails = BLReferenceDetails::where('lead_uuid', $leadUuid)
                ->orderBy('reference_sno')
                ->get();

            if ($referenceDetails->isEmpty()) {
                return $this->apiError(
                    'No reference details found for this lead.',
                    [
                        'lead_uuid' => $leadUuid,
                    ],
                    422,
                    'NO_REFERENCE_DETAILS_FOUND'
                );
            }

            $errors = [];
            $summary = [];

            foreach ($referenceDetails as $detail) {
                $referenceLabel = 'Reference ID: ' . $detail->reference_id;

                $fieldChecks = [
                    'full_name'    => 'Full name is required.',
                    'mobile_no'    => 'Mobile number is required.',
                    'email'        => 'Email is required.',
                    'gender'       => 'Gender is required.',
                    'relationship' => 'Relationship is required.',
                    'address'      => 'Address is required.',
                    'pincode'      => 'Pincode is required.',
                    'district'     => 'District is required.',
                    'city'         => 'City is required.',
                    'state'        => 'State is required.',
                ];

                $missingFields = [];

                foreach ($fieldChecks as $field => $message) {
                    if (!$this->isFilledValue($detail->{$field} ?? null)) {
                        $missingFields[] = $field;
                        $errors[] = "{$referenceLabel}: {$message}";
                    }
                }

                $summary[] = [
                    'reference_id' => $detail->reference_id,
                    'reference_sno' => $detail->reference_sno,
                    'reference_type' => $detail->reference_type,
                    'full_name' => $detail->full_name,
                    'mobile_no' => $detail->mobile_no,
                    'missing_fields' => $missingFields,
                    'status' => empty($missingFields) ? 'passed' : 'failed',
                ];
            }

            if (!empty($errors)) {
                return $this->apiError(
                    'Reference final validation failed.',
                    [
                        'lead_uuid' => $leadUuid,
                        'errors' => $errors,
                        'summary' => $summary,
                    ],
                    422,
                    'REFERENCE_VALIDATION_FAILED'
                );
            }

            $journey = $this->createReferenceJourneyStep($lead, $request);

            return $this->apiSuccess(
                [
                    'lead_uuid' => $leadUuid,
                    'lead_id' => $lead->lead_id,
                    'journey' => $journey,
                    'summary' => $summary,
                ],
                'Reference details validated successfully. Moving to next step.',
                200,
                'REFERENCE_DETAILS_COMPLETED'
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->apiError(
                'Validation failed.',
                $e->errors(),
                422,
                'VALIDATION_ERROR'
            );

        } catch (Throwable $e) {
            return $this->apiError(
                'Something went wrong while validating reference details.',
                app()->environment('local') ? $e->getMessage() : null,
                500,
                'SERVER_ERROR'
            );
        }
    }

    private function createReferenceJourneyStep(
        BLLeadCreation $lead,
        Request $request
    ): BLJourney {
        BLJourney::where('lead_uuid', $lead->uuid)
            ->where('is_latest', true)
            ->update(['is_latest' => false]);

        $workflowStage = null;
        $workflowStatus = null;

        if (Schema::hasTable('b_l_workflow_stages')) {
            $workflowStage = DB::table('b_l_workflow_stages')
                ->where('stage_code', 'REFERENCE_DETAILS')
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
            'lead_uuid' => $lead->uuid,
            'lead_id' => $lead->lead_id,

        
            'workflow_stage_id' => $workflowStage->id ?? null,
            'workflow_status_id' => $workflowStatus->id ?? null,
            'previous_stage_id' => null,
            'action_performed' => 'REFERENCE_DETAILS_COMPLETED',
            'performed_by_role' => $authUser->user_role
                ?? $authUser->user_designation
                ?? $authUser->user_type
                ?? null,
            'transition_type' => 'FORWARD',

      
            'stage' => $workflowStage->stage_name ?? 'Reference Details',
            'status' => $workflowStatus->status_name ?? 'Completed',
            'team' => $workflowStage->team_name ?? 'Sales',
            'action_type' => 'REFERENCE_DETAILS_COMPLETED',
            'remarks' => 'Reference details completed and validated successfully.',
            'user_id' => $authUser->id ?? null,
            'user_name' => $authUser->user_name
                ?? $authUser->name
                ?? null,
            'is_latest' => true,
            'ip_address' => $request->ip(),
            'device_info' => $request->header('User-Agent'),

        
            'created_by' => $authUser->id ?? null,
            'is_completed' => true,
        ];

        return BLJourney::create(
            $this->onlyExistingColumns('b_l_journeys', $payload)
        );
    }

    private function isFilledValue(mixed $value): bool
    {
        if (is_array($value)) {
            return count(array_filter($value, fn ($item) => $item !== null && $item !== '')) > 0;
        }

        if (is_string($value)) {
            $cleanValue = trim($value);

            return $cleanValue !== ''
                && $cleanValue !== '[]'
                && strtolower($cleanValue) !== 'null';
        }

        return !is_null($value);
    }

    private function onlyExistingColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(function ($value, $key) use ($table) {
                return Schema::hasColumn($table, $key);
            })
            ->toArray();
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
