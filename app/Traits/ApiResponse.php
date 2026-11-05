<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Success Response
     */
    public function success(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        string $code = 'SUCCESS'
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'status_code' => $status,
            'code' => $code,
            'message' => $message,
            'request_id' => app('request_id'),
            'data' => $data,
        ], $status);
    }

    /**
     * Error Response
     */
    public function error(
        string $message = 'Something went wrong',
        mixed $errors = null,
        int $status = 400,
        string $code = 'ERROR'
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'status_code' => $status,
            'code' => $code,
            'message' => $message,
            'request_id' => app('request_id'),
            'errors' => $errors,
        ], $status);
    }

    /**
     * Backward compatibility
     */
    public function successResponse(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        string $code = 'SUCCESS'
    ): JsonResponse {
        return $this->success($data, $message, $status, $code);
    }

    /**
     * Backward compatibility
     */
    public function errorResponse(
        string $message = 'Something went wrong',
        mixed $errors = null,
        int $status = 400,
        string $code = 'ERROR'
    ): JsonResponse {
        return $this->error($message, $errors, $status, $code);
    }
}