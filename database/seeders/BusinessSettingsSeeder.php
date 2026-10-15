<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use App\Services\Settings\BusinessSettingsService;
use App\Services\Settings\SettingDefinitions;
use Illuminate\Database\Seeder;

/**
 * Creates the settings the application reads, initialised to the EXISTING behaviour (shipping Rs 99, free from Rs 1999,
 * 14-day return window, or whatever .env overrides). Idempotent and never overwrites a value an admin has changed.
 */
class BusinessSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SettingDefinitions::all() as $def) {
            BusinessSetting::query()->firstOrCreate(['key' => $def->key], [
                'value' => (string) $def->fallback(), 'type' => $def->type, 'group' => $def->group,
                'description' => $def->description, 'is_public' => $def->isPublic,
            ]);
        }
        app(BusinessSettingsService::class)->flush();
    }
}
