<?php

namespace Modules\ESign\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\ESign\Http\Requests\RegisterAgreementRequest;
use Modules\ESign\Http\Requests\StartSigningRequest;
use Modules\ESign\Http\Resources\BackendDocumentResource;
use Modules\ESign\Http\Resources\ESignSessionResource;
use Modules\ESign\Models\ESignDocument;
use Modules\ESign\Models\ESignSession;
use Modules\ESign\Services\BackendAccessGuard;
use Modules\ESign\Services\ESignWorkflow;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BackendDocumentController extends Controller
{
    private const LOG = '[BackendDocument] ';

    public function __construct(
        private readonly ESignWorkflow $workflow,
        private readonly BackendAccessGuard $access,
    ) {
    }

    public function index(Request $request): mixed
    {
        $this->access->assert($request);

        $request->validate([
            'customer_lending_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        Log::channel('payday_audit')->info(
            self::LOG . 'Document listing requested',
            [
                'customer_lending_id' =>
                    $request->integer('customer_lending_id') ?: null,
                'status' =>
                    $request->string('status')->toString() ?: null,
                'per_page' => $request->integer('per_page', 25),
                'requested_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        $documents = ESignDocument::query()
            ->with([
                'sessions' => fn ($query) =>
                    $query->orderBy('id'),
            ])
            ->when(
                $request->integer('customer_lending_id'),
                fn ($query, $id) =>
                    $query->where('customer_lending_id', $id)
            )
            ->when(
                $request->string('status')->toString(),
                fn ($query, $status) =>
                    $query->where('status', $status)
            )
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        Log::channel('payday_audit')->info(
            self::LOG . 'Document listing completed',
            [
                'result_count' => $documents->count(),
                'total' => $documents->total(),
                'current_page' => $documents->currentPage(),
                'requested_by' => $request->user()?->id,
            ]
        );

        return BackendDocumentResource::collection($documents);
    }

    public function store(
        RegisterAgreementRequest $request
    ): JsonResponse {
        $this->access->assert($request);

        $data = $request->validated();

        Log::channel('payday_audit')->info(
            self::LOG . 'Agreement registration requested',
            [
                'customer_lending_id' =>
                    (int) $data['customer_lending_id'],
                'requested_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        $document = $this->workflow->registerAgreement(
            (int) $data['customer_lending_id'],
            $data['original_document_path'],
            $data['original_document_disk'] ?? null,
            $data['metadata'] ?? [],
        );

        Log::channel('payday_audit')->info(
            self::LOG . 'Agreement registered',
            [
                'document_id' => $document->id,
                'customer_lending_id' =>
                    $document->customer_lending_id,
                'version' => $document->version,
                'status' => $document->status,
                'was_created' => $document->wasRecentlyCreated,
                'registered_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        return (new BackendDocumentResource(
            $document->load('sessions')
        ))
            ->response()
            ->setStatusCode(
                $document->wasRecentlyCreated ? 201 : 200
            );
    }

    public function show(
        Request $request,
        ESignDocument $document
    ): BackendDocumentResource {
        $this->access->assert($request);

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

        return new BackendDocumentResource(
            $document->load([
                'sessions' => fn ($query) =>
                    $query->orderBy('id'),
            ])
        );
    }

    public function borrower(
        StartSigningRequest $request,
        ESignDocument $document
    ): ESignSessionResource {
        $this->access->assert($request);

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
            $document,
            $request->validated('signer', [])
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

    public function lender(
        StartSigningRequest $request,
        ESignDocument $document
    ): ESignSessionResource {
        $this->access->assert($request);

        Log::channel('payday_audit')->info(
            self::LOG . 'Lender signing requested',
            [
                'document_id' => $document->id,
                'customer_lending_id' =>
                    $document->customer_lending_id,
                'version' => $document->version,
                'requested_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        $session = $this->workflow->startLenderSigning(
            $document,
            $request->validated('signer', [])
        );

        Log::channel('payday_audit')->info(
            self::LOG . 'Lender signing session started',
            [
                'document_id' => $document->id,
                'session_id' => $session->id,
                'status' => $session->status,
                'started_by' => $request->user()?->id,
            ]
        );

        return new ESignSessionResource($session);
    }

    public function sync(
        Request $request,
        ESignSession $session
    ): ESignSessionResource {
        $this->access->assert($request);

        Log::channel('payday_audit')->info(
            self::LOG . 'Signing session synchronization requested',
            [
                'session_id' => $session->id,
                'document_id' =>
                    $session->getAttribute('e_sign_document_id'),
                'current_status' => $session->status,
                'requested_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        $syncedSession = $this->workflow->syncSession($session);

        Log::channel('payday_audit')->info(
            self::LOG . 'Signing session synchronized',
            [
                'session_id' => $syncedSession->id,
                'document_id' =>
                    $syncedSession->getAttribute(
                        'e_sign_document_id'
                    ),
                'status' => $syncedSession->status,
                'synchronized_by' => $request->user()?->id,
            ]
        );

        return new ESignSessionResource($syncedSession);
    }

    public function download(
        Request $request,
        ESignDocument $document,
        string $type
    ): StreamedResponse {
        $this->access->assert($request);

        Log::channel('payday_audit')->info(
            self::LOG . 'Document download requested',
            [
                'document_id' => $document->id,
                'customer_lending_id' =>
                    $document->customer_lending_id,
                'version' => $document->version,
                'type' => $type,
                'requested_by' => $request->user()?->id,
                'ip' => $request->ip(),
            ]
        );

        [$disk, $path, $suffix] = match ($type) {
            'original' => [
                $document->original_document_disk,
                $document->original_document_path,
                'original',
            ],
            'borrower' => [
                $document->borrower_signed_document_disk,
                $document->borrower_signed_document_path,
                'borrower-signed',
            ],
            'final' => [
                $document->final_signed_document_disk,
                $document->final_signed_document_path,
                'final-signed',
            ],
            default => abort(404),
        };

        abort_if(
            $disk === null
            || $path === null
            || ! Storage::disk($disk)->exists($path),
            404
        );

        Log::channel('payday_audit')->info(
            self::LOG . 'Document download started',
            [
                'document_id' => $document->id,
                'customer_lending_id' =>
                    $document->customer_lending_id,
                'version' => $document->version,
                'type' => $type,
                'downloaded_by' => $request->user()?->id,
            ]
        );

        return Storage::disk($disk)->download(
            $path,
            "agreement-{$document->customer_lending_id}"
                . "-v{$document->version}-{$suffix}.pdf",
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}