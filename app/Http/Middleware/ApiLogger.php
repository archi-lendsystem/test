<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiLogger
{
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        $requestId = $request->header('X-Request-ID') ?? Str::uuid();

        // Attach request_id globally
        app()->instance('request_id', $requestId);

        $response = $next($request);

        $response->headers->set('X-Request-ID', $requestId);

        $end = microtime(true);

        try {
            ApiLog::create([
                'request_id' => $requestId,
                'endpoint' => $request->path(),
                'method' => $request->method(),
                // 'user_id' => auth()->id(),
                'user_id' => optional($request->user())->id,
                'ip_address' => $request->ip(),

                // ⚠️ sanitize sensitive fields
                'request_payload' => $this->sanitize($request->all()),
                'response_payload' => $this->sanitize(
                    json_decode($response->getContent(), true)
                ),

                'status_code' => $response->getStatusCode(),
                'response_time' => round(($end - $start) * 1000, 2),
            ]);

        } catch (\Exception $e) {
            // Never break API if logging fails
        }

        return $response;
    }

    private function sanitize($data)
    {
        if (! is_array($data)) {
            return $data;
        }

        $sensitive = ['password', 'token', 'access_token'];

        foreach ($sensitive as $field) {
            if (isset($data[$field])) {
                $data[$field] = '***';
            }
        }

        return $data;
    }
}
