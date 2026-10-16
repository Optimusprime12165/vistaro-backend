<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** NOTE: written without a PHP runtime in the authoring environment; run `php artisan test --filter=CreateSuperAdminCommandTest`. */
class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('VISTARO_SUPER_ADMIN_PASSWORD');   // unset, even when an assertion failed
        parent::tearDown();
    }

    public function test_creates_the_first_super_admin_on_an_empty_database(): void
    {
        putenv('VISTARO_SUPER_ADMIN_PASSWORD=Str0ng!Passw0rd-123');

        $this->artisan('vistaro:create-super-admin', ['name' => 'Owner', 'email' => 'Owner@Example.com'])->assertExitCode(0);

        $admin = Admin::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame(AdminRole::SuperAdmin, $admin->role);
        $this->assertSame(1, (int) $admin->super_admin_slot);

    }

    public function test_refuses_a_second_super_admin(): void
    {
        putenv('VISTARO_SUPER_ADMIN_PASSWORD=Str0ng!Passw0rd-123');
        Admin::factory()->create(['role' => AdminRole::SuperAdmin, 'is_active' => true]);

        $this->artisan('vistaro:create-super-admin', ['name' => 'Other', 'email' => 'other@example.com'])->assertExitCode(1);
        $this->assertSame(1, Admin::query()->where('role', 'super_admin')->count());

    }

    public function test_rejects_a_short_password(): void
    {
        putenv('VISTARO_SUPER_ADMIN_PASSWORD=short');

        $this->artisan('vistaro:create-super-admin', ['name' => 'Owner', 'email' => 'owner@example.com'])->assertExitCode(1);
        $this->assertDatabaseCount('admins', 0);

    }
}
