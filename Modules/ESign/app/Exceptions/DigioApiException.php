<?php

namespace Modules\ESign\Exceptions;

use Throwable;
use RuntimeException;

class DigioApiException extends RuntimeException
{
    public function __construct(
        string $message = 'Digio API request failed.',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?int $httpStatus = null,
        private readonly ?string $providerCode = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function providerCode(): ?string
    {
        return $this->providerCode;
    }
}