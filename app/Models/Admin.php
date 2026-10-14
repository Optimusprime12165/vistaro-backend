<?php

namespace App\Models;

use App\Contracts\ActiveAccount;
use App\Enums\AdminRole;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable implements ActiveAccount
{
    /** @use HasFactory<AdminFactory> */
    use HasApiTokens, HasFactory;

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
