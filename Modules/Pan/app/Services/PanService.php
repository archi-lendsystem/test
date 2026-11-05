<?php

namespace Modules\Pan\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PanService
{
    private const VERIFY_PATH =
        '/api/v1/verify/pan-details-plus';

    private const RESPONSE_PATH =
        '/api/v1/responses/%s';

    public function verify(
        string $pan,
        string $idempotencyKey
    ): array {
        $pan = strtoupper(trim($pan));
        $idempotencyKey = trim($idempotencyKey);

        if (! preg_match(
            '/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            $pan
        )) {
            throw new RuntimeException(
                'A valid 10-character PAN is required.'
            );
        }

        if ($idempotencyKey === '') {
            throw new RuntimeException(
                'PAN idempotency key is required.'
            );
        }

        return $this->request(
            method: 'POST',
            path: self::VERIFY_PATH,
            body: [
                'pan' => $pan,
                'consent' => true,
            ],
            idempotencyKey: $idempotencyKey
        );
    }

    public function retrieveByReferenceKey(
        string $referenceKey
    ): array {
        $referenceKey = trim($referenceKey);

        if ($referenceKey === '') {
            throw new RuntimeException(
                'PAN ReferenceKey is required.'
            );
        }

        return $this->request(
            method: 'GET',
            path: sprintf(
                self::RESPONSE_PATH,
                rawurlencode($referenceKey)
            ),
            body: null
        );
    }

    private function request(
        string $method,
        string $path,
        ?array $body,
        ?string $idempotencyKey = null
    ): array {
        $configuration = $this->configuration();

        $method = strtoupper(trim($method));

        $url = rtrim(
            $configuration['base_url'],
            '/'
        )
            . '/'
            . ltrim($path, '/');

        $headers = [
            'X-App-Key' =>
                $configuration['app_key'],

            'X-Secret-Key' =>
                $configuration['secret_key'],

            'X-Environment' =>
                $configuration['environment'],

            'Accept' =>
                'application/json',

            'Content-Type' =>
                'application/json',
        ];

        if (
            $idempotencyKey !== null
            && trim($idempotencyKey) !== ''
        ) {
            $headers['Idempotency-Key'] =
                trim($idempotencyKey);
        }

        $request = Http::withHeaders($headers)
            ->acceptJson()
            ->connectTimeout(
                $configuration['connect_timeout']
            )
            ->timeout(
                $configuration['timeout']
            );

        $response = match ($method) {
            'POST' => $request->post(
                $url,
                $body ?? []
            ),

            'GET' => $request->get($url),

            default => throw new RuntimeException(
                'Unsupported PAN HTTP method.'
            ),
        };

        return $this->formatResponse(
            $response,
            $url
        );
    }

    private function configuration(): array
    {
        if (! (bool) config(
            'pan.enabled',
            false
        )) {
            throw new RuntimeException(
                'PAN verification integration is disabled.'
            );
        }

        $baseUrl = rtrim(
            trim((string) config(
                'pan.base_url'
            )),
            '/'
        );

        $appKey = trim(
            (string) config(
                'pan.app_key'
            )
        );

        $secretKey = trim(
            (string) config(
                'pan.secret_key'
            )
        );

        $environment = strtolower(
            trim((string) config(
                'pan.environment',
                'uat'
            ))
        );

        if (! in_array(
            $environment,
            [
                'uat',
                'production',
            ],
            true
        )) {
            throw new RuntimeException(
                'PAN environment must be uat or production.'
            );
        }

        if (
            $baseUrl === ''
            || $appKey === ''
            || $secretKey === ''
        ) {
            throw new RuntimeException(
                'PAN API credentials are not configured.'
            );
        }

        if (! filter_var(
            $baseUrl,
            FILTER_VALIDATE_URL
        )) {
            throw new RuntimeException(
                'PAN API base URL is invalid.'
            );
        }

        return [
            'base_url' => $baseUrl,

            'app_key' => $appKey,

            'secret_key' => $secretKey,

            'environment' => $environment,

            'connect_timeout' => max(
                1,
                (int) config(
                    'pan.connect_timeout',
                    10
                )
            ),

            'timeout' => max(
                1,
                (int) config(
                    'pan.timeout',
                    45
                )
            ),
        ];
    }

    private function formatResponse(
        Response $response,
        string $url
    ): array {
        return [
            'http_status' =>
                $response->status(),

            'successful' =>
                $response->successful(),

            'pending' =>
                $response->status() === 202,

            'body' =>
                $this->responseBody(
                    $response
                ),

            // Helpful while debugging.
            // Does not contain API secrets.
            'endpoint' =>
                $url,
        ];
    }

    private function responseBody(
        Response $response
    ): array {
        $decoded = $response->json();

        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            'message' =>
                $response->body(),
        ];
    }
}