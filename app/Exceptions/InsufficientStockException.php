<?php

namespace App\Exceptions;

class InsufficientStockException extends ApiException
{
    /** @param array<string,mixed> $errors e.g. ["items.0" => ["Only 2 left in size M"]] */
    public function __construct(string $message = 'Insufficient stock.', array $errors = [])
    {
        parent::__construct($message, 409, $errors);
    }
}
