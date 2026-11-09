<?php

namespace Modules\ESign\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Modules\ESign\Exceptions\DigioApiException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class DigioClient
{
    private Client $httpClient;

    private array $config;

    public function __construct()
    {
        $this->config = (array) config('esign', []);

        $baseUrl = trim(
            (string) ($this->config['base_url'] ?? '')
        );

        /*
         * Defensive fallback.
         *
         * Configuration should normally come from config/esign.php.
         * If base_url is accidentally empty, never default an unknown
         * environment directly to production.
         */
        if ($baseUrl === '') {
            $baseUrl = strtolower(
                (string) ($this->config['env'] ?? 'sandbox')
            ) === 'production'
                ? 'https://api.digio.in'
                : 'https://ext.digio.in:444';
        }

        $this->httpClient = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',

            'timeout' => (float) (
                $this->config['timeout'] ?? 30
            ),

            'connect_timeout' => (float) (
                $this->config['connect_timeout'] ?? 10
            ),

            /*
             * Digio uses HTTP Basic authentication.
             */
            'auth' => [
                (string) ($this->config['client_id'] ?? ''),
                (string) ($this->config['client_secret'] ?? ''),
            ],

            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'LOS-Digio-Integration/1.0',
            ],

            /*
             * We process HTTP error responses ourselves so that
             * provider errors can be normalized safely.
             */
            'http_errors' => false,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DigiSign
    |--------------------------------------------------------------------------
    */

    public function createSignRequest(array $payload): array
    {
        return $this->requestJson(
            method: 'POST',
            endpoint: (string) (
                $this->config['endpoints']['create_sign_request']
                ?? '/v2/client/document/uploadpdf'
            ),
            options: [
                'json' => $payload,
            ],
            operation: 'create_sign_request'
        );
    }

    public function getSigningStatus(
        string $digioDocumentId
    ): array {
        if (trim($digioDocumentId) === '') {
            throw new DigioApiException(
                'Digio document ID is required.'
            );
        }

        return $this->requestJson(
            method: 'GET',
            endpoint:
                (string) (
                    $this->config['endpoints']['get_document']
                    ?? '/v2/client/document/'
                )
                . rawurlencode($digioDocumentId),
            operation: 'get_document'
        );
    }

    public function downloadSignedDocument(
        string $digioDocumentId
    ): string {
        if (trim($digioDocumentId) === '') {
            throw new DigioApiException(
                'Digio document ID is required.'
            );
        }

        $response = $this->request(
            method: 'GET',
            endpoint: (string) (
                $this->config['endpoints']['download_document']
                ?? '/v2/client/document/download'
            ),
            options: [
                'query' => [
                    'document_id' => $digioDocumentId,
                ],
                'headers' => [
                    'Accept' => 'application/pdf, application/octet-stream, */*',
                ],
            ],
            operation: 'download_document'
        );

        $content = $response
            ->getBody()
            ->getContents();

        /*
         * Prevent HTML/error pages being accidentally stored
         * as signed PDF documents.
         */
        if (
            $content === ''
            || ! str_starts_with($content, '%PDF')
        ) {
            throw new DigioApiException(
                'Digio returned an invalid signed PDF.'
            );
        }

        return $content;
    }

    /*
    |--------------------------------------------------------------------------
    | Existing NACH Integration
    |--------------------------------------------------------------------------
    |
    | NACH business behaviour is intentionally not being changed during
    | the DigiSign implementation. These methods continue using the
    | existing configured NACH endpoints.
    |
    */

    public function createMandate(array $payload): array
    {
        return $this->requestJson(
            method: 'POST',
            endpoint: (string) (
                $this->config['nach_endpoints']['create_mandate']
                ?? ''
            ),
            options: [
                'json' => $payload,
            ],
            operation: 'create_nach_mandate'
        );
    }

    public function getMandateStatus(
        string $mandateId
    ): array {
        return $this->requestJson(
            method: 'GET',
            endpoint:
                (string) (
                    $this->config['nach_endpoints']['mandate_status']
                    ?? ''
                )
                . rawurlencode($mandateId),
            operation: 'get_nach_mandate'
        );
    }

