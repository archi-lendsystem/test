<?php

namespace Modules\ESign\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ESignDomainException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 409,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'esign_error',
        ], $this->statusCode);
    }
}