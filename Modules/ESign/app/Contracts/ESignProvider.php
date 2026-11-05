<?php

namespace Modules\ESign\Contracts;

use Illuminate\Http\Request;
use Modules\ESign\Data\ProviderSessionData;
use Modules\ESign\Data\ProviderStatusData;
use Modules\ESign\Data\SignedDocumentData;
use Modules\ESign\Data\SignerData;
use Modules\ESign\Data\WebhookData;
use Modules\ESign\Models\ESignDocument;
use Modules\ESign\Models\ESignSession;

interface ESignProvider
{
    public function createSigningSession(
        ESignDocument $document,
        ESignSession $session,
        SignerData $signer,
    ): ProviderSessionData;

    public function fetchStatus(ESignSession $session): ProviderStatusData;

    public function downloadSignedDocument(ESignSession $session): SignedDocumentData;

    public function verifyWebhook(Request $request): bool;

    public function parseWebhook(Request $request): WebhookData;
}
