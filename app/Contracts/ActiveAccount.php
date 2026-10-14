<?php

namespace App\Contracts;

/** Implemented by Admin and User so middleware can reject suspended accounts even with a valid token. */
interface ActiveAccount
{
    public function isActive(): bool;
}
