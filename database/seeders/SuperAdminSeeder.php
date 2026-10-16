<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Database\Seeder;

/**
 * Creates the ONE Super Admin from environment variables (works in every environment, including production):
 *   SUPER_ADMIN_NAME, SUPER_ADMIN_EMAIL, SUPER_ADMIN_PASSWORD
 * No credentials are hard-coded. Idempotent: does nothing if an active Super Admin already exists, and never
 * touches an existing account. If the variables are missing it only prints a warning.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (Admin::query()->where('super_admin_slot', 1)->exists()) {
            $this->command?->info('SuperAdminSeeder skipped: an active Super Admin already exists.');

            return;
        }

        $name = (string) env('SUPER_ADMIN_NAME', 'Super Admin');
        $email = mb_strtolower((string) env('SUPER_ADMIN_EMAIL', ''));
        $password = (string) env('SUPER_ADMIN_PASSWORD', '');

        if ($email === '' || mb_strlen($password) < 12) {
            $this->command?->warn('SuperAdminSeeder skipped: set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD (min 12 chars) in .env.');

            return;
        }

        if (Admin::query()->where('email', $email)->exists()) {
            $this->command?->warn("SuperAdminSeeder skipped: {$email} already exists (use vistaro:transfer-super-admin to promote it).");

            return;
        }

        Admin::query()->create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => AdminRole::SuperAdmin, 'is_active' => true]);   // hashed by the model cast

        $this->command?->info("Super Admin {$email} created.");
    }
}
