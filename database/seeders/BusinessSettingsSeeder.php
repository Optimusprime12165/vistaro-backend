<?php

namespace Database\Seeders;

use App\Enums\SettingType;
use App\Enums\Size;
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
        // Free-form (non-managed) setting: which sizes the store offers. Value is compact JSON; edit it in Business Settings.
        BusinessSetting::query()->firstOrCreate(['key' => 'catalog.sizes'], [
            'value' => json_encode(Size::values(), JSON_UNESCAPED_SLASHES), 'type' => SettingType::Json, 'group' => 'catalog',
            'description' => 'Sizes the store offers. Admins pick from these when creating products and managing stock.', 'is_public' => true,
        ]);
        app(BusinessSettingsService::class)->flush();
    }
}
