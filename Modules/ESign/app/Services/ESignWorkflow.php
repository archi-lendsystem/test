<?php

namespace Modules\ESign\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\CustomerLending\Models\CustomerLending;
use Modules\ESign\Contracts\ESignProvider;
use Modules\ESign\Data\ProviderStatusData;
use Modules\ESign\Data\WebhookData;
use Modules\ESign\Enums\DocumentStatus;
use Modules\ESign\Enums\SessionStatus;
use Modules\ESign\Enums\SignerType;
use Modules\ESign\Events\BorrowerSigned;
use Modules\ESign\Events\ESignCompleted;
use Modules\ESign\Events\ESignFailed;
use Modules\ESign\Events\SigningUrlCreated;
use Modules\ESign\Exceptions\ESignDomainException;
use Modules\ESign\Models\ESignDocument;
use Modules\ESign\Models\ESignSession;
use Throwable;

final class ESignWorkflow
{
    public function __construct(
        private readonly ESignProvider $provider,
        private readonly DocumentStorage $storage,
        private readonly SignerResolver $signers,
    ) {
    }

    public function registerAgreement(
        int $customerLendingId,
        string $path,
        ?string $disk = null,
        array $metadata = [],
    ): ESignDocument {
        $disk ??= (string) config('esign.storage.source_disk', 'local');
        $this->storage->assertValidPdf($disk, $path);

        return DB::transaction(function () use ($customerLendingId, $disk, $path, $metadata): ESignDocument {
            CustomerLending::query()
                ->whereKey($customerLendingId)
                ->lockForUpdate()
                ->firstOrFail();

            $latest = ESignDocument::withTrashed()
                ->where('customer_lending_id', $customerLendingId)
                ->orderByDesc('version')
                ->first();

            if (
                $latest !== null
                && $latest->deleted_at === null
                && $latest->original_document_disk === $disk
                && $latest->original_document_path === $path
                && ! $latest->isCompleted()
            ) {
                return $latest;
            }

            return ESignDocument::query()->create([
                'uuid' => (string) Str::uuid(),
                'customer_lending_id' => $customerLendingId,
                'version' => ($latest?->version ?? 0) + 1,
                'original_document_disk' => $disk,
                'original_document_path' => $path,
                'status' => DocumentStatus::Generated,
                'metadata' => $metadata,
            ]);
        }, 3);
    }

    public function startBorrowerSigning(ESignDocument $document, array $signerOverride = []): ESignSession
    {
        return $this->startSigning($document, SignerType::Borrower, $signerOverride);
    }

    public function startLenderSigning(ESignDocument $document, array $signerOverride = []): ESignSession
    {
        return $this->startSigning($document, SignerType::Lender, $signerOverride);
    }

    public function syncSession(ESignSession $session): ESignSession
    {
        $session->refresh();

        if ($session->status === SessionStatus::Signed) {
            return $session;
        }

        if (! $session->status->isActive()) {
            throw new ESignDomainException('Only an active signing session can be synchronized.');
        }

        $status = $this->provider->fetchStatus($session);

        return $this->applyProviderStatus($session, $status);
    }

    public function applyWebhook(ESignSession $session, WebhookData $data): ESignSession
    {
        return $this->applyProviderStatus($session, new ProviderStatusData(
            status: $data->status,
            requestId: $data->requestId,
            documentId: $data->documentId,
            signedAt: $data->occurredAt,
            raw: $data->payload,
        ));
    }