    public function cancelMandate(
        string $mandateId
    ): array {
        return $this->requestJson(
            method: 'POST',
            endpoint:
                (string) (
                    $this->config['nach_endpoints']['cancel_mandate']
                    ?? ''
                )
                . rawurlencode($mandateId),
            operation: 'cancel_nach_mandate'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP Layer
    |--------------------------------------------------------------------------
    */

    private function requestJson(
        string $method,
        string $endpoint,
        array $options = [],
        string $operation = 'request'
    ): array {
        $response = $this->request(
            method: $method,
            endpoint: $endpoint,
            options: $options,
            operation: $operation
        );

        $raw = $response
            ->getBody()
            ->getContents();

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode(
            $raw,
            true
        );

        if (! is_array($decoded)) {
            throw new DigioApiException(
                'Digio returned an invalid JSON response.'
            );
        }

        return $decoded;
    }

    private function request(
        string $method,
        string $endpoint,
        array $options = [],
        string $operation = 'request'
    ): ResponseInterface {
        $this->assertConfigured();

        if (trim($endpoint) === '') {
            throw new DigioApiException(
                "Digio endpoint is missing for [{$operation}]."
            );
        }

        $maxRetries = max(
            0,
            (int) (
                $this->config['max_retries'] ?? 3
            )
        );

        $attempt = 0;

        do {
            $attempt++;

            try {
                $response = $this->httpClient->request(
                    $method,
                    ltrim($endpoint, '/'),
                    $options
                );

                $status = $response->getStatusCode();

                if (
                    $status >= 200
                    && $status < 300
                ) {
                    return $response;
                }

                /*
                 * Retry only temporary/provider availability failures.
                 *
                 * 4xx validation/auth failures must not be retried,
                 * except 408 and 429.
                 */
                if (
                    $this->isRetryableStatus($status)
                    && $attempt <= $maxRetries
                ) {
                    $this->backoff($attempt);

                    continue;
                }

                throw $this->providerExceptionFromResponse(
                    response: $response,
                    operation: $operation,
                    attempt: $attempt
                );

            } catch (ConnectException $exception) {

                if ($attempt <= $maxRetries) {
                    $this->backoff($attempt);

                    continue;
                }

                $this->logFailure(
                    operation: $operation,
                    status: null,
                    attempt: $attempt,
                    exception: $exception
                );

                throw new DigioApiException(
                    message: 'Unable to connect to Digio.',
                    previous: $exception
                );

            } catch (RequestException $exception) {

                $status = $exception
                    ->getResponse()
                    ?->getStatusCode();

                if (
                    (
                        $status === null
                        || $this->isRetryableStatus($status)
                    )
                    && $attempt <= $maxRetries
                ) {
                    $this->backoff($attempt);

                    continue;
                }

                $this->logFailure(
                    operation: $operation,
                    status: $status,
                    attempt: $attempt,
                    exception: $exception
                );

                throw new DigioApiException(
                    message: 'Digio API request failed.',
                    previous: $exception,
                    httpStatus: $status
                );

            } catch (DigioApiException $exception) {

                $this->logFailure(
                    operation: $operation,
                    status: $exception->httpStatus(),
                    attempt: $attempt,
                    exception: $exception,
                    providerCode: $exception->providerCode()
                );

                throw $exception;

            } catch (Throwable $exception) {

                $this->logFailure(
                    operation: $operation,
                    status: null,
                    attempt: $attempt,
                    exception: $exception
                );

                throw new DigioApiException(
                    message: 'Unexpected Digio integration error.',
                    previous: $exception
                );
            }

        } while ($attempt <= $maxRetries);

        throw new DigioApiException(
            'Digio API request failed after retries.'
        );
    }

    private function providerExceptionFromResponse(
        ResponseInterface $response,
        string $operation,
        int $attempt
    ): DigioApiException {
        $status = $response->getStatusCode();

        $body = $response
            ->getBody()
            ->getContents();

        $decoded = json_decode(
            $body,
            true
        );

        $providerCode = is_array($decoded)
            ? (string) (
                $decoded['error_code']
                ?? $decoded['code']
                ?? ''
            )
            : '';

        $providerMessage = is_array($decoded)
            ? (string) (
                $decoded['message']
                ?? $decoded['error_message']
                ?? ''
            )
            : '';

        /*
         * Never log the provider response body because it may
         * contain customer/document information.
         */
        $this->logFailure(
            operation: $operation,
            status: $status,
            attempt: $attempt,
            providerCode:
                $providerCode !== ''
                    ? $providerCode
                    : null
        );

        $message = $providerMessage !== ''
            ? 'Digio request rejected: '
                . $providerMessage
            : 'Digio request failed with HTTP '
                . $status
                . '.';

        return new DigioApiException(
            message: $message,
            httpStatus: $status,
            providerCode:
                $providerCode !== ''
                    ? $providerCode
                    : null
        );
    }

    private function isRetryableStatus(
        int $status
    ): bool {
        return $status === 408
            || $status === 429
            || $status >= 500;
    }

    private function backoff(
        int $attempt
    ): void {
        /*
         * Retry:
         * 250ms → 500ms → 1000ms → max 2000ms
         */
        $milliseconds = min(
            2000,
            250 * (
                2 ** max(
                    0,
                    $attempt - 1
                )
            )
        );

        usleep(
            $milliseconds * 1000
        );
    }

    private function assertConfigured(): void
    {
        foreach (
            [
                'base_url',
                'client_id',
                'client_secret',
            ] as $key
        ) {
            if (
                trim(
                    (string) (
                        $this->config[$key] ?? ''
                    )
                ) === ''
            ) {
                throw new DigioApiException(
                    "Digio configuration [{$key}] is missing."
                );
            }
        }
    }

    private function logFailure(
        string $operation,
        ?int $status,
        int $attempt,
        ?Throwable $exception = null,
        ?string $providerCode = null
    ): void {
        /*
         * SECURITY:
         *
         * Never log:
         * - client secret
         * - access token
         * - signer email/mobile
         * - Aadhaar information
         * - PDF/base64
         * - complete provider response
         */

        Log::error(
            'Digio API operation failed.',
            [
                'operation' => $operation,
                'http_status' => $status,
                'attempt' => $attempt,
                'provider_code' => $providerCode,
                'exception_class' =>
                    $exception
                        ? $exception::class
                        : null,
            ]
        );
    }
}