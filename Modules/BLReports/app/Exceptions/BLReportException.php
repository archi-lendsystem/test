<?php

namespace Modules\BLReports\Exceptions;

use Throwable;

class BLReportException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 422,
        private readonly string $errorCode = 'BL_REPORT_ERROR',
        private readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }
}