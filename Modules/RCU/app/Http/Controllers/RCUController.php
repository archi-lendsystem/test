<?php

namespace Modules\RCU\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Modules\BLDocumentUpload\Models\BLDocumentUpload;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLWorkflow\Services\BLWorkflowService;
use Modules\RCU\Models\RcuReview;
use Throwable;

class RCUController extends Controller
{
    public function __construct(
        private readonly BLWorkflowService $workflowService
    ) {}

    /**
     * Every document uploaded for this lead, with its verification status,
     * plus a summary RCU uses to decide whether the case is ready to
     * approve (every document must be VERIFIED — none PENDING/REJECTED).
     */
    public function documents(string $leadUuid): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $leadUuid)->first();

        if (!$lead) {
            return $this->error('Lead not found.', 404);
        }

        $documents = BLDocumentUpload::query()
            ->with(['checklist', 'group', 'checklistDocument', 'verifiedBy:id,user_name'])
            ->where('lead_id', $lead->lead_id)
            ->orderBy('document_group')
            ->orderBy('document_name')
            ->get()
            ->map(function (BLDocumentUpload $document) {
                $document->view_url = $document->document
                    ? URL::temporarySignedRoute('api.bldocumentuploads.view', now()->addMinutes(30), ['upload_uuid' => $document->uuid])
                    : null;

                return $document;
            });

        $summary = [
            'total' => $documents->count(),
            'verified' => $documents->where('verification_status', 'VERIFIED')->count(),
            'rejected' => $documents->where('verification_status', 'REJECTED')->count(),
            'pending' => $documents->where('verification_status', 'PENDING')->count(),
        ];

        $review = RcuReview::where('lead_uuid', $leadUuid)->latest('id')->first();

        return response()->json([
            'success' => true,
            'message' => 'Documents fetched successfully.',
            'data' => [
                'lead_uuid' => $leadUuid,
                'lead_id' => $lead->lead_id,
                'summary' => $summary,
                'documents' => $documents,
                'review' => $review,
                'can_approve' => $summary['total'] > 0 && $summary['verified'] === $summary['total'],
            ],
        ]);
    }

    /**
     * Mark one uploaded document as VERIFIED or REJECTED.
     */
    public function verifyDocument(Request $request, string $leadUuid, string $uploadUuid): JsonResponse
    {
        $document = BLDocumentUpload::where('uuid', $uploadUuid)->first();

        if (!$document) {
            return $this->error('Document not found.', 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['VERIFIED', 'REJECTED'])],
            'remarks' => 'required_if:status,REJECTED|nullable|string|max:1000',
        ]);

        $document->update([
            'verification_status' => $validated['status'],
            'verified_by' => optional($request->user())->id,
            'verified_at' => now(),
            'rejection_remarks' => $validated['status'] === 'REJECTED' ? ($validated['remarks'] ?? null) : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Document marked as {$validated['status']}.",
            'data' => $document->fresh(['verifiedBy:id,user_name']),
        ]);
    }

    /**
     * RCU signs off: every document must already be VERIFIED. Records the
     * decision and best-effort logs a workflow journey entry — a hiccup in
     * the shared workflow tracker must never block the RCU decision itself.
     */
    public function approve(Request $request, string $leadUuid): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $leadUuid)->first();

        if (!$lead) {
            return $this->error('Lead not found.', 404);
        }

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:2000',
        ]);

        $documents = BLDocumentUpload::where('lead_id', $lead->lead_id)->get();

        if ($documents->isEmpty()) {
            return $this->error('No documents uploaded for this lead.', 422);
        }

        $notVerified = $documents->where('verification_status', '!=', 'VERIFIED')->count();

        if ($notVerified > 0) {
            return $this->error("{$notVerified} document(s) still need to be verified before the case can be approved.", 422);
        }

        $review = RcuReview::updateOrCreate(
            ['lead_uuid' => $leadUuid],
            [
                'status' => 'APPROVED',
                'remarks' => $validated['remarks'] ?? null,
                'reviewed_by' => optional($request->user())->id,
                'reviewed_at' => now(),
            ]
        );

        $this->logWorkflowJourney($leadUuid, $request, $validated['remarks'] ?? 'RCU approved — all documents verified.');

        return response()->json([
            'success' => true,
            'message' => 'Case approved by RCU.',
            'data' => $review->fresh(),
        ]);
    }

    /**
     * RCU sends the case back — doesn't require every document to be
     * verified (that's often exactly why it's being rejected).
     */
    public function reject(Request $request, string $leadUuid): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $leadUuid)->first();

        if (!$lead) {
            return $this->error('Lead not found.', 404);
        }

        $validated = $request->validate([
            'remarks' => 'required|string|min:5|max:2000',
        ]);

        $review = RcuReview::updateOrCreate(
            ['lead_uuid' => $leadUuid],
            [
                'status' => 'REJECTED',
                'remarks' => $validated['remarks'],
                'reviewed_by' => optional($request->user())->id,
                'reviewed_at' => now(),
            ]
        );

        $this->logWorkflowJourney($leadUuid, $request, 'RCU rejected: ' . $validated['remarks']);

        return response()->json([
            'success' => true,
            'message' => 'Case rejected by RCU.',
            'data' => $review->fresh(),
        ]);
    }

    private function logWorkflowJourney(string $leadUuid, Request $request, string $remarks): void
    {
        try {
            $currentData = $this->workflowService->getLeadCurrentData($leadUuid);

            if ($currentData['current_status']) {
                $this->workflowService->changeStatus(
                    $leadUuid,
                    $currentData['current_status']->status_code,
                    $request->user(),
                    $remarks,
                    $request,
                    false
                );
            }
        } catch (Throwable $e) {
            Log::warning('RCU: failed to log workflow journey entry', [
                'lead_uuid' => $leadUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
