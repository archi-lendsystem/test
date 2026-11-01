<?php

namespace Modules\ESign\Exceptions;

class ProviderException extends ESignDomainException
{
    public function __construct(
        string $message = 'The eSign provider request failed.',
        private readonly int $httpStatusCode = 502,
    ) {
        parent::__construct($message, $httpStatusCode > 0 ? $httpStatusCode : 502);
    }

    public function httpStatus(): int
    {
        return $this->httpStatusCode;
    }
}
