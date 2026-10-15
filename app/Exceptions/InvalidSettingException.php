<?php

namespace App\Exceptions;

/** A business-setting key / type / value was refused. Renders as the standard 422 envelope with errors.value (or the given field). */
class InvalidSettingException extends ApiException
{
    public function __construct(string $message, string $field = 'value')
    {
        parent::__construct($message, 422, [$field => [$message]]);
    }
}
