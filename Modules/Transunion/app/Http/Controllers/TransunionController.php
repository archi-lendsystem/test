<?php

namespace Modules\Transunion\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\Transunion\Http\Requests\StoreTransunionRequest;
use Modules\Transunion\Models\Transunion;
use Modules\Transunion\Services\TransunionService;
use Modules\Transunion\Transformers\TransunionResource;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TransunionController extends Controller
{
    public function __construct(
        protected TransunionService $transunionService
    ) {
    }

    public function store(
    Request $request,
    BLLeadCreation $lead
): JsonResponse {

    /*
     * Frontend only chooses applicant and gives consent.
     *
     * Applicant information itself comes from
     * BLApplicantDetails.
     */
    $validated = $request->validate([
        'applicant_id' => [
            'nullable',
            'integer',
        ],

        'consent' => [
            'required',
            'accepted',
        ],
    ]);

    /*
     * Resolve selected applicant.
     *
     * If applicant_id is missing, main applicant
     * is selected automatically.
     */
    $applicant = $this->resolveApplicant(
        $lead->uuid,
        $validated['applicant_id'] ?? null
    );

    /*
     * Applicant name.
     */
    $fullName = trim(
        (string) $applicant->full_name
    );

    if ($fullName === '') {
        throw ValidationException::withMessages([
            'applicant_id' => [
                'Applicant full name is missing from BL Applicant Details.',
            ],
        ]);
    }

    /*
     * PAN from Applicant Details.
     */
    $pan = strtoupper(
        trim(
            (string) (
                $applicant->pan_card_number
                ?? ''
            )
        )
    );

    /*
     * Fallback:
     *
     * In your Applicant Details code,
     * pan_card_number is populated when
     * secondary_kyc_type = PAN Card.
     *
     * Still keep this fallback for older records.
     */
    if (
        $pan === ''
        && strcasecmp(
            trim(
                (string) $applicant->secondary_kyc_type
            ),
            'PAN Card'
        ) === 0
    ) {
        $pan = strtoupper(
            preg_replace(
                '/\s+/',
                '',
                trim(
                    (string)
                    $applicant->secondary_kyc_number
                )
            )
        );
    }

    if (
        $pan === ''
        || !preg_match(
            '/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            $pan
        )
    ) {
        throw ValidationException::withMessages([
            'pan' => [
                'A valid PAN number is required in BL Applicant Details before generating a TransUnion report.',
            ],
        ]);
    }

    /*
     * Bureau mobile.
     *
     * Continue using BL Lead mobile,
     * same pattern as Experian.
     */
    $mobileNumber = preg_replace(
        '/\D/',
        '',
        (string) $lead->mobile_number
    );

    if (
        empty($mobileNumber)
        || !preg_match(
            '/^[6-9][0-9]{9}$/',
            $mobileNumber
        )
    ) {
        throw ValidationException::withMessages([
            'mobile_number' => [
                'A valid 10-digit mobile number is required on the BL lead.',
            ],
        ]);
    }

    /*
     * Actual request being sent to TransUnion.
     */
    $requestPayload = [
        'full_name' =>
            $fullName,

        'mobile_number' =>
            $mobileNumber,

        'pan' =>
            $pan,

        'consent' =>
            true,
    ];

    try {

        /*
         * Call TransUnion.
         */
        $apiResponse =
            $this->transunionService
                ->generateReport(
                    $requestPayload
                );

        $body =
            $apiResponse['body']
            ?? [];

        if (!is_array($body)) {
            $body = [];
        }

        $httpStatus = (int) (
            $apiResponse['status_code']
            ?? 500
        );

        /*
         * Extract PDF before storing response.
         */
        $pdfBase64 = data_get(
            $body,
            'data.report_pdf_base64'
        );

        /*
         * DB response does NOT contain PDF base64.
         */
        $responseForDatabase =
            $this->removePdfBase64(
                $body
            );

        $reportUuid =
            (string) Str::uuid();

        /*
         * Vendor fields.
         */
        $requestId =
            data_get(
                $body,
                'request_id'
            )
            ?? data_get(
                $body,
                'data.request_id'
            );

        $referenceKey =
            data_get(
                $body,
                'ReferenceKey'
            )
            ?? data_get(
                $body,
                'data.ReferenceKey'
            );

        $productCode =
            data_get(
                $body,
                'product_code',
                'BUREAU_B'
            );

        $status =
            data_get(
                $body,
                'data.status'
            )
            ?? $this->resolveStatus(
                $httpStatus
            );

        $bureauScore =
            data_get(
                $body,
                'data.bureau_score'
            );

        $environment =
            data_get(
                $body,
                'data.environment'
            )
            ?? config(
                'transunion.environment'
            );

        $billable =
            (bool) data_get(
                $body,
                'data.billable',
                false
            );

        $reconciliationStatus =
            data_get(
                $body,
                'data.reconciliation_status'
            );

        /*
         * Save TransUnion report.
         */
        $transunion =
            Transunion::create([
                'uuid' =>
                    $reportUuid,

                'lead_uuid' =>
                    $lead->uuid,

                /*
                 * Applicant linkage.
                 */
                'applicant_id' =>
                    $applicant->applicant_id,

                'applicant_type' =>
                    $applicant->applicant_type,

                /*
                 * Snapshot of values used.
                 */
                'applicant_name' =>
                    $fullName,

                'mobile_number' =>
                    $mobileNumber,

                'pan_number' =>
                    $pan,

                /*
                 * Vendor.
                 */
                'reference_key' =>
                    $referenceKey,

                'request_id' =>
                    $requestId,

                'product_code' =>
                    $productCode,

                'status' =>
                    $status,

                'http_status' =>
                    $httpStatus,

                'bureau_score' =>
                    $bureauScore,

                'environment' =>
                    $environment,

                'billable' =>
                    $billable,

                'reconciliation_status' =>
                    $reconciliationStatus,

                /*
                 * Consent.
                 */
                'consent' =>
                    true,

                'consented_at' =>
                    now(),

                /*
                 * Native JSON columns.
                 *
                 * Pass arrays directly.
                 */
                'request_json' =>
                    $requestPayload,

                'response_json' =>
                    $responseForDatabase,
            ]);

        /*
         * Save PDF separately.
         */
        if (
            is_string($pdfBase64)
            && trim($pdfBase64) !== ''
        ) {
            try {

                $pdfPath =
                    $this->transunionService
                        ->generatePdf(
                            $pdfBase64,
                            $lead->uuid,
                            $reportUuid
                        );

                $transunion->update([
                    'pdf_path' =>
                        $pdfPath,

                    'pdf_generated_at' =>
                        now(),
                ]);

            } catch (Throwable $pdfException) {

                Log::error(
                    'TransUnion PDF generation failed',
                    [
                        'lead_uuid' =>
                            $lead->uuid,

                        'applicant_id' =>
                            $applicant->applicant_id,

                        'transunion_uuid' =>
                            $reportUuid,

                        'error' =>
                            $pdfException->getMessage(),
                    ]
                );

                report(
                    $pdfException
                );
            }
        }

        $transunion->refresh();

        return response()->json([
            'success' =>
                (bool) data_get(
                    $body,
                    'success',
                    $apiResponse['successful']
                    ?? false
                ),

            'message' =>
                data_get(
                    $body,
                    'message',
                    'TransUnion request completed.'
                ),

            'status_code' =>
                $httpStatus,

            'error_code' =>
                data_get(
                    $body,
                    'error_code'
                ),

            'request_id' =>
                $requestId,

            'reference_key' =>
                $referenceKey,

            'data' => (
                new TransunionResource(
                    $transunion
                )
            )->resolve(
                $request
            ),

        ], $this->portalHttpStatus(
            $httpStatus
        ));

    } catch (Throwable $exception) {

        Log::error(
            'TransUnion request failed',
            [
                'lead_uuid' =>
                    $lead->uuid,

                'applicant_id' =>
                    $applicant->applicant_id
                    ?? null,

                'error' =>
                    $exception->getMessage(),

                'file' =>
                    $exception->getFile(),

                'line' =>
                    $exception->getLine(),
            ]
        );

        report(
            $exception
        );

        return response()->json([
            'success' => false,

            'message' =>
                'Unable to process TransUnion report.',

            'debug' =>
                app()->environment('local')
                    ? $exception->getMessage()
                    : null,

        ], 500);
    }
}

    /**
     * Get latest TransUnion report
     * for a particular lead.
     */
    public function latest(
    Request $request,
    string $leadUuid
): JsonResponse {

    $validated = $request->validate([
        'applicant_id' => [
            'nullable',
            'integer',
        ],
    ]);

    $query =
        Transunion::where(
            'lead_uuid',
            $leadUuid
        );

    if (
        !empty(
            $validated['applicant_id']
            ?? null
        )
    ) {
        $query->where(
            'applicant_id',
            $validated['applicant_id']
        );
    }

    $transunion =
        $query
            ->latest('id')
            ->first();

    if (!$transunion) {
        return response()->json([
            'success' => false,

            'message' =>
                'No TransUnion report found.',
        ], 404);
    }

    return response()->json([
        'success' => true,

        'data' => (
            new TransunionResource(
                $transunion
            )
        )->resolve(
            $request
        ),
    ]);
}

    /**
     * Replay/fetch the vendor response through ReferenceKey.
     * This does not create a fresh bureau enquiry.
     */
    public function replay(Request $request, Transunion $transunion): JsonResponse
    {
        try {
            $result = $this->transunionService->replayReport($transunion);
            $report = $result['report'];
            $vendorHttpStatus = (int) ($result['http_status'] ?? 200);

            $success = ! in_array($report->status, [
                'failed',
                'unauthorized',
                'insufficient_balance',
                'blocked',
                'validation_failed',
            ], true);

            return response()->json([
                'success' => $success,
                'message' => $success
                    ? 'TransUnion report refreshed successfully.'
                    : ($report->error_message ?: 'Unable to refresh the TransUnion report.'),
                'data' => new TransunionResource($report),
            ], $success
                ? Response::HTTP_OK
                : $this->transunionService->portalHttpStatus($vendorHttpStatus));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('[TransunionController] replay failed', [
                'transunion_uuid' => $transunion->uuid,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not refresh the TransUnion report right now.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
