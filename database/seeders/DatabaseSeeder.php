<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Safe by environment:
 *  - CategorySeeder    : reference data, idempotent, never overwrites existing rows -> every environment
 *  - AdminSeeder       : dummy admin -> local/testing ONLY
 *  - DevProductSeeder  : the 32 demo products -> local/testing ONLY
 * No seeder ever creates customers, orders, payments, shipments or login events.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CategorySeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call([AdminSeeder::class, DevProductSeeder::class]);
        } else {
            $this->command?->info('Non-development environment: skipped dummy admin and demo products.');
        }
    }
}
