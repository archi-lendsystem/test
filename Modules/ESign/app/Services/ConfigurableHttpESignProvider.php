<?php

namespace Modules\ESign\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\ESign\Contracts\ESignProvider;
use Modules\ESign\Data\ProviderSessionData;
use Modules\ESign\Data\ProviderStatusData;
use Modules\ESign\Data\SignedDocumentData;
use Modules\ESign\Data\SignerData;
use Modules\ESign\Data\WebhookData;
use Modules\ESign\Enums\SessionStatus;
use Modules\ESign\Exceptions\ProviderException;
use Modules\ESign\Models\ESignDocument;
use Modules\ESign\Models\ESignSession;
use Throwable;

final class ConfigurableHttpESignProvider implements ESignProvider
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly ESignApiLogger $logger,
    ) {
    }

    /**
     * Step 1 of 3: esign-generate. Returns an upload_url (presigned S3
     * PUT, not authenticated with our own headers) and an esign_url for
     * the signer — the document itself is uploaded separately, it is
     * not attached to this call.
     */
    public function createSigningSession(
        ESignDocument $document,
        ESignSession $session,
        SignerData $signer,
    ): ProviderSessionData {
        $endpoint = (string) config('esign.provider.endpoints.generate');

        $payload = array_filter([
            'unique_id' => $session->reference_key,
            'signers' => [$this->signerPayload($signer)],
            'reason' => 'Loan agreement',
            'file_name' => $this->fileName($document, $session),
            'template_id' => $session->template_id,
            'consent' => true,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $contents = $this->storage->contentsForSigning($document, $session->signer_type);
        $started = microtime(true);

        try {
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => $session->reference_key])
                ->post($endpoint, $payload);

            $this->logResponse($session, 'create_session', 'POST', $endpoint, $payload, $response, $started);
            $this->assertSuccessful($response);
            $body = $response->json();

            $requestId = $this->requiredString($body, 'request_id');
            $signingUrl = $this->requiredString($body, 'signing_url');
            $uploadUrl = $this->requiredString($body, 'upload_url');

            $this->uploadToPresignedUrl($session, $uploadUrl, $contents);

            return new ProviderSessionData(
                requestId: $requestId,
                documentId: $this->optionalString($body, 'reference_key'),
                signingUrl: $signingUrl,
                status: $this->mappedStatus(data_get($body, config('esign.provider.response_paths.status'))),
                expiresAt: null,
                raw: is_array($body) ? $body : [],
            );
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->record(
                $session,
                'create_session',
                'POST',
                $endpoint,
                $payload,
                null,
                null,
                $this->duration($started),
                $exception->getMessage(),
            );

            throw new ProviderException();
        }
    }

    /**
     * Step 2 of 3: optional SMS delivery of the signing link.
     */
    public function sendSigningLink(ESignSession $session): void
    {
        if ($session->provider_request_id === null) {
            throw new ProviderException('Provider request ID is missing.');
        }

        $endpoint = (string) config('esign.provider.endpoints.short_url');
        $payload = ['request_id' => $session->provider_request_id];
        $started = microtime(true);

        try {
            $response = $this->request()->post($endpoint, $payload);
            $this->logResponse($session, 'send_signing_sms', 'POST', $endpoint, $payload, $response, $started);
            $this->assertSuccessful($response);
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->record(
                $session,
                'send_signing_sms',
                'POST',
                $endpoint,
                $payload,
                null,
                null,
                $this->duration($started),
                $exception->getMessage(),
            );
            throw new ProviderException('Unable to send the eSign SMS link.');
        }
    }

    /**
     * Step 3 of 3: esign-get-doc. There is no separate "status" endpoint
     * — get-doc is the only way to poll a session's signing state, so
     * this and downloadSignedDocument() both call it.
     */
    public function fetchStatus(ESignSession $session): ProviderStatusData
    {
        if ($session->provider_request_id === null) {
            throw new ProviderException('Provider request ID is missing.');
        }

        $endpoint = (string) config('esign.provider.endpoints.get_doc');
        $payload = ['request_id' => $session->provider_request_id];
        $started = microtime(true);

        try {
            $response = $this->request()->post($endpoint, $payload);
            $this->logResponse($session, 'fetch_status', 'POST', $endpoint, $payload, $response, $started);
            $this->assertSuccessful($response);
            $body = $response->json();

            $state = $this->signerState($body)
                ?? (string) data_get($body, config('esign.provider.response_paths.status'));

            return new ProviderStatusData(
                status: $this->mappedStatus($state),
                requestId: $this->optionalString($body, 'request_id'),
                documentId: $this->optionalString($body, 'reference_key'),
                signedAt: strtolower(trim($state)) === 'signed' ? CarbonImmutable::now() : null,
                failureReason: data_get($body, 'message'),
                raw: is_array($body) ? $body : [],
            );
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->record($session, 'fetch_status', 'POST', $endpoint, $payload, null, null, $this->duration($started), $exception->getMessage());
            throw new ProviderException();
        }
    }

    /**
     * download_url (from esign-get-doc) is itself a presigned S3 GET
     * URL — the actual PDF bytes come from a second, unauthenticated
     * request to that URL, not from the vendor API response directly.
     */
    public function downloadSignedDocument(ESignSession $session): SignedDocumentData
    {
        if ($session->provider_request_id === null) {
            throw new ProviderException('Provider request ID is missing.');
        }

        $endpoint = (string) config('esign.provider.endpoints.get_doc');
        $payload = ['request_id' => $session->provider_request_id];
        $started = microtime(true);

        try {
            $response = $this->request()->post($endpoint, $payload);
            $this->logResponse($session, 'download_signed_document', 'POST', $endpoint, $payload, $response, $started);
            $this->assertSuccessful($response);
            $body = $response->json();

            $downloadUrl = $this->requiredString($body, 'download_url');

            $fileResponse = Http::timeout((int) config('esign.provider.timeout', 30))->get($downloadUrl);

            if (! $fileResponse->successful()) {
                throw new ProviderException(
                    sprintf('Downloading the signed document failed (HTTP %d).', $fileResponse->status()),
                    $fileResponse->status(),
                );
            }

            return new SignedDocumentData(
                $fileResponse->body(),
                (string) ($fileResponse->header('Content-Type') ?: 'application/pdf'),
            );
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->record($session, 'download_signed_document', 'POST', $endpoint, $payload, null, null, $this->duration($started), $exception->getMessage());
            throw new ProviderException();
        }
    }

    public function verifyWebhook(Request $request): bool
    {
        $mode = strtolower(trim((string) config('esign.provider.webhook.verification_mode', 'hmac')));
        $secret = trim((string) config('esign.provider.webhook.secret'));

        if ($mode === 'none') {
            return (bool) config('app.debug', false) || app()->environment(['local', 'testing']);
        }

        if ($mode === 'token') {
            $header = (string) config('esign.provider.webhook.token_header', 'X-ESign-Webhook-Token');
            $provided = trim((string) $request->header($header));

            return $secret !== '' && $provided !== '' && hash_equals($secret, $provided);
        }

        $signatureHeader = (string) config('esign.provider.webhook.signature_header');
        $timestampHeader = (string) config('esign.provider.webhook.timestamp_header');
        $algorithm = (string) config('esign.provider.webhook.algorithm', 'sha256');
        $signature = trim((string) $request->header($signatureHeader));
        $timestamp = trim((string) $request->header($timestampHeader));

        if ($secret === '' || $signature === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > (int) config('esign.provider.webhook.tolerance_seconds', 300)) {
            return false;
        }

        $signature = str_contains($signature, '=') ? substr($signature, strpos($signature, '=') + 1) : $signature;
        $expected = hash_hmac($algorithm, $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhook(Request $request): WebhookData
    {
        $payload = $request->json()->all();

        if ($payload === []) {
            throw new ProviderException('Webhook payload is invalid.');
        }

        $value = function (string $name, array $fallbackKeys) use ($payload): mixed {
            $path = trim((string) config("esign.provider.webhook_paths.{$name}"));
            $configured = $path !== '' ? data_get($payload, $path) : null;

            return $configured ?? $this->findFirstValue($payload, $fallbackKeys);
        };

        return new WebhookData(
            eventId: $this->nullable($value('event_id', ['event_id', 'eventId', 'id'])),
            referenceKey: $this->nullable($value('reference_key', ['ReferenceKey', 'reference_key', 'unique_id'])),
            requestId: $this->nullable($value('request_id', ['request_id', 'requestId'])),
            documentId: $this->nullable($value('document_id', ['docId', 'document_id', 'documentId'])),
            status: $this->mappedStatus($value('status', ['state', 'status'])),
            occurredAt: $this->parseDate($value('occurred_at', ['timestamp', 'occurred_at', 'signed_at', 'updated_at'])),
            payload: $payload,
        );
    }

    /**
     * DigiLocker/Aadhaar signer object only needs name + at least one of
     * mobile/email per the vendor's docs — SignerData doesn't carry a
     * "location" field, so it's simply omitted (documented as optional).
     */
    private function signerPayload(SignerData $signer): array
    {
        return array_filter([
            'name' => $signer->name,
            'mobile' => $signer->mobile,
            'email' => $signer->email,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Vendor requires letters, numbers, underscore, and an extension
     * only — no hyphens (document uuids contain hyphens).
     */
    private function fileName(ESignDocument $document, ESignSession $session): string
    {
        $name = sprintf(
            'agreement_%s_%s_v%d.pdf',
            str_replace('-', '', (string) $document->uuid),
            $session->signer_type->value,
            $document->version,
        );

        return preg_replace('/[^A-Za-z0-9_.]/', '_', $name) ?? $name;
    }

    private function uploadToPresignedUrl(ESignSession $session, string $url, string $contents): void
    {
        $started = microtime(true);

        $response = Http::withBody($contents, 'application/pdf')
            ->timeout((int) config('esign.provider.timeout', 30))
            ->put($url);

        $this->logger->record(
            $session,
            'upload_document',
            'PUT',
            $url,
            ['bytes' => strlen($contents)],
            ['status' => $response->status()],
            $response->status(),
            $this->duration($started),
        );

        if (! $response->successful()) {
            throw new ProviderException(
                sprintf('Uploading the document to the eSign provider failed (HTTP %d).', $response->status()),
                $response->status(),
            );
        }
    }

    /**
     * esign-get-doc's exact per-signer response shape isn't fully
     * documented (only that a "state" field exists, "signed"/
     * "notsigned") — search for it recursively rather than assume one
     * fixed path, same approach used for other Lendsystem products
     * whose real shape only became clear from live responses.
     */
    private function signerState(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $found = $this->findFirstValue($body, ['state']);

        return $found !== null ? strtolower(trim((string) $found)) : null;
    }

    private function findFirstValue(array $payload, array $candidateKeys): mixed
    {
        foreach ($payload as $key => $value) {
            if (
                in_array((string) $key, $candidateKeys, true)
                && (is_string($value) || is_numeric($value))
            ) {
                return $value;
            }

            if (is_array($value)) {
                $found = $this->findFirstValue($value, $candidateKeys);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function request(): PendingRequest
    {
        $baseUrl = rtrim((string) config('esign.provider.base_url'), '/');
        $appKey = trim((string) config('esign.provider.app_key'));
        $secretKey = trim((string) config('esign.provider.secret_key'));
        $appKeyHeader = trim((string) config('esign.provider.app_key_header', 'X-App-Key'));
        $secretKeyHeader = trim((string) config('esign.provider.secret_key_header', 'X-Secret-Key'));
        $environment = trim((string) config('esign.provider.environment', 'production'));
        $environmentHeader = trim((string) config('esign.provider.environment_header', 'X-Environment'));

        if ($baseUrl === '' || $appKey === '' || $secretKey === '') {
            throw new ProviderException('eSign provider App Key or Secret Key is not configured.');
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withHeaders(array_filter([
                $appKeyHeader => $appKey,
                $secretKeyHeader => $secretKey,
                $environmentHeader => $environment !== '' ? $environment : null,
            ]))
            ->timeout((int) config('esign.provider.timeout', 30))
            ->connectTimeout((int) config('esign.provider.connect_timeout', 10))
            ->retry(
                max(1, (int) config('esign.provider.retry_times', 2)),
                max(0, (int) config('esign.provider.retry_sleep_ms', 250)),
                throw: false,
            )
            ->withOptions(['verify' => (bool) config('esign.provider.verify_tls', true)]);
    }

    private function mappedStatus(mixed $providerStatus): SessionStatus
    {
        $key = strtolower(trim((string) $providerStatus));
        $mapped = config("esign.provider.status_map.{$key}");

        if (! is_string($mapped)) {
            throw new ProviderException('Provider returned an unknown signing status.');
        }

        return SessionStatus::from($mapped);
    }

    private function requiredString(array $body, string $name): string
    {
        $value = $this->optionalString($body, $name);

        if ($value === null) {
            throw new ProviderException("Provider response is missing {$name}.");
        }

        return $value;
    }

    private function optionalString(array $body, string $name): ?string
    {
        return $this->nullable(data_get($body, config("esign.provider.response_paths.{$name}")));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json();

        $providerMessage = null;

        if (is_array($body)) {
            $providerMessage = data_get($body, 'message')
                ?? data_get($body, 'error.message')
                ?? data_get($body, 'error')
                ?? data_get($body, 'errors.0')
                ?? data_get($body, 'detail');
        }

        if (is_array($providerMessage)) {
            $providerMessage = json_encode($providerMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $providerMessage = trim((string) ($providerMessage ?: $response->body()));

        if ($providerMessage === '') {
            $providerMessage = 'Provider returned an empty error response.';
        }

        throw new ProviderException(
            sprintf(
                'eSign provider rejected the request (HTTP %d): %s',
                $response->status(),
                mb_substr($providerMessage, 0, 1500),
            ),
            $response->status(),
        );
    }

    private function logResponse(
        ESignSession $session,
        string $action,
        string $method,
        string $endpoint,
        array $request,
        Response $response,
        float $started,
    ): void {
        $this->logger->record(
            $session,
            $action,
            $method,
            $endpoint,
            $request,
            $response->json() ?? ['body' => $response->body()],
            $response->status(),
            $this->duration($started),
        );
    }

    private function duration(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
