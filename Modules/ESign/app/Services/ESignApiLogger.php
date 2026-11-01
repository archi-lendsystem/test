<?php

namespace Modules\ESign\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\ESign\Models\ESignLog;
use Modules\ESign\Models\ESignSession;
use Throwable;

final class ESignApiLogger
{
    public function __construct(private readonly LogSanitizer $sanitizer)
    {
    }

    public function record(
        ?ESignSession $session,
        string $action,
        string $method,
        string $endpoint,
        mixed $requestPayload,
        mixed $responsePayload,
        ?int $statusCode,
        int $durationMs,
        ?string $error = null,
    ): void {
        try {
            ESignLog::query()->create([
                'esign_session_id' => $session?->getKey(),
                'correlation_id' => (string) Str::uuid(),
                'direction' => 'outbound',
                'action' => $action,
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'request_payload' => $this->sanitizer->sanitize($requestPayload),
                'response_payload' => $this->sanitizer->sanitize($responsePayload),
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
                'error' => $error === null ? null : mb_substr($error, 0, 2000),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Unable to persist eSign API audit log.', [
                'action' => $action,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
