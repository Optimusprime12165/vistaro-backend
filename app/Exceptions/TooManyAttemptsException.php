<?php

namespace App\Exceptions;

/** 429 with Retry-After. `retry_after` is also placed in errors so the frontend countdown can read it from JSON. */
class TooManyAttemptsException extends ApiException
{
    public function __construct(string $message, int $retryAfterSeconds, array $extra = [])
    {
        $retryAfterSeconds = max(1, $retryAfterSeconds);

        parent::__construct(
            $message,
            429,
            ['retry_after' => $retryAfterSeconds] + $extra,
            ['Retry-After' => (string) $retryAfterSeconds],
        );
    }
}
