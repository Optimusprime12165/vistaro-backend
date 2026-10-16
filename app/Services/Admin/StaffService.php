<?php

namespace App\Services\Admin;

use App\Abstracts\AbstractCrudService;
use App\Enums\AdminRole;
use App\Exceptions\ConflictException;
use App\Models\Admin;
use App\Services\Auth\TokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Staff Management (Super Admin only; the routes are behind `admin.role:super_admin`).
 * Rules enforced here AND in the Admin model / database:
 *  - accounts are only ever created as Staff (no public registration, no role input),
 *  - the Super Admin account can not be edited, deactivated, demoted or deleted through these methods,
 *  - deactivating or resetting an account revokes its tokens immediately (inactive accounts are also rejected by EnsurePrincipal).
 */
final class StaffService extends AbstractCrudService
{
    public function __construct(private readonly TokenService $tokens)
    {
    }

    protected function modelClass(): string
    {
        return Admin::class;
    }

    protected function searchable(): array
    {
        return ['name', 'email'];
    }

    protected function searchContains(): bool
    {
        return true;               // handful of accounts
    }

    protected function sortable(): array
    {
        return ['id' => 'id', 'name' => 'name', 'email' => 'email', 'last_login_at' => 'last_login_at', 'created_at' => 'created_at'];
    }

    protected function filterable(): array
    {
        return [
            'is_active' => fn (Builder $q, $v) => $q->where('is_active', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
            'role' => 'role',
        ];
    }

    protected function with(): array
    {
        return ['creator:id,name'];
    }

    protected function defaultSort(): array
    {
        return ['id', 'asc'];
    }

    public function create(array $data, Admin $actor): Admin
    {
        $admin = new Admin(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password'], 'role' => AdminRole::Staff, 'is_active' => true]);
        $admin->created_by = $actor->id;
        $admin->save();

        return $admin->load('creator:id,name');
    }

    public function updateStaff(Admin $admin, array $data): Admin
    {
        $this->assertStaff($admin);
        $admin->fill(array_intersect_key($data, array_flip(['name', 'email'])))->save();

        return $admin->refresh()->load('creator:id,name');
    }

    public function deactivate(Admin $admin): Admin
    {
        $this->assertStaff($admin);

        DB::transaction(function () use ($admin) {
            $admin->forceFill(['is_active' => false])->save();
            $this->tokens->revokeAll($admin);                       // existing sessions stop working immediately
        });

        return $admin->refresh()->load('creator:id,name');
    }

    public function activate(Admin $admin): Admin
    {
        $this->assertStaff($admin);
        $admin->forceFill(['is_active' => true])->save();

        return $admin->refresh()->load('creator:id,name');
    }

    /** New password (hashed by the model cast); every existing session of that account is signed out. */
    public function resetPassword(Admin $admin, string $password): void
    {
        $this->assertStaff($admin);

        DB::transaction(function () use ($admin, $password) {
            $admin->forceFill(['password' => $password])->save();
            $this->tokens->revokeAll($admin);
        });
    }

    /**
     * The ONE controlled way to change who the Super Admin is (console command `vistaro:transfer-super-admin`, not an API).
     * Done with direct updates in one transaction so the "exactly one active Super Admin" invariant holds before and after.
     */
    public function transferSuperAdmin(Admin $to): void
    {
        DB::transaction(function () use ($to) {
            $current = Admin::query()->where('super_admin_slot', 1)->lockForUpdate()->first();
            if ($current !== null && $current->is($to)) {
                return;
            }

            if ($current !== null) {
                DB::table('admins')->where('id', $current->id)->update(['role' => AdminRole::Staff->value, 'super_admin_slot' => null, 'updated_at' => now()]);
                $this->tokens->revokeAll($current);
            }
            DB::table('admins')->where('id', $to->id)->update(['role' => AdminRole::SuperAdmin->value, 'is_active' => true, 'super_admin_slot' => 1, 'updated_at' => now()]);
            $this->tokens->revokeAll($to);
        });
    }

    private function assertStaff(Admin $admin): void
    {
        if ($admin->isSuperAdmin()) {
            throw new ConflictException('The Super Admin account cannot be changed here.');
        }
    }
}
