<?php

namespace App\Enums;

/** Exactly one active Super Admin exists; every other administrative account is Staff. */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Staff = 'staff';
}