    private function startSigning(
        ESignDocument $document,
        SignerType $type,
        array $signerOverride,
    ): ESignSession {
        $document->refresh();
        $signer = $this->signers->resolve($document, $type, $signerOverride);
        [$session, $created] = DB::transaction(function () use ($document, $type, $signer): array {
            $locked = ESignDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            $latest = ESignSession::query()
                ->where('esign_document_id', $locked->getKey())
                ->where('signer_type', $type->value)
                ->orderByDesc('attempt_no')
                ->lockForUpdate()
                ->first();

            if ($latest?->status === SessionStatus::Signed) {
                return [$latest, false];
            }

            if (
                $latest?->status === SessionStatus::Initiating
                && $latest->created_at?->greaterThan(now()->subMinutes(2))
            ) {
                return [$latest, false];
            }

            $this->assertCanStart($locked, $type);

            if ($latest?->hasUsableUrl()) {
                return [$latest, false];
            }

            if ($latest?->status?->isActive()) {
                $latest->update([
                    'status' => SessionStatus::Expired,
                    'failure_reason' => 'Signing URL expired before completion.',
                ]);
            }

            $attempt = ($latest?->attempt_no ?? 0) + 1;
            $uuid = (string) Str::uuid();
            $session = ESignSession::query()->create([
                'uuid' => $uuid,
                'esign_document_id' => $locked->getKey(),
                'signer_type' => $type,
                'attempt_no' => $attempt,
                'template_id' => config("esign.templates.{$type->value}"),
                'signer_details' => $signer->toArray(),
                'provider' => (string) config('esign.provider.name', 'http'),
                'reference_key' => 'ESIGN-'.strtoupper(str_replace('-', '', $uuid)),
                'status' => SessionStatus::Initiating,
            ]);

            return [$session, true];
        }, 3);

        if (! $created) {
            return $session;
        }

        try {
            $providerSession = $this->provider->createSigningSession($document->fresh(), $session, $signer);

            DB::transaction(function () use ($session, $document, $type, $providerSession): void {
                $lockedSession = ESignSession::query()->lockForUpdate()->findOrFail($session->getKey());
                $lockedDocument = ESignDocument::query()->lockForUpdate()->findOrFail($document->getKey());

                $lockedSession->update([
                    'provider_request_id' => $providerSession->requestId,
                    'provider_doc_id' => $providerSession->documentId,
                    'esign_url' => $providerSession->signingUrl,
                    'status' => $providerSession->status === SessionStatus::Signed
                        ? SessionStatus::Pending
                        : $providerSession->status,
                    'expires_at' => $providerSession->expiresAt,
                    'last_synced_at' => now(),
                    'metadata' => ['provider_create' => $this->safeMetadata($providerSession->raw)],
                ]);

                if ($providerSession->status !== SessionStatus::Signed) {
                    $lockedDocument->update([
                        'status' => $type === SignerType::Borrower
                            ? DocumentStatus::BorrowerPending
                            : DocumentStatus::LenderPending,
                        'last_error' => null,
                    ]);
                }
            }, 3);

            $freshSession = $session->fresh();
            $this->dispatchSafely(new SigningUrlCreated($freshSession));

            if (
                $type === SignerType::Lender
                && (bool) config('esign.notifications.lender_sms', true)
                && method_exists($this->provider, 'sendSigningLink')
            ) {
                try {
                    $this->provider->sendSigningLink($freshSession);
                } catch (Throwable $exception) {
                    Log::warning('Lender signing session was created but SMS delivery failed.', [
                        'session_uuid' => $freshSession->uuid,
                        'document_uuid' => $document->uuid,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }

            if ($providerSession->status === SessionStatus::Signed) {
                return $this->completeSignedSession($session->fresh(), now());
            }

            return $session->fresh();
        } catch (Throwable $exception) {
            $this->markStartFailed($session, $document, $exception);
            throw $exception;
        }
    }

    private function applyProviderStatus(ESignSession $session, ProviderStatusData $data): ESignSession
    {
        DB::transaction(function () use ($session, $data): void {
            $lockedSession = ESignSession::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($lockedSession->status === SessionStatus::Signed) {
                return;
            }

            $lockedSession->update([
                'provider_request_id' => $data->requestId ?? $lockedSession->provider_request_id,
                'provider_doc_id' => $data->documentId ?? $lockedSession->provider_doc_id,
                'status' => $data->status === SessionStatus::Signed
                    ? $lockedSession->status
                    : $data->status,
                'failure_reason' => $data->failureReason,
                'last_synced_at' => now(),
            ]);

            if (in_array($data->status, [SessionStatus::Failed, SessionStatus::Expired, SessionStatus::Cancelled], true)) {
                $lockedSession->document()->update([
                    'status' => DocumentStatus::Failed,
                    'last_error' => $data->failureReason ?? "Signing session {$data->status->value}.",
                ]);
            }
        }, 3);

        if ($data->status === SessionStatus::Signed) {
            return $this->completeSignedSession($session->fresh(), $data->signedAt ?? now());
        }

        $fresh = $session->fresh();

        if (in_array($data->status, [SessionStatus::Failed, SessionStatus::Expired, SessionStatus::Cancelled], true)) {
            $this->dispatchSafely(new ESignFailed($fresh));
        }

        return $fresh;
    }

    private function completeSignedSession(ESignSession $session, mixed $signedAt): ESignSession
    {
        $session->load('document');
        $document = $session->document;

        if (
            $session->status === SessionStatus::Signed
            && ($session->signer_type === SignerType::Borrower
                ? $document->borrower_signed_document_path !== null
                : $document->final_signed_document_path !== null)
        ) {
            return $session;
        }

        $signedDocument = $this->provider->downloadSignedDocument($session);
        $signedChecksum = hash('sha256', $signedDocument->contents);
        $stored = $this->storage->storeSignedPdf($document, $session->signer_type, $signedDocument->contents);

        $transitioned = false;

        DB::transaction(function () use ($session, $document, $signedAt, $stored, $signedChecksum, &$transitioned): void {
            $lockedSession = ESignSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $lockedDocument = ESignDocument::query()->lockForUpdate()->findOrFail($document->getKey());

            $alreadyStored = $lockedSession->signer_type === SignerType::Borrower
                ? $lockedDocument->borrower_signed_document_path !== null
                : $lockedDocument->final_signed_document_path !== null;

            if ($lockedSession->status === SessionStatus::Signed && $alreadyStored) {
                return;
            }

            $transitioned = true;

            $lockedSession->update([
                'status' => SessionStatus::Signed,
                'signed_at' => $signedAt,
                'last_synced_at' => now(),
                'failure_reason' => null,
                'esign_url' => null,
            ]);

            if ($lockedSession->signer_type === SignerType::Borrower) {
                $lockedDocument->update([
                    'borrower_signed_document_disk' => $stored['disk'],
                    'borrower_signed_document_path' => $stored['path'],
                    'borrower_signed_at' => $signedAt,
                    'status' => DocumentStatus::BorrowerSigned,
                    'last_error' => null,
                    'metadata' => array_merge((array) $lockedDocument->metadata, [
                        'borrower_signed_checksum' => $signedChecksum,
                    ]),
                ]);
            } else {
                $lockedDocument->update([
                    'final_signed_document_disk' => $stored['disk'],
                    'final_signed_document_path' => $stored['path'],
                    'lender_signed_at' => $signedAt,
                    'completed_at' => $signedAt,
                    'status' => DocumentStatus::Completed,
                    'last_error' => null,
                    'metadata' => array_merge((array) $lockedDocument->metadata, [
                        'final_signed_checksum' => $signedChecksum,
                    ]),
                ]);
            }
        }, 3);

        if (! $transitioned) {
            return $session->fresh();
        }

        $freshDocument = $document->fresh();

        if ($session->signer_type === SignerType::Borrower) {
            $this->dispatchSafely(new BorrowerSigned($freshDocument));

            if ((bool) config('esign.bl.auto_start_lender', true)) {
                try {
                    $this->startLenderSigning($freshDocument);
                } catch (Throwable $exception) {
                    ESignDocument::query()->whereKey($freshDocument->getKey())->update([
                        'last_error' => 'Borrower signed, but lender signing could not be started automatically: '.mb_substr($exception->getMessage(), 0, 1600),
                    ]);

                    Log::error('Borrower signed but automatic lender eSign start failed.', [
                        'document_uuid' => $freshDocument->uuid,
                        'lead_uuid' => $freshDocument->lead_uuid,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        } else {
            $this->markBlSanctionCompleted($freshDocument);
            $this->dispatchSafely(new ESignCompleted($freshDocument));
        }

        return $session->fresh();
    }

    private function markBlSanctionCompleted(ESignDocument $document): void
    {
        if (empty($document->lead_uuid) || $document->document_type !== BLCombinedDocumentService::DOCUMENT_TYPE) {
            return;
        }

        $model = 'Modules\\BLSaction\\Models\\BLSaction';
        if (! class_exists($model)) {
            return;
        }

        try {
            $model::query()
                ->where('lead_uuid', $document->lead_uuid)
                ->where('status', 'esign_pending')
                ->latest('id')
                ->limit(1)
                ->update(['status' => 'esigned']);
        } catch (Throwable $exception) {
            Log::warning('Final eSign completed but sanction status synchronization failed.', [
                'document_uuid' => $document->uuid,
                'lead_uuid' => $document->lead_uuid,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function markStartFailed(ESignSession $session, ESignDocument $document, Throwable $exception): void
    {
        DB::transaction(function () use ($session, $document, $exception): void {
            ESignSession::query()->whereKey($session->getKey())->update([
                'status' => SessionStatus::Failed,
                'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
                'last_synced_at' => now(),
            ]);
            ESignDocument::query()->whereKey($document->getKey())->update([
                'status' => DocumentStatus::Failed,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
        }, 3);

        $this->dispatchSafely(new ESignFailed($session->fresh()));
    }

    private function assertCanStart(ESignDocument $document, SignerType $type): void
    {
        if ($document->isCompleted()) {
            throw new ESignDomainException('This agreement is already fully signed.');
        }

        if ($type === SignerType::Borrower) {
            if ($document->borrower_signed_document_path !== null) {
                throw new ESignDomainException('The borrower has already signed this agreement.');
            }

            return;
        }

        if ($document->borrower_signed_document_path === null || $document->borrower_signed_at === null) {
            throw new ESignDomainException('Borrower signature must be completed before lender signing.');
        }
    }

    private function safeMetadata(array $payload): array
    {
        return [
            'received_status' => data_get($payload, config('esign.provider.response_paths.status')),
            'received_at' => now()->toIso8601String(),
        ];
    }

    private function dispatchSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            Log::error('An eSign event listener failed.', [
                'event' => $event::class,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
