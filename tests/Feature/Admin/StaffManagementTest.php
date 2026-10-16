<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Services\Admin\StaffService;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/**
 * One Super Admin, everybody else Staff: permissions, staff CRUD, protection of the Super Admin.
 * NOTE: written without a PHP runtime in the authoring environment; run `php artisan test --filter=StaffManagementTest`.
 */
class StaffManagementTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd';

    private function newStaff(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsAdmin()->postJson('/api/v1/admin/staff', $over + ['name' => 'Asha Staff', 'email' => 'Asha@Vistaro.local', 'password' => self::PASSWORD]);
    }

    // ---------------------------------------------------------------- permissions

    public function test_only_the_super_admin_can_open_staff_management(): void
    {
        $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/staff')->assertStatus(403);
        $this->actingAsAdmin(AdminRole::Staff)->postJson('/api/v1/admin/staff', ['name' => 'X Y', 'email' => 'x@y.com', 'password' => self::PASSWORD])->assertStatus(403);
        $this->guest()->getJson('/api/v1/admin/staff')->assertStatus(401);
        $this->actingAsAdmin()->getJson('/api/v1/admin/staff')->assertOk();
    }

    public function test_staff_cannot_use_super_admin_only_features(): void
    {
        $p = $this->makeProduct(['M' => 3]);
        $cat = \App\Models\Category::factory()->create();
        $staff = fn () => $this->actingAsAdmin(AdminRole::Staff);

        $staff()->postJson('/api/v1/admin/products', ['name' => 'x'])->assertStatus(403);
        $staff()->patchJson("/api/v1/admin/products/{$p->id}", ['name' => 'Renamed'])->assertStatus(403);
        $staff()->deleteJson("/api/v1/admin/products/{$p->id}")->assertStatus(403);
        $staff()->postJson('/api/v1/admin/categories', ['name' => 'Nope'])->assertStatus(403);
        $staff()->patchJson("/api/v1/admin/categories/{$cat->id}", ['name' => 'Nope'])->assertStatus(403);
        $staff()->deleteJson("/api/v1/admin/categories/{$cat->id}")->assertStatus(403);
        $staff()->postJson('/api/v1/admin/sub-categories', ['name' => 'Nope', 'category_id' => $cat->id])->assertStatus(403);
        $this->assertDatabaseHas('categories', ['id' => $cat->id]);
        $staff()->getJson('/api/v1/admin/settings')->assertStatus(403);
        $staff()->getJson('/api/v1/admin/customers')->assertStatus(403);
    }

    public function test_staff_can_do_what_the_job_needs(): void
    {
        $p = $this->makeProduct(['M' => 3]);
        $staff = fn () => $this->actingAsAdmin(AdminRole::Staff);

        $staff()->getJson('/api/v1/admin/inventory')->assertOk();
        $staff()->getJson('/api/v1/admin/categories')->assertOk();
        $staff()->getJson('/api/v1/admin/orders')->assertOk();
        $staff()->postJson("/api/v1/admin/products/{$p->id}/stock/adjust", ['size' => 'M', 'delta' => -1, 'note' => 'sold in shop'])->assertOk();
    }

    public function test_me_reports_role_and_permissions_for_the_ui(): void
    {
        $staff = $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/me')->assertOk()->json('data');
        $super = $this->actingAsAdmin()->getJson('/api/v1/admin/me')->assertOk()->json('data');

        $this->assertSame('staff', $staff['role']);
        $this->assertContains('stocks.adjust', $staff['permissions']);
        $this->assertNotContains('staff.manage', $staff['permissions']);
        $this->assertNotContains('products.manage', $staff['permissions']);
        $this->assertSame('super_admin', $super['role']);
        $this->assertContains('staff.manage', $super['permissions']);
    }

    // ---------------------------------------------------------------- CRUD

    public function test_super_admin_creates_staff_with_a_hashed_password_and_no_credentials_in_the_response(): void
    {
        $res = $this->newStaff()->assertCreated();

        $res->assertJsonPath('data.role', 'staff')->assertJsonPath('data.email', 'asha@vistaro.local')->assertJsonPath('data.is_active', true);
        $this->assertStringNotContainsString(self::PASSWORD, $res->getContent());
        $this->assertArrayNotHasKey('password', $res->json('data'));

        $admin = Admin::query()->where('email', 'asha@vistaro.local')->firstOrFail();
        $this->assertSame(AdminRole::Staff, $admin->role);
        $this->assertNotSame(self::PASSWORD, $admin->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertNotNull($admin->created_by);

        // the new staff member can sign in
        $this->guest()->postJson('/api/v1/admin/auth/login', ['email' => 'asha@vistaro.local', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.admin.role', 'staff');
    }

    public function test_staff_creation_validates_input_and_cannot_set_a_role(): void
    {
        $this->newStaff(['password' => 'weak'])->assertStatus(422);
        $this->newStaff(['email' => 'not-an-email'])->assertStatus(422);
        $this->newStaff(['role' => 'super_admin'])->assertStatus(422);
        $this->newStaff(['is_active' => false])->assertStatus(422);

        $this->newStaff()->assertCreated();
        $this->newStaff(['email' => 'ASHA@vistaro.local'])->assertStatus(422);                                // duplicate e-mail (case-insensitive)
        $this->assertSame(1, Admin::query()->where('role', 'super_admin')->count(), 'creating staff never creates a second Super Admin');
    }

    public function test_list_search_and_details(): void
    {
        $this->newStaff(['name' => 'Asha Staff', 'email' => 'asha@vistaro.local'])->assertCreated();
        $id = $this->newStaff(['name' => 'Ravi Kumar', 'email' => 'ravi@vistaro.local'])->assertCreated()->json('data.id');

        $list = $this->actingAsAdmin()->getJson('/api/v1/admin/staff?q=ravi')->assertOk();
        $this->assertSame([$id], array_column($list->json('data'), 'id'));
        $this->assertArrayHasKey('total', $list->json('meta'));

        $one = $this->actingAsAdmin()->getJson("/api/v1/admin/staff/{$id}")->assertOk()->json('data');
        $this->assertSame('Staff', $one['role_label']);
        $this->assertSame('active', $one['status']);
        $this->assertArrayHasKey('created_by', $one);
        $this->assertArrayNotHasKey('password', $one);
    }

    public function test_update_name_and_email_but_never_role(): void
    {
        $id = $this->newStaff()->assertCreated()->json('data.id');

        $this->actingAsAdmin()->patchJson("/api/v1/admin/staff/{$id}", ['name' => 'Asha K', 'email' => 'asha.k@vistaro.local'])->assertOk()->assertJsonPath('data.name', 'Asha K');
        $this->actingAsAdmin()->patchJson("/api/v1/admin/staff/{$id}", ['role' => 'super_admin'])->assertStatus(422);
        $this->assertSame(AdminRole::Staff, Admin::findOrFail($id)->role);
    }

    // ---------------------------------------------------------------- activation / reset

    public function test_deactivated_staff_cannot_log_in_and_their_tokens_stop_working(): void
    {
        $id = $this->newStaff()->assertCreated()->json('data.id');
        $staff = Admin::findOrFail($id);
        $plain = app(TokenService::class)->issue($staff, 'admin', 60, 't')->plainTextToken;
        $this->guest()->withToken($plain)->getJson('/api/v1/admin/me')->assertOk();

        $this->actingAsAdmin()->postJson("/api/v1/admin/staff/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $staff->tokens()->count());
        $this->guest()->withToken($plain)->getJson('/api/v1/admin/me')->assertStatus(401);
        $this->guest()->postJson('/api/v1/admin/auth/login', ['email' => 'asha@vistaro.local', 'password' => self::PASSWORD])->assertStatus(403);

        $this->actingAsAdmin()->postJson("/api/v1/admin/staff/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->guest()->postJson('/api/v1/admin/auth/login', ['email' => 'asha@vistaro.local', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_password_reset_signs_the_account_out_and_the_new_password_works(): void
    {
        $id = $this->newStaff()->assertCreated()->json('data.id');
        $staff = Admin::findOrFail($id);
        app(TokenService::class)->issue($staff, 'admin', 60, 't');

        $this->actingAsAdmin()->putJson("/api/v1/admin/staff/{$id}/password", ['password' => 'weak'])->assertStatus(422);
        $this->actingAsAdmin()->putJson("/api/v1/admin/staff/{$id}/password", ['password' => 'An0ther!Passw0rd'])->assertOk();

        $this->assertSame(0, $staff->tokens()->count());
        $this->guest()->postJson('/api/v1/admin/auth/login', ['email' => 'asha@vistaro.local', 'password' => self::PASSWORD])->assertStatus(401);
        $this->guest()->postJson('/api/v1/admin/auth/login', ['email' => 'asha@vistaro.local', 'password' => 'An0ther!Passw0rd'])->assertOk();
    }

    // ---------------------------------------------------------------- Super Admin protection

    public function test_the_super_admin_cannot_be_deactivated_edited_or_reset_through_the_api(): void
    {
        $super = Admin::factory()->superAdmin()->create();

        $this->actingAsAdmin()->postJson("/api/v1/admin/staff/{$super->id}/deactivate")->assertStatus(409);
        $this->actingAsAdmin()->patchJson("/api/v1/admin/staff/{$super->id}", ['name' => 'Hijacked'])->assertStatus(409);
        $this->actingAsAdmin()->putJson("/api/v1/admin/staff/{$super->id}/password", ['password' => 'An0ther!Passw0rd'])->assertStatus(409);

        $super->refresh();
        $this->assertTrue($super->is_active);
        $this->assertSame(AdminRole::SuperAdmin, $super->role);
    }

    public function test_there_can_only_ever_be_one_active_super_admin(): void
    {
        Admin::factory()->superAdmin()->create();

        $this->expectException(\App\Exceptions\ConflictException::class);
        Admin::factory()->superAdmin()->create();
    }

    public function test_staff_cannot_be_promoted_to_super_admin_while_one_exists(): void
    {
        Admin::factory()->superAdmin()->create();
        $staff = Admin::factory()->create();

        $this->expectException(\App\Exceptions\ConflictException::class);
        $staff->forceFill(['role' => AdminRole::SuperAdmin])->save();
    }

    public function test_the_last_active_super_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $super = Admin::factory()->superAdmin()->create();

        foreach ([
            fn () => $super->forceFill(['role' => AdminRole::Staff])->save(),
            fn () => $super->forceFill(['is_active' => false])->save(),
            fn () => $super->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('the Super Admin must be protected');
            } catch (\App\Exceptions\ConflictException) {
                $this->assertTrue(true);
            }
            $super->refresh();
        }

        $this->assertSame(1, Admin::query()->where('role', 'super_admin')->where('is_active', true)->count());
    }

    public function test_the_super_admin_role_can_be_handed_over_in_one_controlled_step(): void
    {
        $old = Admin::factory()->superAdmin()->create();
        $new = Admin::factory()->create();

        app(StaffService::class)->transferSuperAdmin($new);

        $this->assertSame(AdminRole::Staff, $old->refresh()->role);
        $this->assertSame(AdminRole::SuperAdmin, $new->refresh()->role);
        $this->assertSame(1, Admin::query()->where('role', 'super_admin')->where('is_active', true)->count());
    }

    public function test_there_is_no_public_way_to_register_an_admin(): void
    {
        foreach (['register', 'auth/register', 'staff/register'] as $path) {
            $this->assertContains($this->guest()->postJson("/api/v1/admin/{$path}", ['name' => 'Evil', 'email' => 'evil@x.com', 'password' => self::PASSWORD])->status(), [401, 404, 405]);
        }
        $this->assertSame(0, Admin::query()->where('email', 'evil@x.com')->count());
    }
}
