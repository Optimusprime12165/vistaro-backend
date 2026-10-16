<?php

namespace Tests\Feature\Fulfilment;

use App\Enums\AdminRole;
use App\Models\Address;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/**
 * Phase 8 addition: GET /api/v1/admin/customers/{id}/addresses (read-only, admin only).
 * NOTE: written against the existing CatalogTestHelpers; NOT executed by the author (no PHP runtime in the authoring environment).
 */
class AdminCustomerAddressesTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    public function test_requires_an_admin_token_and_rejects_staff(): void
    {
        [$user] = $this->shopper();

        $this->guest()->getJson("/api/v1/admin/customers/{$user->id}/addresses")->assertStatus(401);
        $this->actingAsCustomer($user)->getJson("/api/v1/admin/customers/{$user->id}/addresses")->assertStatus(401);
        $this->actingAsAdmin(AdminRole::Staff)->getJson("/api/v1/admin/customers/{$user->id}/addresses")->assertStatus(403);
    }

    public function test_admin_sees_only_that_customers_addresses_default_first(): void
    {
        [$user, $default] = $this->shopper();
        $other = Address::factory()->create(['user_id' => $user->id, 'is_default' => false]);
        [, $strangers] = $this->shopper();

        $res = $this->actingAsAdmin(AdminRole::SuperAdmin)->getJson("/api/v1/admin/customers/{$user->id}/addresses")->assertOk();

        $ids = array_column($res->json('data'), 'id');
        $this->assertSame([$default->id, $other->id], $ids);
        $this->assertNotContains($strangers->id, $ids);
        $res->assertJsonStructure(['data' => [['id', 'type', 'name', 'phone', 'line1', 'city', 'state', 'pincode', 'is_default']]]);
    }

    public function test_unknown_customer_is_a_404(): void
    {
        $this->actingAsAdmin(AdminRole::SuperAdmin)->getJson('/api/v1/admin/customers/999999/addresses')->assertStatus(404);
    }
}
