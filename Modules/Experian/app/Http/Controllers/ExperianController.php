<?php

namespace Modules\Experian\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\Experian\Http\Requests\StoreExperianRequest;
use Modules\Experian\Models\Experian;
use Modules\Experian\Services\ExperianService;
use Modules\Experian\Transformers\ExperianResource;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ExperianController extends Controller
{
    public function __construct(
        private readonly ExperianService $experianService
    ) {
    }

    /**
     * Applicant + all Co-applicants +
     * all Guarantors of the lead.
     */
    public function applicantsByLead(
        string $leadUuid
    ): JsonResponse {
        $applicants =
            BLApplicantDetails::query()
                ->where(
                    'lead_uuid',
                    $leadUuid
                )
                ->orderByRaw(
                    "FIELD(
                        applicant_type,
                        'applicant',
                        'Co-applicant',
                        'Guarantor'
                    )"
                )
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
         * Latest Experian report
         * of each applicant.
         */
        $latestReports =
            Experian::query()
                ->where(
                    'lead_uuid',
                    $leadUuid
                )
                ->whereNotNull(
                    'applicant_id'
                )
                ->orderByDesc('id')
                ->get()
                ->unique(
                    'applicant_id'
                )
                ->keyBy(
                    'applicant_id'
                );

        $data =
            $applicants->map(
                function (
                    BLApplicantDetails $applicant
                ) use ($latestReports): array {
                    $report =
                        $latestReports->get(
                            $applicant
                                ->applicant_id
                        );

                    return [
                        'applicant_id' =>
                            $applicant
                                ->applicant_id,

                        'applicant_sno' =>
                            $applicant
                                ->applicant_sno,

                        'applicant_type' =>
                            $applicant
                                ->applicant_type,

                        'full_name' =>
                            $applicant
                                ->full_name,

                        'pan_card_number' =>
                            $applicant
                                ->pan_card_number,

                        'mobile_number' =>
                            $applicant
                                ->alternate_contact_number,

                        'date_of_birth' =>
                            $applicant
                                ->date_of_birth
                                ?->format(
                                    'Y-m-d'
                                ),

                        'experian' =>
                            $report
                                ? [
                                    'report_uuid' =>
                                        $report
                                            ->uuid,

                                    'bureau_score' =>
                                        $report
                                            ->bureau_score,

                                    'status' =>
                                        $report
                                            ->status,

                                    'requested_at' =>
                                        $report
                                            ->created_at
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
     * Run Experian for ONE selected applicant.
     */
    public function store(
        StoreExperianRequest $request
    ): JsonResponse {
        $validated =
            $request->validated();

        /*
         * Exact person:
         *
         * lead_uuid + applicant_id
         */
        $applicant =
            BLApplicantDetails::query()
                ->where(
                    'lead_uuid',
                    $validated[
                        'lead_uuid'
                    ]
                )
                ->where(
                    'applicant_id',
                    $validated[
                        'applicant_id'
                    ]
                )
                ->first();

        if ($applicant === null) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Applicant details not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $experian =
                $this
                    ->experianService
                    ->generateReport(
                        $applicant,

                        (int)
                            $request
                                ->user()
                                ->id,

                        $request->header(
                            'X-Idempotency-Key'
                        )
                    );

            /*
             * Provider processing.
             */
            if (
                $experian->status ===
                'pending'
            ) {
                return response()->json([
                    'success' => true,

                    'message' =>
                        'Experian report is pending reconciliation.',

                    'data' =>
                        new ExperianResource(
                            $experian
                        ),

                ], Response::HTTP_ACCEPTED);
            }

            /*
             * Provider rejected/failed.
             */
            if (
                $experian->status ===
                'failed'
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        $experian
                            ->error_message
                        ?: 'Experian request failed.',

                    'data' =>
                        new ExperianResource(
                            $experian
                        ),

                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            /*
             * Success.
             */
            return response()->json([
                'success' => true,

                'message' =>
                    $experian->status
                    === 'not_found'
                        ? 'Experian report completed. No bureau record found.'
                        : 'Experian report generated successfully.',

                'data' =>
                    new ExperianResource(
                        $experian
                    ),

            ], Response::HTTP_OK);

        } catch (
            ValidationException $exception
        ) {
            throw $exception;

        } catch (Throwable $exception) {
            Log::error(
                '[ExperianController] Experian failed',
                [
                    'lead_uuid' =>
                        $validated[
                            'lead_uuid'
                        ],

                    'applicant_id' =>
                        $validated[
                            'applicant_id'
                        ],

                    'error' =>
                        $exception
                            ->getMessage(),

                    'file' =>
                        $exception
                            ->getFile(),

                    'line' =>
                        $exception
                            ->getLine(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to perform Experian verification.',

                'error' =>
                    app()->environment(
                        'local'
                    )
                        ? $exception
                            ->getMessage()
                        : null,

            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Latest report of each applicant
     * under this lead.
     */
    public function reportsByLead(
        string $leadUuid
    ): JsonResponse {
        $reports =
            Experian::query()
                ->where(
                    'lead_uuid',
                    $leadUuid
                )
                ->whereNotNull(
                    'applicant_id'
                )
                ->orderByDesc('id')
                ->get()
                ->unique(
                    'applicant_id'
                )
                ->values();

        return response()->json([
            'success' => true,

            'data' =>
                ExperianResource::collection(
                    $reports
                ),

        ], Response::HTTP_OK);
    }

    /**
     * Single Experian report.
     */
    public function show(
        string $uuid
    ): JsonResponse {
        $report =
            Experian::query()
                ->where(
                    'uuid',
                    $uuid
                )
                ->first();

        if ($report === null) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Experian report not found.',

            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,

            'data' =>
                new ExperianResource(
                    $report
                ),

        ], Response::HTTP_OK);
    }

    /**
     * Download generated Experian PDF.
     */
    public function downloadPdf(
        string $uuid
    ) {
        $report =
            Experian::query()
                ->where(
                    'uuid',
                    $uuid
                )
                ->first();

        if ($report === null) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Experian report not found.',

            ], Response::HTTP_NOT_FOUND);
        }

        if (
            empty(
                $report->pdf_path
            )
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Experian PDF is not available.',

            ], Response::HTTP_NOT_FOUND);
        }

        $disk =
            Storage::disk(
                config(
                    'experian.disk',
                    'public'
                )
            );

        if (
            ! $disk->exists(
                $report->pdf_path
            )
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Experian PDF file was not found.',

            ], Response::HTTP_NOT_FOUND);
        }

        $fileName =
            sprintf(
                'Experian_%s_%s.pdf',

                $report
                    ->applicant_type
                    ?: 'Applicant',

                $report->uuid
            );

        return $disk->download(
            $report->pdf_path,
            $fileName,
            [
                'Content-Type' =>
                    'application/pdf',
            ]
        );
    }

    /**
     * Resolve applicant.
     *
     * Exact applicant_id when supplied.
     *
     * Otherwise:
     * 1. Main applicant
     * 2. First available applicant as fallback
     */
    private function resolveApplicant(
        string $leadUuid,
        ?int $applicantId = null
    ): BLApplicantDetails {

        if ($applicantId !== null) {

            return BLApplicantDetails::where(
                'lead_uuid',
                $leadUuid
            )
                ->where(
                    'applicant_id',
                    $applicantId
                )
                ->firstOrFail();
        }

        /*
         * Main applicant.
         */
        $applicant = BLApplicantDetails::where(
            'lead_uuid',
            $leadUuid
        )
            ->whereRaw(
                'LOWER(applicant_type) = ?',
                ['applicant']
            )
            ->orderBy('id')
            ->first();

        /*
         * Fallback if applicant_type wasn't set properly.
         */
        if (!$applicant) {
            $applicant = BLApplicantDetails::where(
                'lead_uuid',
                $leadUuid
            )
                ->orderBy('id')
                ->first();
        }

        if (!$applicant) {
            throw ValidationException::withMessages([
                'applicant_id' => [
                    'No BL Applicant Details found for this lead.',
                ],
            ]);
        }

        return $applicant;
    }

    /**
     * Applicant information for Experian screen.
     *
     * These values come directly from BL Applicant Details.
     */
    private function applicantPrefillData(
        BLLeadCreation $lead,
        BLApplicantDetails $applicant
    ): array {

        return [
            'applicant_id' =>
                $applicant->applicant_id,

            'applicant_type' =>
                $applicant->applicant_type,

            'applicant_sno' =>
                $applicant->applicant_sno,

            'full_name' =>
                $applicant->full_name,

            'gender' =>
                $applicant->gender,

            'date_of_birth' =>
                $applicant->date_of_birth,

            'email_address' =>
                $applicant->email_address,

            /*
             * Main bureau mobile remains the lead mobile.
             */
            'mobile_number' =>
                $lead->mobile_number,

            'alternate_contact_number' =>
                $applicant->alternate_contact_number,

            'current_address' =>
                $applicant->current_address,

            'current_landmark' =>
                $applicant->current_landmark,

            'current_pincode' =>
                $applicant->current_pincode,

            'current_city' =>
                $applicant->current_city,

            'current_district' =>
                $applicant->current_district,

            'current_state' =>
                $applicant->current_state,
        ];
    }
}