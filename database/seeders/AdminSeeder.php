<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ONLY dummy admin:  admin@vistaro.local / ChangeMe@123
 * Refuses to run outside local/testing, and never modifies an admin that already exists
 * (so it cannot overwrite real credentials).
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('AdminSeeder skipped: dummy admin is for local/testing only.');
            return;
        }

        Admin::query()->firstOrCreate(
            ['email' => 'admin@vistaro.local'],
            ['name' => 'Dev Admin', 'password' => 'ChangeMe@123', 'role' => 'super_admin', 'is_active' => true], // hashed by cast
        );
    }
}
