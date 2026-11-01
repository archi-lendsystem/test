<?php

namespace Modules\Crif\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\Crif\Models\CrifReport;
use Modules\Crif\Services\CrifService;
use Symfony\Component\HttpFoundation\Response;
use Modules\Crif\Http\Requests\StoreCrifRequest;
use Modules\Crif\Transformers\CrifResource;
use Barryvdh\DomPDF\Facade\Pdf;

class CrifController extends Controller
{
    protected CrifService $crifService;

    public function __construct(CrifService $crifService)
    {
        $this->crifService = $crifService;
    }

// public function applicantByLead(
//     Request $request,
//     string $leadUuid
// ): JsonResponse {
//     /*
//      * CRIF primary applicant is always applicant_sno = 0.
//      */
//     $applicant = BLApplicantDetails::query()
//         ->where(
//             'lead_uuid',
//             $leadUuid
//         )
//         ->where(
//             'applicant_sno',
//             0
//         )
//         ->first();

//     if ($applicant === null) {
//         return response()->json([
//             'success' => false,

//             'message' =>
//                 'Primary applicant not found for this lead.',

//             'error_code' =>
//                 'PRIMARY_APPLICANT_NOT_FOUND',

//         ], Response::HTTP_NOT_FOUND);
//     }

//     return response()->json([
//         'success' => true,

//         'message' =>
//             'Primary applicant fetched successfully.',

//         'data' => [
//             'applicant_id' =>
//                 $applicant->applicant_id,

//             'applicant_sno' =>
//                 $applicant->applicant_sno,

//             'full_name' =>
//                 $applicant->full_name,

//             'pan_card_number' =>
//                 $applicant->pan_card_number,

//             /*
//              * CRIF calls this mobile_number.
//              */
//             'mobile_number' =>
//                 $applicant->alternate_contact_number,

//             /*
//              * Keep original field also if other
//              * frontend code needs it.
//              */
//             'alternate_contact_number' =>
//                 $applicant->alternate_contact_number,

//             'date_of_birth' =>
//                 $applicant
//                     ->date_of_birth
//                     ?->format('Y-m-d'),
//         ],
//     ], Response::HTTP_OK);
// }


public function store(
    StoreCrifRequest $request
): JsonResponse {
    $validated =
        $request->validated();

    /*
     * Exact person find karo:
     *
     * lead_uuid + applicant_id
     */
    $applicant = BLApplicantDetails::query()
        ->where(
            'lead_uuid',
            $validated['lead_uuid']
        )
        ->where(
            'applicant_id',
            $validated['applicant_id']
        )
        ->first();

    if ($applicant === null) {
        return response()->json([
            'success' => false,

            'message' =>
                'Applicant details not found.',
        ], Response::HTTP_NOT_FOUND);
    }

    /*
     * Ensure required CRIF identity exists.
     */
    if (empty($applicant->full_name)) {
        return response()->json([
            'success' => false,

            'message' =>
                'Applicant full name is required.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    if (empty($applicant->pan_card_number)) {
        return response()->json([
            'success' => false,

            'message' =>
                'Applicant PAN is required.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    if (
        empty(
            $applicant
                ->alternate_contact_number
        )
    ) {
        return response()->json([
            'success' => false,

            'message' =>
                'Applicant mobile number is required.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    try {
       $result =
    $this
        ->crifService
        ->executeBureauCheck(
            $applicant,
            (int) $request->user()->id,
            $request->header(
                'X-Idempotency-Key'
            )
        );

if (
    ($result['http_status'] ?? 200)
    === Response::HTTP_CONFLICT
) {
    return response()->json([
        'success' => false,
        'message' =>
            $result['message']
            ?? 'Idempotency conflict.',
    ], Response::HTTP_CONFLICT);
}

$report = $result['report'];

if ($report === null) {
    return response()->json([
        'success' => false,
        'message' =>
            'CRIF did not return a report.',
    ], Response::HTTP_BAD_GATEWAY);
}

/*
|--------------------------------------------------------------------------
| CRIF failed
|--------------------------------------------------------------------------
*/

if (
    $report->verification_status === 'failed'
) {
    return response()->json([
        'success' => false,

        'message' =>
            $report->error_message
            ?: 'CRIF verification failed.',

        'data' =>
            new CrifResource($report),

        'replayed' =>
            $result['replayed'] ?? false,

    ], Response::HTTP_UNPROCESSABLE_ENTITY);
}

/*
|--------------------------------------------------------------------------
| CRIF still processing
|--------------------------------------------------------------------------
*/

if (
    $report->verification_status === 'pending'
) {
    return response()->json([
        'success' => true,

        'message' =>
            'CRIF verification is processing.',

        'data' =>
            new CrifResource($report),

        'replayed' =>
            $result['replayed'] ?? false,

    ], Response::HTTP_ACCEPTED);
}

/*
|--------------------------------------------------------------------------
| CRIF completed
|--------------------------------------------------------------------------
*/

return response()->json([
    'success' => true,

    'message' =>
        'CRIF verification completed successfully.',

    'data' =>
        new CrifResource($report),

    'replayed' =>
        $result['replayed'] ?? false,

], Response::HTTP_OK);

    } catch (
        \Illuminate\Validation\ValidationException $exception
    ) {
        throw $exception;

    } catch (\Throwable $exception) {

    Log::error('[CrifController] CRIF failed', [
        'lead_uuid' => $validated['lead_uuid'],
        'applicant_id' => $validated['applicant_id'],
        'error' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ]);

    return response()->json([
        'success' => false,
        'message' => 'Unable to perform CRIF verification.',

        // LOCAL DEBUG ONLY
        'error' => app()->environment('local')
            ? $exception->getMessage()
            : null,

        'file' => app()->environment('local')
            ? $exception->getFile()
            : null,

        'line' => app()->environment('local')
            ? $exception->getLine()
            : null,

    ], Response::HTTP_INTERNAL_SERVER_ERROR);
}
}


public function applicantsByLead(
    string $leadUuid
): JsonResponse {
    $applicants = BLApplicantDetails::query()
        ->where(
            'lead_uuid',
            $leadUuid
        )
        ->orderByRaw("
            FIELD(
                applicant_type,
                'applicant',
                'Co-applicant',
                'Guarantor'
            )
        ")
        ->orderBy(
            'applicant_sno'
        )
        ->get();

    if ($applicants->isEmpty()) {
        return response()->json([
            'success' => false,
            'message' =>
                'No applicants found for this lead.',
            'data' => [],
        ], Response::HTTP_NOT_FOUND);
    }

    /*
     * Get latest CRIF report of each applicant.
     */
    $latestReports = CrifReport::query()
        ->where(
            'lead_uuid',
            $leadUuid
        )
        ->whereNotNull(
            'applicant_id'
        )
        ->orderByDesc('id')
        ->get()
        ->unique('applicant_id')
        ->keyBy('applicant_id');

    $data = $applicants->map(
        function (
            BLApplicantDetails $applicant
        ) use ($latestReports): array {
            $report = $latestReports->get(
                $applicant->applicant_id
            );

            return [
                'applicant_id' =>
                    $applicant->applicant_id,

                'applicant_sno' =>
                    $applicant->applicant_sno,

                'applicant_type' =>
                    $applicant->applicant_type,

                'full_name' =>
                    $applicant->full_name,

                'pan_card_number' =>
                    $applicant->pan_card_number,

                'mobile_number' =>
                    $applicant->alternate_contact_number,

                'date_of_birth' =>
                    $applicant
                        ->date_of_birth
                        ?->format('Y-m-d'),

                'crif' => $report
                    ? [
                        'report_uuid' =>
                            $report->uuid,

                        'bureau_score' =>
                            $report->bureau_score,

                        'verification_status' =>
                            $report->verification_status,

                        'requested_at' =>
                            $report
                                ->requested_at
                                ?->toISOString(),
                    ]
                    : null,
            ];
        }
    );

    return response()->json([
        'success' => true,

        'message' =>
            'Applicants fetched successfully.',

        'data' =>
            $data,
    ], Response::HTTP_OK);
}

    /**
     * Retrieve applicant identity details using lead_uuid.
     */
    public function getApplicantDetails(Request $request, string $leadUuid): JsonResponse
    {
        try {
            $query = BLApplicantDetails::where('lead_uuid', $leadUuid);

            if ($request->filled('applicant_id')) {
                $query->where('applicant_id', $request->query('applicant_id'));
            } else {
                $query->where('is_co_applicant', false);
            }

            $applicant = $query->first();

            if (! $applicant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Applicant details not found for lead UUID: ' . $leadUuid,
                ], Response::HTTP_404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id'                       => $applicant->id,
                    'lead_uuid'                => $applicant->lead_uuid,
                    'applicant_id'             => $applicant->applicant_id,
                    'is_co_applicant'          => (bool) $applicant->is_co_applicant,
                    'full_name'                => $applicant->full_name,
                    'pan_card_number'          => $applicant->pan_card_number,
                    'aadhaar_card_number'      => $applicant->aadhaar_card_number,
                    'date_of_birth'            => $applicant->date_of_birth?->format('Y-m-d'),
                    'gender'                   => $applicant->gender,
                    'current_pincode'          => $applicant->current_pincode,
                    'current_city'             => $applicant->current_city,
                    'current_state'            => $applicant->current_state,
                    'current_address'          => $applicant->current_address,
                    'alternate_contact_number' => $applicant->alternate_contact_number,
                    'email_address'            => $applicant->email_address,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[CrifController] getApplicantDetails failed', [
                'lead_uuid' => $leadUuid,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch applicant details.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Run bureau verification for the given lead.
     */
    public function runBureau(Request $request, string $leadUuid): JsonResponse
    {
        $validated = $request->validate([
            'bureau_id'       => 'nullable|string|in:crif,experian,transunion',
            'applicant_id'    => 'nullable|string',
            'idempotency_key' => 'nullable|string|max:255',
        ]);

        try {
            $query = BLApplicantDetails::where('lead_uuid', $leadUuid);

            if (! empty($validated['applicant_id'])) {
                $query->where('applicant_id', $validated['applicant_id']);
            } else {
                $query->where('is_co_applicant', false);
            }

            $applicant = $query->first();

            if (! $applicant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Applicant not found for lead UUID: ' . $leadUuid,
                ], Response::HTTP_404);
            }

            if (empty($applicant->pan_card_number)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Valid PAN Card Number is required before running bureau check.',
                ], Response::HTTP_422);
            }

            $userId = (int) ($request->user()?->id ?? 1);
            $idempotencyKey = $validated['idempotency_key'] ?? $request->header('X-Idempotency-Key');

            $result = $this->crifService->executeBureauCheck($applicant, $userId, $idempotencyKey);

            if (($result['http_status'] ?? 200) === 409) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], Response::HTTP_CONFLICT);
            }

            return response()->json([
                'success'  => true,
                'message'  => 'Bureau check initiated successfully.',
                'data'     => $result['report'],
                'replayed' => $result['replayed'] ?? false,
            ], $result['http_status'] ?? Response::HTTP_OK);

        } catch (\Throwable $e) {
            Log::error('[CrifController] runBureau error', [
                'lead_uuid' => $leadUuid,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * View latest CRIF report details.
     */
    public function viewReport(string $leadUuid, string $bureauId): JsonResponse
    {
        $report = CrifReport::where('lead_uuid', $leadUuid)->latest()->first();

        if (! $report) {
            return response()->json([
                'success' => false,
                'message' => "No {$bureauId} report record found for this lead.",
            ], Response::HTTP_404);
        }

        return response()->json([
            'success' => true,
            'data'    => $report,
        ]);
    }

    /**
     * Download CRIF report document.
     */
    public function downloadReport(string $leadUuid, string $bureauId)
    {
        $report = CrifReport::where('lead_uuid', $leadUuid)->latest()->first();

        if (! $report || empty($report->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Report document not available for download.',
            ], Response::HTTP_404);
        }

        $disk = Storage::disk('private');
        if (! $disk->exists($report->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found on storage disk.',
            ], Response::HTTP_404);
        }

        return response()->download($disk->path($report->file_path));
    }


    public function reportsByLead(
    string $leadUuid
): JsonResponse {
    $reports = CrifReport::query()
        ->where(
            'lead_uuid',
            $leadUuid
        )
        ->whereNotNull(
            'applicant_id'
        )
        ->orderByDesc('id')
        ->get()
        ->unique('applicant_id')
        ->values();

    return response()->json([
        'success' => true,

        'data' =>
            CrifResource::collection(
                $reports
            ),
    ]);
}

public function show(
    string $uuid
): JsonResponse {
    $report = CrifReport::query()
        ->where(
            'uuid',
            $uuid
        )
        ->first();

    if ($report === null) {
        return response()->json([
            'success' => false,
            'message' =>
                'CRIF report not found.',
        ], Response::HTTP_NOT_FOUND);
    }

    return response()->json([
        'success' => true,

        'data' =>
            new CrifResource(
                $report
            ),
    ]);
}

public function downloadPdf(
    string $uuid
)
{
    $report = CrifReport::query()
        ->where('uuid', $uuid)
        ->first();

    if ($report === null) {
        return response()->json([
            'success' => false,
            'message' => 'CRIF report not found.',
        ], Response::HTTP_NOT_FOUND);
    }

    $pdf = Pdf::loadView(
        'crif::reports.crif-report',
        [
            'report' => $report,
        ]
    );

    $fileName = sprintf(
        'CRIF_%s_%s.pdf',
        $report->applicant_type ?? 'Applicant',
        $report->uuid
    );

    return $pdf->download($fileName);
}
}