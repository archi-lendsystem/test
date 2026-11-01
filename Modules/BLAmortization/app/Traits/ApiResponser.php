<?php

namespace Modules\BLAmortization\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;


trait ApiResponser
{
 
    protected function generateRequestCode(): string
    {
        return 'REQ-' . strtoupper(Str::random(8)) . '-' . now()->format('YmdHis');
    }

    protected function successResponse($data = null, string $message = 'Success', int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'status'       => true,
            'status_code'  => $statusCode,
            'request_code' => $this->generateRequestCode(),
            'timestamp'    => now()->toIso8601String(),
            'message'      => $message,
            'data'         => $data,
        ], $statusCode);
    }

    protected function errorResponse(string $message = 'Something went wrong', int $statusCode = 400, $errors = null): JsonResponse
    {
        return response()->json([
            'status'       => false,
            'status_code'  => $statusCode,
            'request_code' => $this->generateRequestCode(),
            'timestamp'    => now()->toIso8601String(),
            'message'      => $message,
            'errors'       => $errors,
        ], $statusCode);
    }

    protected function validationErrorResponse($errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->errorResponse($message, 422, $errors);
    }

    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }
}