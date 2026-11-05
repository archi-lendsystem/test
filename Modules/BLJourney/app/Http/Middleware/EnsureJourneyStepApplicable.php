<?php

namespace Modules\BLJourney\Http\Middleware;

use App\Services\BLLeadPermissionService;
use Closure;
use Illuminate\Http\Request;
use Modules\BLDocumentUpload\Models\BLDocumentUpload;
use Modules\BLJourney\Services\JourneyDefinitionService;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Symfony\Component\HttpFoundation\Response;

class EnsureJourneyStepApplicable
{
    public function __construct(
        private JourneyDefinitionService $definitions
    ) {
    }

    public function handle(
        Request $request,
        Closure $next,
        string $stepKey
    ): Response {
        /*
         * This middleware protects write operations.
         *
         * Existing read authentication, permissions and signed-file
         * protections remain with their existing route middleware.
         */
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
            ], 401);
        }

        $lead = $this->resolveLead($request, $stepKey);

        $user->loadMissing('roles');

        /*
         * Reuse existing edit rules, including admin bypass,
         * assignment restrictions and status locks.
         */
        abort_unless(
            BLLeadPermissionService::canEdit($lead->lead_id, $user),
            403,
            'You are not authorized to edit this lead.'
        );

        /*
         * Even an editable lead may not have this particular step.
         * The current product journey is authoritative.
         */
        $this->definitions->assertApplicable($lead, $stepKey);

        /*
         * Prevent a request body from overriding the lead selected
         * by the route or by an existing document-upload record.
         */
        if ($request->has('lead_uuid')) {
            $validated = $request->validate([
                'lead_uuid' => ['required', 'uuid'],
            ]);

            abort_unless(
                strtolower($validated['lead_uuid'])
                    === strtolower((string) $lead->uuid),
                422,
                'The lead UUID does not match the requested record.'
            );
        }

        if ($request->has('lead_id')) {
            $validated = $request->validate([
                'lead_id' => ['required', 'string'],
            ]);

            abort_unless(
                $validated['lead_id'] === (string) $lead->lead_id,
                422,
                'The lead ID does not match the requested record.'
            );
        }

        return $next($request);
    }

    private function resolveLead(
        Request $request,
        string $stepKey
    ): BLLeadCreation {
        /*
         * Existing document update/delete routes identify the upload.
         * Resolve its actual parent lead from the stored record.
         */
        if (
            $stepKey === 'documents'
            && $request->route('upload_uuid') !== null
        ) {
            $identifier = (string) $request->route('upload_uuid');

            $upload = BLDocumentUpload::query()
                ->where(
                    ctype_digit($identifier) ? 'id' : 'uuid',
                    $identifier
                )
                ->firstOrFail();

            return BLLeadCreation::query()
                ->where('lead_id', $upload->lead_id)
                ->firstOrFail();
        }

        $uuid = $request->route('lead_uuid')
            ?? $request->route('leadUuid');

        /*
         * The existing BLApplicant resource uses "applicant"
         * as its route parameter for the lead UUID.
         */
        if ($uuid === null && $stepKey === 'applicant') {
            $uuid = $request->route('applicant');
        }

        $uuid ??= $request->input('lead_uuid');

        if ($uuid !== null) {
            $validated = validator(
                ['lead_uuid' => $uuid],
                ['lead_uuid' => ['required', 'uuid']]
            )->validate();

            return BLLeadCreation::query()
                ->where('uuid', $validated['lead_uuid'])
                ->firstOrFail();
        }

        /*
         * Document creation and other existing endpoints may provide
         * the business lead_id instead of a lead UUID.
         */
        $leadId = $request->route('lead_id')
            ?? $request->input('lead_id');

        $validated = validator(
            ['lead_id' => $leadId],
            ['lead_id' => ['required', 'string']]
        )->validate();

        return BLLeadCreation::query()
            ->where('lead_id', $validated['lead_id'])
            ->firstOrFail();
    }
}