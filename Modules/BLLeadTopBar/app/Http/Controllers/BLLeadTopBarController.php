<?php

namespace Modules\BLLeadTopBar\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Throwable;

class BLLeadTopBarController extends Controller
{
    public function show(Request $request, string $uuid): JsonResponse
    {
        $requestId = $this->resolveRequestId($request);

        try {
            if (! $request->user()) {
                return response()->json([
                    'success' => false,
                    'status_code' => Response::HTTP_UNAUTHORIZED,
                    'request_id' => $requestId,
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                    'message' => 'Unauthorized user.',
                    'data' => null,
                ], Response::HTTP_UNAUTHORIZED);
            }

         
            $lead = BLLeadCreation::query()
                ->with([
                    'applicantDetails',
                    'currentJourney',
                    'creator',
                    'branch',
                    'schemeSelect',
                    'latestCam',
                    'disbursement',
                ])
                ->where('uuid', $uuid)
                ->first();

            if (! $lead) {
                return response()->json([
                    'success' => false,
                    'status_code' => Response::HTTP_NOT_FOUND,
                    'request_id' => $requestId,
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                    'message' => 'Lead not found.',
                    'data' => null,
                ], Response::HTTP_NOT_FOUND);
            }

            /*
             * Get the primary applicant. If it is unavailable, use the first
             * available applicant.
             */
            $applicant = $lead->applicantDetails->first(
                function ($applicant) {
                    $applicantType = strtolower(
                        trim((string) $applicant->applicant_type)
                    );

                    return ! (bool) $applicant->is_co_applicant
                        || in_array($applicantType, [
                            'applicant',
                            'primary applicant',
                            'primary',
                        ], true);
                }
            ) ?? $lead->applicantDetails->first();

          

            $creator = $lead->creator;


            $branch = $lead->branch;

            return response()->json([
                'success' => true,
                'status_code' => Response::HTTP_OK,
                'request_id' => $requestId,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'message' => 'Lead top-bar data fetched successfully.',
                'data' => [
                    'uuid' => $lead->uuid,
                    'lead_id' => $lead->lead_id,
                    'customer_name' => $applicant?->full_name,
                    'loan_amount' => $lead->loan_amount !== null
                        ? (float) $lead->loan_amount
                        : null,

                    'branch_id' => $lead->branch_id,
                    'branch_name' => $branch?->name,

                    'branch' => $branch ? [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'branch_code' => $branch->branch_code,
                    ] : null,

                    'stage' => $lead->currentJourney?->stage,

                    // Business workflow status, matching BLLeadsController.
                    'status' => $this->resolveWorkflowStatus($lead),

                    // Raw journey status is retained separately for clients
                    // that need the current journey record's value.
                    'journey_status' => $lead->currentJourney?->status,
                    'created_date' => $lead->created_at?->format('Y-m-d H:i:s'),
                    'created_by' => [
                        'id' => $creator?->id,
                        'name' => $creator?->user_name,
                    ],
                ],
            ], Response::HTTP_OK);
        } catch (Throwable $exception) {
            Log::error('BL Lead Top Bar Fetch Error', [
                'request_id' => $requestId,
                'uuid' => $uuid,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'status_code' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'request_id' => $requestId,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'message' => 'Something went wrong while fetching lead data.',
                'data' => null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Resolve the same business workflow status used by BLLeadsController.
     */
    private function resolveWorkflowStatus(BLLeadCreation $lead): string
    {
        if ($lead->disbursement !== null) {
            return 'disbursed';
        }

        if ($lead->latestCam !== null) {
            $camStatus = strtolower(trim((string) $lead->latestCam->status));

            if (in_array($camStatus, ['approve', 'approved'], true)) {
                return 'disbursement_pending';
            }

            if (in_array($camStatus, ['reject', 'rejected'], true)) {
                return 'cam_rejected';
            }

            return 'cam_approval_pending';
        }

        if ($lead->schemeSelect !== null) {
            return 'cam_pending';
        }

        $journeyStage = strtolower(
            trim((string) $lead->currentJourney?->stage)
        );

        $journeyStage = str_replace(
            [' ', '-', '/'],
            '_',
            $journeyStage
        );

        if (in_array($journeyStage, [
            'lead_submit',
            'lead_submitted',
            'submitted',
        ], true)) {
            return 'scheme_select_pending';
        }

        return strtolower(
            (string) ($lead->currentJourney?->status ?? 'draft')
        );
    }

    private function resolveRequestId(Request $request): string
    {
        if (app()->bound('request_id')) {
            return (string) app('request_id');
        }

        $headerRequestId = $request->header('X-Request-ID');

        if (! empty($headerRequestId)) {
            return (string) $headerRequestId;
        }

        return (string) Str::uuid();
    }
}
