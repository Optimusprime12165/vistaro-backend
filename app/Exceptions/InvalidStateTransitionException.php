<?php

namespace App\Exceptions;

class InvalidStateTransitionException extends ApiException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("Cannot change status from '{$from}' to '{$to}'.", 422, [
            'status' => ["Transition {$from} -> {$to} is not allowed."],
        ]);
    }
}
