<?php

namespace Modules\Repayment\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

trait ApiResponser
{
    /**
     * Generate a unique API request reference.
     */
    protected function generateRequestCode(): string
    {
        return sprintf(
            'REQ-%s-%s',
            strtoupper(Str::random(8)),
            now()->format('YmdHis')
        );
    }

    /**
     * Return a successful JSON response.
     */
    protected function successResponse(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'status' => true,
            'status_code' => $statusCode,
            'request_code' => $this->generateRequestCode(),
            'timestamp' => now()->toIso8601String(),
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Return an error JSON response.
     */
    protected function errorResponse(
        string $message = 'Something went wrong',
        int $statusCode = 400,
        mixed $errors = null
    ): JsonResponse {
        return response()->json([
            'status' => false,
            'status_code' => $statusCode,
            'request_code' => $this->generateRequestCode(),
            'timestamp' => now()->toIso8601String(),
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }

    /**
     * Return a validation-error response.
     */
    protected function validationErrorResponse(
        mixed $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return $this->errorResponse(
            message: $message,
            statusCode: 422,
            errors: $errors
        );
    }

    /**
     * Return a resource-not-found response.
     */
    protected function notFoundResponse(
        string $message = 'Resource not found'
    ): JsonResponse {
        return $this->errorResponse(
            message: $message,
            statusCode: 404
        );
    }

    /**
     * Return a conflict response.
     */
    protected function conflictResponse(
        string $message,
        mixed $errors = null
    ): JsonResponse {
        return $this->errorResponse(
            message: $message,
            statusCode: 409,
            errors: $errors
        );
    }
}