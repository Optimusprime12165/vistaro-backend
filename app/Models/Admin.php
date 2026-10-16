<?php

namespace App\Models;

use App\Contracts\ActiveAccount;
use App\Enums\AdminRole;
use App\Exceptions\ConflictException;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Role model: exactly ONE active Super Admin, everybody else is Staff.
 *
 * Invariant, enforced twice:
 *  1. database: `super_admin_slot` is 1 only for the active super admin (NULL otherwise) and carries a UNIQUE index,
 *  2. model events: the active Super Admin can never be demoted, deactivated or deleted, and a second active Super Admin
 *     can never be created (see StaffService::transferSuperAdmin() for the one controlled hand-over path).
 */
class Admin extends Authenticatable implements ActiveAccount
{
    /** @use HasFactory<AdminFactory> */
    use HasApiTokens, HasFactory;

    /** `super_admin_slot` and `created_by` are deliberately NOT mass-assignable. */
    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'last_login_at', 'last_login_ip'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => AdminRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Admin $admin) {
            $isActiveSuper = $admin->role === AdminRole::SuperAdmin && $admin->is_active;

            if ($admin->exists && $admin->getOriginal('role') === AdminRole::SuperAdmin && (bool) $admin->getOriginal('is_active')
                && ! $isActiveSuper) {
                throw new ConflictException('The Super Admin cannot be demoted or deactivated.');
            }

            if ($isActiveSuper) {
                $other = static::query()->where('super_admin_slot', 1)->when($admin->exists, fn ($q) => $q->whereKeyNot($admin->getKey()))->exists();
                if ($other) {
                    throw new ConflictException('There can be only one active Super Admin.');
                }
            }

            $admin->super_admin_slot = $isActiveSuper ? 1 : null;
        });

        static::deleting(function (Admin $admin) {
            if ($admin->role === AdminRole::SuperAdmin && $admin->is_active) {
                throw new ConflictException('The Super Admin cannot be deleted.');
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === AdminRole::SuperAdmin;
    }

    /** super_admin satisfies every role check. */
    public function hasRole(AdminRole ...$roles): bool
    {
        return $this->role === AdminRole::SuperAdmin || in_array($this->role, $roles, true);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
