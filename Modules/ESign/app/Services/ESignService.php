<?php

namespace Modules\ESign\Services;

use Modules\ESign\Exceptions\ESignDomainException;

/**
 * Compatibility facade used by existing BLReports/BLSaction modules.
 * Those modules previously referenced this class, but it was absent from
 * the supplied ESign package. BL source requests now intentionally collapse
 * into one combined document and one sequential borrower -> lender journey.
 */
final class ESignService
{
    public function __construct(
        private readonly BLCombinedDocumentService $combined,
    ) {
    }

    public function initiateSigning(array $payload): array
    {
        $moduleType = strtolower(trim((string) ($payload['module_type'] ?? '')));

        if (! in_array($moduleType, ['bl', 'bl_reports', 'blsanction'], true)) {
            throw new ESignDomainException(
                'This ESignService entry point only accepts BL sanction/report documents.',
                422,
            );
        }

        $leadUuid = trim((string) ($payload['lead_uuid'] ?? ''));
        $initiatedBy = isset($payload['initiated_by']) && is_numeric($payload['initiated_by'])
            ? (int) $payload['initiated_by']
            : null;

        $result = $this->combined->initiate($leadUuid, $initiatedBy);
        $document = $result['document']->fresh(['borrowerSession', 'lenderSession']);
        $session = $result['session']->fresh();

        return [
            'success' => true,
            'message' => 'Combined BL package created and sent to the borrower for eSign.',
            'data' => [
                'document_uuid' => $document->uuid,
                'lead_uuid' => $document->lead_uuid,
                'document_type' => $document->document_type,
                'status' => $document->status->value,
                'borrower_esign_url' => $session->esign_url,
                'borrower_request_id' => $session->provider_request_id,
                'borrower_session_uuid' => $session->uuid,
                'lender_esign_url' => $document->lenderSession?->esign_url,
                'webhook_url' => (string) config('esign.provider.webhook.public_url'),
            ],
        ];
    }
}
