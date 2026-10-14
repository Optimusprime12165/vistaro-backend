<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base for expected, client-facing failures. The renderer turns it into the standard error envelope,
 * so services can throw instead of building responses.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string,mixed> $errors field => messages, or arbitrary structured detail
     * @param array<string,string> $headers
     */
    public function __construct(
        string $message,
        protected int $status = 422,
        protected array $errors = [],
        protected array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
