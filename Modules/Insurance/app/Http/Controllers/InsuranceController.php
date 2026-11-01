<?php


namespace Modules\Insurance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\BLCam\Models\BLCam;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\Insurance\Models\Insurance;
use Modules\Insurance\Transformers\InsuranceResource;
use Modules\BLDisbursment\Models\BLDisbursment;
use Throwable;

class InsuranceController extends Controller
{

    private function isLeadDisbursed(string $lead_uuid): bool
    {
        return BLDisbursment::query()
            ->where('lead_uuid', $lead_uuid)
            ->exists();
    }
    public function show(
        Request $request,
        string $lead_uuid
    ): JsonResponse {
        $requestId = $this->getRequestId($request);

        try {
            $leadExists = BLLeadCreation::query()
                ->where('uuid', $lead_uuid)
                ->exists();

            if (! $leadExists) {
                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'Lead not found.',
                    data: null,
                    statusCode: 404
                );
            }

            $insurance = Insurance::query()
                ->where('lead_uuid', $lead_uuid)
                ->first();

            $insuranceExists = $insurance !== null;

            if (! $insurance) {
                $insurance = new Insurance();

                $insurance->forceFill([
                    'id'                   => null,
                    'lead_uuid'            => $lead_uuid,
                    'insurance_percentage' => null,
                    'insurance_amount'     => null,
                    'created_at'           => null,
                    'updated_at'           => null,
                ]);
            }

            $insurance->load([
                'cam',
                'applicantDetails',
                'latestSanction',
            ]);

            $data = (new InsuranceResource($insurance))
                ->resolve($request);

            if (! $insuranceExists) {
                $data['id']                   = null;
                $data['insurance_percentage'] = null;
                $data['insurance_amount']     = null;
                $data['created_at']           = null;
                $data['updated_at']           = null;
            }

            Log::channel('bl_audit')->info(
                'Insurance summary fetched successfully.',
                [
                    'request_id'      => $requestId,
                    'lead_uuid'       => $lead_uuid,
                    'insurance_found' => $insuranceExists,
                ]
            );

            return $this->apiResponse(
                request: $request,
                success: true,
                message: $insuranceExists
                    ? 'Insurance details fetched successfully.'
                    : 'Insurance not available. Applicant and sanction details fetched successfully.',
                data: $data,
                statusCode: 200
            );
        } catch (Throwable $e) {
            Log::channel('bl_audit')->error(
                'Failed to fetch insurance summary.',
                [
                    'request_id' => $requestId,
                    'lead_uuid'  => $lead_uuid,
                    'error'      => $e->getMessage(),
                    'file'       => $e->getFile(),
                    'line'       => $e->getLine(),
                ]
            );

            return $this->apiResponse(
                request: $request,
                success: false,
                message: 'Something went wrong while fetching insurance details.',
                data: null,
                statusCode: 500
            );
        }
    }

    public function store(
        Request $request,
        string $lead_uuid
    ): JsonResponse {
        $requestId = $this->getRequestId($request);

        try {
            $validated = $request->validate(
                [
                    'insurance_percentage' => [
                        'required',
                        'numeric',
                        'min:0',
                        'max:100',
                    ],
                ],
                [
                    'insurance_percentage.required' =>
                    'Insurance percentage is required.',

                    'insurance_percentage.numeric' =>
                    'Insurance percentage must be numeric.',

                    'insurance_percentage.min' =>
                    'Insurance percentage cannot be less than 0.',

                    'insurance_percentage.max' =>
                    'Insurance percentage cannot be greater than 100.',
                ]
            );

            $leadExists = BLLeadCreation::query()
                ->where('uuid', $lead_uuid)
                ->exists();

            if (! $leadExists) {
                Log::channel('bl_audit')->warning(
                    'Lead not found while creating insurance.',
                    [
                        'request_id' => $requestId,
                        'lead_uuid'  => $lead_uuid,
                    ]
                );

                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'Lead not found.',
                    data: null,
                    statusCode: 404
                );
            }

            if ($this->isLeadDisbursed($lead_uuid)) {
                Log::channel('bl_audit')->warning(
                    'Insurance creation blocked because lead is already disbursed.',
                    [
                        'request_id' => $requestId,
                        'lead_uuid'  => $lead_uuid,
                    ]
                );

                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'Insurance cannot be created because this lead has already been disbursed.',
                    data: null,
                    statusCode: 422
                );
            }

            $existingInsurance = Insurance::query()
                ->where('lead_uuid', $lead_uuid)
                ->first();

            if ($existingInsurance) {
                Log::channel('bl_audit')->warning(
                    'Insurance already exists for this lead.',
                    [
                        'request_id'   => $requestId,
                        'insurance_id' => $existingInsurance->id,
                        'lead_uuid'    => $lead_uuid,
                    ]
                );

                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'Insurance already exists for this lead. Please use the update API.',
                    data: null,
                    statusCode: 409
                );
            }

            $cam = $this->getLatestCam($lead_uuid);

            if (! $cam) {
                Log::channel('bl_audit')->warning(
                    'CAM details not found while creating insurance.',
                    [
                        'request_id' => $requestId,
                        'lead_uuid'  => $lead_uuid,
                    ]
                );

                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'CAM details not found for this lead.',
                    data: null,
                    statusCode: 404
                );
            }

            if ($cam->loan_amount === null) {
                Log::channel('bl_audit')->warning(
                    'Loan amount is not available in CAM.',
                    [
                        'request_id' => $requestId,
                        'lead_uuid'  => $lead_uuid,
                        'cam_id'     => $cam->id,
                    ]
                );

                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'Loan amount is not available in CAM details.',
                    data: null,
                    statusCode: 422
                );
            }

            $loanAmount = (float) $cam->loan_amount;
            $percentage = (float) $validated['insurance_percentage'];

            $insuranceAmount = $this->calculateInsuranceAmount(
                $loanAmount,
                $percentage,
                $lead_uuid
            );

            $insurance = DB::transaction(
                function () use (
                    $lead_uuid,
                    $percentage,
                    $insuranceAmount
                ) {
                    return Insurance::query()->create([
                        'lead_uuid'            => $lead_uuid,
                        'insurance_percentage' => $percentage,
                        'insurance_amount'     => $insuranceAmount,
                    ]);
                }
            );

            $insurance->load([
                'cam',
                'applicantDetails',
                'latestSanction',
            ]);

            Log::channel('bl_audit')->info(
                'Insurance details created successfully.',
                [
                    'request_id'           => $requestId,
                    'insurance_id'         => $insurance->id,
                    'lead_uuid'            => $lead_uuid,
                    'loan_amount'          => $loanAmount,
                    'insurance_percentage' => $percentage,
                    'insurance_amount'     => $insuranceAmount,
                ]
            );

            return $this->apiResponse(
                request: $request,
                success: true,
                message: 'Insurance details created successfully.',
                data: (new InsuranceResource($insurance))
                    ->resolve($request),
                statusCode: 201
            );
        } catch (ValidationException $e) {
            Log::channel('bl_audit')->warning(
                'Insurance validation failed.',
                [
                    'request_id' => $requestId,
                    'lead_uuid'  => $lead_uuid,
                    'errors'     => $e->errors(),
                ]
            );

            return $this->apiResponse(
                request: $request,
                success: false,
                message: 'Validation failed.',
                data: null,
                statusCode: 422,
                errors: $e->errors()
            );
        } catch (Throwable $e) {
            Log::channel('bl_audit')->error(
                'Failed to create insurance details.',
                [
                    'request_id' => $requestId,
                    'lead_uuid'  => $lead_uuid,
                    'error'      => $e->getMessage(),
                    'file'       => $e->getFile(),
                    'line'       => $e->getLine(),
                ]
            );

            return $this->apiResponse(
                request: $request,
                success: false,
                message: 'Something went wrong while creating insurance details.',
                data: null,
                statusCode: 500
            );
        }
    }

    public function update(
        Request $request,
        string $lead_uuid
    ): JsonResponse {
        $requestId = $this->getRequestId($request);

        try {
            $validated = $request->validate(
                [
                    'insurance_percentage' => [
                        'required',
                        'numeric',
                        'min:0',
                        'max:100',
                    ],
                ],
                [
                    'insurance_percentage.required' =>
                    'Insurance percentage is required.',

                    'insurance_percentage.numeric' =>
                    'Insurance percentage must be numeric.',

                    'insurance_percentage.min' =>
                    'Insurance percentage cannot be less than 0.',

                    'insurance_percentage.max' =>
                    'Insurance percentage cannot be greater than 100.',
                ]
            );

            $insurance = Insurance::query()
                ->where('lead_uuid', $lead_uuid)
                ->first();

            if (! $insurance) {
                Log::channel('bl_audit')->warning(
                    'Insurance not found while updating.',
                    [
                        'request_id' => $requestId,
                        'lead_uuid'  => $lead_uuid,
                    ]
                );

                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'Insurance details not found for this lead.',
                    data: null,
                    statusCode: 404
                );
            }

            if ($this->isLeadDisbursed($lead_uuid)) {
                Log::channel('bl_audit')->warning(
                    'Insurance update blocked because lead is already disbursed.',
                    [
                        'request_id'   => $requestId,
                        'insurance_id' => $insurance->id,
                        'lead_uuid'    => $lead_uuid,
                    ]
                );

                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'Insurance cannot be updated because this lead has already been disbursed.',
                    data: null,
                    statusCode: 422
                );
            }

            $cam = $this->getLatestCam($lead_uuid);

            if (! $cam) {
                Log::channel('bl_audit')->warning(
                    'CAM details not found while updating insurance.',
                    [
                        'request_id' => $requestId,
                        'lead_uuid'  => $lead_uuid,
                    ]
                );

                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'CAM details not found for this lead.',
                    data: null,
                    statusCode: 404
                );
            }

            if ($cam->loan_amount === null) {
                Log::channel('bl_audit')->warning(
                    'Loan amount is not available while updating insurance.',
                    [
                        'request_id' => $requestId,
                        'lead_uuid'  => $lead_uuid,
                        'cam_id'     => $cam->id,
                    ]
                );

                return $this->apiResponse(
                    request: $request,
                    success: false,
                    message: 'Loan amount is not available in CAM details.',
                    data: null,
                    statusCode: 422
                );
            }

            $loanAmount = (float) $cam->loan_amount;
            $percentage = (float) $validated['insurance_percentage'];

            $insuranceAmount = $this->calculateInsuranceAmount(
                $loanAmount,
                $percentage,
                $lead_uuid
            );

            DB::transaction(
                function () use (
                    $insurance,
                    $percentage,
                    $insuranceAmount
                ) {
                    $insurance->update([
                        'insurance_percentage' => $percentage,
                        'insurance_amount'      => $insuranceAmount,
                    ]);
                }
            );

            $insurance->refresh();
            $insurance->load([
                'cam',
                'applicantDetails',
                'latestSanction',
            ]);

            Log::channel('bl_audit')->info(
                'Insurance details updated successfully.',
                [
                    'request_id'           => $requestId,
                    'insurance_id'         => $insurance->id,
                    'lead_uuid'            => $lead_uuid,
                    'loan_amount'          => $loanAmount,
                    'insurance_percentage' => $percentage,
                    'insurance_amount'     => $insuranceAmount,
                ]
            );

            return $this->apiResponse(
                request: $request,
                success: true,
                message: 'Insurance details updated successfully.',
                data: (new InsuranceResource($insurance))
                    ->resolve($request),
                statusCode: 200
            );
        } catch (ValidationException $e) {
            Log::channel('bl_audit')->warning(
                'Insurance update validation failed.',
                [
                    'request_id' => $requestId,
                    'lead_uuid'  => $lead_uuid,
                    'errors'     => $e->errors(),
                ]
            );

            return $this->apiResponse(
                request: $request,
                success: false,
                message: 'Validation failed.',
                data: null,
                statusCode: 422,
                errors: $e->errors()
            );
        } catch (Throwable $e) {
            Log::channel('bl_audit')->error(
                'Failed to update insurance details.',
                [
                    'request_id' => $requestId,
                    'lead_uuid'  => $lead_uuid,
                    'error'      => $e->getMessage(),
                    'file'       => $e->getFile(),
                    'line'       => $e->getLine(),
                ]
            );

            return $this->apiResponse(
                request: $request,
                success: false,
                message: 'Something went wrong while updating insurance details.',
                data: null,
                statusCode: 500
            );
        }
    }

    private function getLatestCam(string $lead_uuid): ?BLCam
    {
        return BLCam::query()
            ->where('lead_uuid', $lead_uuid)
            ->latest('id')
            ->first();
    }

    private function calculateInsuranceAmount(
        float $loanAmount,
        float $percentage,
        string $lead_uuid
    ): float {
        $baseAmount = round(
            ($loanAmount * $percentage) / 100,
            2
        );

        $coApplicantCount = BLApplicant::query()
            ->where('lead_uuid', $lead_uuid)
            ->where('applicant_types', 'Co-applicant')
            ->count();

        if ($coApplicantCount > 0) {
            return round(
                $baseAmount * (1 + $coApplicantCount),
                2
            );
        }

        return $baseAmount;
    }

    private function getRequestId(Request $request): string
    {
        $requestId = $request->attributes->get('request_id');

        if ($requestId) {
            return (string) $requestId;
        }

        $requestId = $request->header('X-Request-ID')
            ?: (string) Str::uuid();

        $request->attributes->set(
            'request_id',
            $requestId
        );

        return $requestId;
    }

    private function apiResponse(
        Request $request,
        bool $success,
        string $message,
        mixed $data,
        int $statusCode,
        ?array $errors = null
    ): JsonResponse {
        $response = [
            'success'     => $success,
            'message'     => $message,
            'status_code' => $statusCode,
            'request_id'  => $this->getRequestId($request),
            'timestamp'   => now()->toIso8601String(),
            'data'        => $data,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()
            ->json($response, $statusCode)
            ->header(
                'Cache-Control',
                'no-store, no-cache, must-revalidate, max-age=0'
            )
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
