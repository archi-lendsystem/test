<?php

namespace Modules\PaydayPartnerCases\Services\Integrations;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LoanTrustIntegrationService extends AbstractPartnerIntegrationService
{

    public function __construct(array $credentials = [])
    {
        $this->apiUrl = rtrim(trim((string) (
            $credentials['api_url']
                ?? config('services.loantrust.api_url')
                ?? ''
        )), '/');

        $this->clientId = trim((string) (
            $credentials['client_id']
                ?? config('services.loantrust.client_id')
                ?? ''
        ));

        $this->clientSecret = trim((string) (
            $credentials['client_secret']
                ?? config('services.loantrust.client_secret')
                ?? ''
        ));
    }

    public function fetchCases(array $filters = []): array
    {
        return $this->get('', $filters);
    }

    public function fetchCase(string $partnerCaseId): array
    {
        return $this->get('/' . $partnerCaseId);
    }

    public function createCase(array $payload): array
    {
        return $this->post('', $payload);
    }

    public function updateCase(string $partnerCaseId, array $payload): array
    {
        return $this->put('/' . $partnerCaseId, $payload);
    }

    private function get(string $path, array $query = []): array
    {
        $start = microtime(true);

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout)
                ->get($this->apiUrl . $path, $query);

            return $this->parseResponse($response, $start, 'GET', $query);
        } catch (Throwable $e) {
            return $this->exceptionResponse($e, $start, 'GET', $query);
        }
    }

    private function post(string $path, array $payload): array
    {
        $start = microtime(true);

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout)
                ->post($this->apiUrl . $path, $payload);

            return $this->parseResponse($response, $start, 'POST', $payload);
        } catch (Throwable $e) {
            return $this->exceptionResponse($e, $start, 'POST', $payload);
        }
    }

    private function put(string $path, array $payload): array
    {
        $start = microtime(true);

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout)
                ->put($this->apiUrl . $path, $payload);

            return $this->parseResponse($response, $start, 'PUT', $payload);
        } catch (Throwable $e) {
            return $this->exceptionResponse($e, $start, 'PUT', $payload);
        }
    }

    private function parseResponse(Response $response, float $start, string $method, array $payload): array
    {
        $responseTimeMs = round((microtime(true) - $start) * 1000, 2);

        $body = $response->json();

        if (!is_array($body)) {
            $body = [
                'raw' => $response->body(),
            ];
        }

        return [
            'api_call_status'      => $response->successful() ? 'success' : 'failed',
            'api_http_status_code' => $response->status(),
            'api_response_status'  => $body['status'] ?? null,
            'api_response_code'    => $body['code'] ?? null,
            'api_response_message' => $body['message'] ?? null,
            'api_response_body'    => $body,
            'api_response_time_ms' => $responseTimeMs,

            'api_endpoint'         => $this->apiUrl,
            'api_method'           => $method,
            'api_request_headers'  => $this->sanitizedHeaders(),
            'api_request_payload'  => $payload,

            'error_description'    => $response->failed()
                ? ($body['message'] ?? 'Partner API request failed.')
                : null,
        ];
    }

    private function exceptionResponse(Throwable $e, float $start, string $method, array $payload): array
    {
        $responseTimeMs = round((microtime(true) - $start) * 1000, 2);

        Log::error('[LoanTrust] API Exception', [
            'message' => $e->getMessage(),
        ]);

        $status = str_contains(strtolower($e->getMessage()), 'timed out')
            ? 'timeout'
            : 'failed';

        return [
            'api_call_status'      => $status,
            'api_http_status_code' => null,
            'api_response_status'  => 'ERROR',
            'api_response_code'    => null,
            'api_response_message' => $e->getMessage(),
            'api_response_body'    => null,
            'api_response_time_ms' => $responseTimeMs,

            'api_endpoint'         => $this->apiUrl,
            'api_method'           => $method,
            'api_request_headers'  => $this->sanitizedHeaders(),
            'api_request_payload'  => $payload,

            'error_description'    => $e->getMessage(),
        ];
    }

}