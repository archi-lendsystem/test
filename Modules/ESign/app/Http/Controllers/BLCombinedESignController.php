<?php

namespace Modules\ESign\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ESign\Services\BackendAccessGuard;
use Modules\ESign\Services\BLCombinedDocumentService;

final class BLCombinedESignController extends Controller
{
    public function __construct(
        private readonly BLCombinedDocumentService $service,
        private readonly BackendAccessGuard $access,
    ) {
    }

    public function __invoke(Request $request, string $leadUuid): JsonResponse
    {
        $this->access->assert($request);

        $result = $this->service->initiate($leadUuid, $request->user()?->id);
        $document = $result['document']->fresh(['borrowerSession', 'lenderSession']);
        $session = $result['session']->fresh();

        return response()->json([
            'success' => true,
            'data' => [
                'document_uuid' => $document->uuid,
                'lead_uuid' => $document->lead_uuid,
                'status' => $document->status->value,
                'borrower_esign_url' => $session->esign_url,
                'borrower_request_id' => $session->provider_request_id,
                'borrower_session_uuid' => $session->uuid,
                'lender_esign_url' => $document->lenderSession?->esign_url,
                'webhook_url' => (string) config('esign.provider.webhook.public_url'),
            ],
        ], 201);
    }
}
