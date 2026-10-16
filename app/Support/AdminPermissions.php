<?php

namespace App\Support;

use App\Enums\AdminRole;

/**
 * Permission names exposed to the admin UI so it can hide buttons the account may not use.
 * This list is ADVISORY for the frontend only: the real authorisation is the `admin.role` route middleware
 * and the service-level checks, which never trust the client.
 */
final class AdminPermissions
{
    private const STAFF = ['stocks.view', 'stocks.adjust', 'orders.view', 'orders.fulfil', 'categories.view'];

    private const SUPER = [
        'stocks.view', 'stocks.adjust', 'orders.view', 'orders.fulfil', 'categories.view',
        'products.manage', 'sizes.manage', 'categories.manage', 'staff.manage',
        'orders.cancel', 'orders.refund', 'customers.view', 'settings.manage',
    ];

    /** @return list<string> */
    public static function for(AdminRole $role): array
    {
        return $role === AdminRole::SuperAdmin ? self::SUPER : self::STAFF;
    }
}
