<?php

namespace App\Exceptions;

/** 409: the request is valid but conflicts with current state (duplicate, in-use, idempotency clash). */
class ConflictException extends ApiException
{
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message, 409, $errors);
    }
}
