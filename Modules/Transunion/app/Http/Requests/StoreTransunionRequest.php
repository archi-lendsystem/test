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

    /**
     * Show Applicant + Co-applicants + Guarantors for this lead,
     * together with each person's latest TransUnion report.
     */
    public function applicantsByLead(string $leadUuid): JsonResponse
    {
        $applicants = BLApplicantDetails::query()
            ->where('lead_uuid', $leadUuid)
            ->orderByRaw("FIELD(applicant_type, 'applicant', 'Co-applicant', 'Guarantor')")
            ->orderBy('applicant_sno')
            ->get();

        if ($applicants->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No applicant, co-applicant, or guarantor was found for this lead.',
                'data' => [],
            ], Response::HTTP_NOT_FOUND);
        }

        $latestReports = Transunion::query()
            ->where('lead_uuid', $leadUuid)
            ->whereNotNull('applicant_id')
            ->orderByDesc('id')
            ->get()
            ->unique('applicant_id')
            ->keyBy('applicant_id');

        $data = $applicants->map(function (BLApplicantDetails $applicant) use ($latestReports): array {
            $report = $latestReports->get($applicant->applicant_id);

            return [
                'applicant_id' => $applicant->applicant_id,
                'applicant_sno' => $applicant->applicant_sno,
                'applicant_type' => $applicant->applicant_type,
                'full_name' => $applicant->full_name,
                'pan_card_number' => $applicant->pan_card_number,
                'mobile_number' => $applicant->alternate_contact_number,
                'date_of_birth' => $applicant->date_of_birth?->format('Y-m-d'),
                'transunion' => $report
                    ? [
                        'report_uuid' => $report->uuid,
                        'bureau_score' => $report->bureau_score,
                        'status' => $report->status,
                        'created_at' => $report->created_at?->toISOString(),
                    ]
                    : null,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Applicants fetched successfully.',
            'data' => $data,
        ], Response::HTTP_OK);
    }

    /**
     * Run TransUnion/CIBIL for one selected person.
     */
    public function store(StoreTransunionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $applicant = BLApplicantDetails::query()
            ->where('lead_uuid', $validated['lead_uuid'])
            ->where('applicant_id', $validated['applicant_id'])
            ->first();

        if ($applicant === null) {
            return response()->json([
                'success' => false,
                'message' => 'Applicant details are not available. Please complete the applicant profile first.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->transunionService->generateReport(
                $applicant,
                (int) $request->user()->id,
                $request->header('X-Idempotency-Key')
            );

            /** @var Transunion $report */
            $report = $result['report'];
            $providerBody = is_array($result['provider_body'] ?? null)
                ? $result['provider_body']
                : [];
            $vendorHttpStatus = (int) ($result['http_status'] ?? 200);

            $resource = new TransunionResource($report);

            if (in_array($report->status, ['pending', 'processing'], true)) {
                return response()->json([
                    'success' => true,
                    'message' => 'TransUnion verification is being processed. Please check again shortly.',
                    'data' => $resource,
                    'replayed' => (bool) ($result['replayed'] ?? false),
                ], Response::HTTP_ACCEPTED);
            }

            if (in_array($report->status, [
                'failed',
                'unauthorized',
                'insufficient_balance',
                'blocked',
                'validation_failed',
            ], true)) {
                return response()->json([
                    'success' => false,
                    'message' => $report->error_message
                        ?: data_get($providerBody, 'message')
                        ?: 'TransUnion verification could not be completed. Please try again.',
                    'data' => $resource,
                    'replayed' => (bool) ($result['replayed'] ?? false),
                ], $this->transunionService->portalHttpStatus($vendorHttpStatus));
            }

            if ($report->status === 'not_found') {
                return response()->json([
                    'success' => true,
                    'message' => 'TransUnion check completed, but no bureau record was found for this applicant.',
                    'data' => $resource,
                    'replayed' => (bool) ($result['replayed'] ?? false),
                ], Response::HTTP_OK);
            }

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'TransUnion report generated successfully for %s.',
                    $applicant->applicant_type ?: 'applicant'
                ),
                'data' => $resource,
                'replayed' => (bool) ($result['replayed'] ?? false),
            ], Response::HTTP_OK);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('[TransunionController] TransUnion verification failed', [
                'lead_uuid' => $validated['lead_uuid'],
                'applicant_id' => $validated['applicant_id'],
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not complete the TransUnion verification right now. Please try again.',
                'error' => app()->environment('local')
                    ? $exception->getMessage()
                    : null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Latest TransUnion report for each person under this lead.
     */
    public function reportsByLead(string $leadUuid): JsonResponse
    {
        $reports = Transunion::query()
            ->where('lead_uuid', $leadUuid)
            ->whereNotNull('applicant_id')
            ->orderByDesc('id')
            ->get()
            ->unique('applicant_id')
            ->values();

        return response()->json([
            'success' => true,
            'data' => TransunionResource::collection($reports),
        ], Response::HTTP_OK);
    }

    /**
     * View one stored report by UUID.
     */
    public function show(Transunion $transunion): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new TransunionResource($transunion),
        ], Response::HTTP_OK);
    }

    /**
     * Return the original provider PDF stored on the local disk.
     */
    public function pdf(Transunion $transunion)
    {
        if (empty($transunion->pdf_path)) {
            return response()->json([
                'success' => false,
                'message' => 'The TransUnion PDF is not available for this report.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! Storage::disk('local')->exists($transunion->pdf_path)) {
            return response()->json([
                'success' => false,
                'message' => 'The TransUnion PDF file could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $fileName = sprintf(
            'TransUnion_%s_%s.pdf',
            $transunion->applicant_type ?: 'Applicant',
            $transunion->uuid
        );

        return Storage::disk('local')->download(
            $transunion->pdf_path,
            str_replace(' ', '_', $fileName),
            ['Content-Type' => 'application/pdf']
        );
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
