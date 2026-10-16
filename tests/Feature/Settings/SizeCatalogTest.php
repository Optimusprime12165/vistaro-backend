<?php

namespace Tests\Feature\Settings;

use App\Enums\SettingType;
use App\Enums\Size;
use App\Models\BusinessSetting;
use App\Services\Settings\BusinessSettingsService;
use App\Support\SizeCatalog;
use Database\Seeders\BusinessSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8 addition: the `catalog.sizes` business setting drives which sizes admins can add to products.
 * NOTE: written without a PHP runtime in the authoring environment; run `php artisan test --filter=SizeCatalogTest`.
 */
class SizeCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_sizes_run_xxxs_to_4xl_in_order(): void
    {
        $this->assertSame(['XXXS', 'XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL'], Size::values());
    }

    public function test_without_a_setting_every_supported_size_is_enabled(): void
    {
        $this->assertSame(Size::values(), SizeCatalog::enabled());
    }

    public function test_setting_limits_and_orders_enabled_sizes_and_drops_unknown_codes(): void
    {
        BusinessSetting::query()->create([
            'key' => SizeCatalog::KEY, 'value' => json_encode(['L', 'XS', 'FOO']), 'type' => SettingType::Json,
            'group' => 'catalog', 'description' => 'test', 'is_public' => true,
        ]);
        app(BusinessSettingsService::class)->flush();

        $this->assertSame(['XS', 'L'], SizeCatalog::enabled());
    }

    public function test_seeder_creates_a_public_catalog_sizes_setting(): void
    {
        $this->seed(BusinessSettingsSeeder::class);

        $this->assertSame(Size::values(), SizeCatalog::enabled());
        $res = $this->getJson('/api/v1/settings')->assertOk();
        $this->assertSame(Size::values(), $res->json('data.catalog')['catalog.sizes'] ?? null);
    }
}
