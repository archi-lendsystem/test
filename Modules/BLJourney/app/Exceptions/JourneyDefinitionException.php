<?php

namespace Modules\BLJourney\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class JourneyDefinitionException extends HttpException
{
    public function __construct(
        public string $errorCode,
        string $message,
        int $statusCode = 409
    ) {
        parent::__construct($statusCode, $message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'data' => null,
        ], $this->getStatusCode());
    }
}