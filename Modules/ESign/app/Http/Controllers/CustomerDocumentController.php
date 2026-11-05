<?php

namespace Modules\ESign\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\ESign\Http\Resources\CustomerDocumentResource;
use Modules\ESign\Http\Resources\ESignSessionResource;
use Modules\ESign\Models\ESignDocument;
use Modules\ESign\Services\CustomerAccessGuard;
use Modules\ESign\Services\ESignWorkflow;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CustomerDocumentController extends Controller
{
    private const LOG = '[CustomerDocument] ';

    public function __construct(
        private readonly ESignWorkflow $workflow,
        private readonly CustomerAccessGuard $access,
    ) {
    }

    public function latest(
        Request $request,
        int $customerLendingId
    ): CustomerDocumentResource {
        Log::channel('payday_audit')->info(
            self::LOG . 'Latest document requested',
            [
                'customer_lending_id' => $customerLendingId,
                'requested_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        $document = ESignDocument::query()
            ->where('customer_lending_id', $customerLendingId)
            ->latest('version')
            ->firstOrFail();

        $this->access->assert($request, $document);

        Log::channel('payday_audit')->info(
            self::LOG . 'Latest document fetched',
            [
                'document_id' => $document->id,
                'customer_lending_id' =>
                    $document->customer_lending_id,
                'version' => $document->version,
                'status' => $document->status,
                'requested_by' => $request->user()?->id,
            ]
        );

        return new CustomerDocumentResource(
            $document->load([
                'customerLending',
                'borrowerSession',
            ])
        );
    }

    public function show(
        Request $request,
        ESignDocument $document
    ): CustomerDocumentResource {
        $this->access->assert($request, $document);

        Log::channel('payday_audit')->info(
            self::LOG . 'Document view requested',
            [
                'document_id' => $document->id,
                'customer_lending_id' =>
                    $document->customer_lending_id,
                'version' => $document->version,
                'status' => $document->status,
                'requested_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        return new CustomerDocumentResource(
            $document->load([
                'customerLending',
                'borrowerSession',
            ])
        );
    }

    public function borrower(
        Request $request,
        ESignDocument $document
    ): ESignSessionResource {
        $this->access->assert($request, $document);

        Log::channel('payday_audit')->info(
            self::LOG . 'Borrower signing requested',
            [
                'document_id' => $document->id,
                'customer_lending_id' =>
                    $document->customer_lending_id,
                'version' => $document->version,
                'requested_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        $session = $this->workflow->startBorrowerSigning(
            $document
        );

        Log::channel('payday_audit')->info(
            self::LOG . 'Borrower signing session started',
            [
                'document_id' => $document->id,
                'session_id' => $session->id,
                'status' => $session->status,
                'started_by' => $request->user()?->id,
            ]
        );

        return new ESignSessionResource($session);
    }

    public function borrowerDownload(
        Request $request,
        ESignDocument $document
    ): StreamedResponse {
        $this->access->assert($request, $document);

        Log::channel('payday_audit')->info(
            self::LOG . 'Borrower-signed document download requested',
            [
                'document_id' => $document->id,
                'customer_lending_id' =>
                    $document->customer_lending_id,
                'version' => $document->version,
                'requested_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        $disk = $document->borrower_signed_document_disk;
        $path = $document->borrower_signed_document_path;

        abort_if(
            $disk === null
            || $path === null
            || ! Storage::disk($disk)->exists($path),
            404
        );

        Log::channel('payday_audit')->info(
            self::LOG . 'Borrower-signed document download started',
            [
                'document_id' => $document->id,
                'customer_lending_id' =>
                    $document->customer_lending_id,
                'version' => $document->version,
                'downloaded_by' => $request->user()?->id,
            ]
        );

        return Storage::disk($disk)->download(
            $path,
            "agreement-{$document->customer_lending_id}"
                . '-borrower-signed.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}