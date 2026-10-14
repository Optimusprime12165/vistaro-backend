<?php

namespace Tests\Feature\Commerce;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function payload(array $o = []): array
    {
        return $o + ['type' => 'Home', 'name' => 'Asha Rao', 'phone' => '98765 43210', 'line1' => '12 MI Road', 'landmark' => 'Near the park', 'city' => 'Jaipur', 'state' => 'Rajasthan', 'pincode' => '302001'];
    }

    private function me(): static
    {
        return $this->actingAsCustomer($this->user);
    }

    private function defaults(?User $u = null): int
    {
        return Address::where('user_id', ($u ?? $this->user)->id)->where('is_default', true)->count();
    }

    public function test_addresses_require_a_customer_token(): void
    {
        $a = Address::factory()->for($this->user)->create();

        foreach ([['get', '/api/v1/addresses'], ['post', '/api/v1/addresses'], ['get', "/api/v1/addresses/{$a->id}"], ['patch', "/api/v1/addresses/{$a->id}"],
            ['delete', "/api/v1/addresses/{$a->id}"], ['patch', "/api/v1/addresses/{$a->id}/default"]] as [$m, $url]) {
            $this->guest()->json($m, $url, [])->assertUnauthorized();
            $this->actingAsAdmin()->json($m, $url, [])->assertUnauthorized();
        }
    }

    public function test_first_address_becomes_default_and_values_are_normalised(): void
    {
        $r = $this->me()->postJson('/api/v1/addresses', $this->payload())->assertCreated()
            ->assertJsonPath('data.is_default', true)->assertJsonPath('data.type', 'home')->assertJsonPath('data.phone', '+919876543210')
            ->assertJsonPath('data.pincode', '302001')->assertJsonPath('data.landmark', 'Near the park');
        $this->assertSame($this->user->id, Address::findOrFail($r->json('data.id'))->user_id, 'owner comes from the token, never from the request');

        $second = $this->me()->postJson('/api/v1/addresses', $this->payload(['type' => 'work', 'user_id' => 999, 'landmark' => null]))->assertCreated()->assertJsonPath('data.is_default', false);
        $this->assertSame($this->user->id, Address::findOrFail($second->json('data.id'))->user_id);
        $this->assertSame(1, $this->defaults());
    }

    public function test_address_validation(): void
    {
        $bad = [
            'missing everything' => [], 'short name' => ['name' => 'A'], 'bad phone' => ['phone' => '12345'], 'phone letters' => ['phone' => 'abcdefghij'],
            'pincode 5 digits' => ['pincode' => '30200'], 'pincode 7 digits' => ['pincode' => '3020011'], 'pincode leading zero' => ['pincode' => '012345'],
            'pincode letters' => ['pincode' => 'ABC123'], 'bad type' => ['type' => 'office'], 'short line' => ['line1' => 'ab'], 'no city' => ['city' => ''],
            'no state' => ['state' => ''], 'long landmark' => ['landmark' => str_repeat('a', 256)], 'default not bool' => ['is_default' => 'maybe'],
        ];
        foreach ($bad as $label => $o) {
            $body = $label === 'missing everything' ? [] : $this->payload($o);
            $this->me()->postJson('/api/v1/addresses', $body)->assertStatus(422, $label)->assertJsonPath('success', false);
        }
        $this->assertSame(0, Address::count());

        $this->me()->postJson('/api/v1/addresses', $this->payload(['pincode' => 302001, 'phone' => 9876543210]))->assertCreated();   // JSON numbers tolerated
    }

    public function test_exactly_one_default_is_kept_through_every_operation(): void
    {
        $ids = [];
        foreach (range(1, 4) as $i) {
            $ids[] = $this->me()->postJson('/api/v1/addresses', $this->payload(['name' => "Addr {$i}"]))->assertCreated()->json('data.id');
            $this->assertSame(1, $this->defaults(), "after create {$i}");
        }
        $this->assertTrue(Address::find($ids[0])->is_default);

        $this->me()->patchJson("/api/v1/addresses/{$ids[2]}/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertSame([$ids[2]], Address::where('is_default', true)->pluck('id')->all());

        $this->me()->postJson('/api/v1/addresses', $this->payload(['is_default' => true]))->assertCreated();        // new default on create
        $this->assertSame(1, $this->defaults());

        $this->me()->patchJson("/api/v1/addresses/{$ids[1]}", ['is_default' => true])->assertOk();                   // default through update
        $this->assertSame([$ids[1]], Address::where('is_default', true)->pluck('id')->all());

        $this->me()->patchJson("/api/v1/addresses/{$ids[1]}", ['is_default' => false])->assertOk()->assertJsonPath('data.is_default', true);   // cannot un-default directly
        $this->assertSame(1, $this->defaults());

        $this->me()->deleteJson("/api/v1/addresses/{$ids[0]}")->assertOk();                                          // delete a non-default
        $this->assertSame([$ids[1]], Address::where('is_default', true)->pluck('id')->all());
    }

    public function test_deleting_the_default_promotes_another_and_deleting_the_last_leaves_none(): void
    {
        $a = $this->me()->postJson('/api/v1/addresses', $this->payload())->json('data.id');
        $b = $this->me()->postJson('/api/v1/addresses', $this->payload())->json('data.id');
        $c = $this->me()->postJson('/api/v1/addresses', $this->payload())->json('data.id');

        $this->me()->deleteJson("/api/v1/addresses/{$a}")->assertOk();
        $this->assertSame([$c], Address::where('is_default', true)->pluck('id')->all(), 'the most recently added remaining address is promoted');

        $this->me()->deleteJson("/api/v1/addresses/{$c}")->assertOk();
        $this->assertSame([$b], Address::where('is_default', true)->pluck('id')->all());

        $this->me()->deleteJson("/api/v1/addresses/{$b}")->assertOk();
        $this->assertSame(0, Address::count());
        $this->me()->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_shows_the_default_first_and_only_my_addresses(): void
    {
        $other = User::factory()->create();
        Address::factory()->for($other)->count(3)->create();
        $a = $this->me()->postJson('/api/v1/addresses', $this->payload(['name' => 'One']))->json('data.id');
        $b = $this->me()->postJson('/api/v1/addresses', $this->payload(['name' => 'Two']))->json('data.id');
        $this->me()->patchJson("/api/v1/addresses/{$b}/default")->assertOk();
        $c = $this->me()->postJson('/api/v1/addresses', $this->payload(['name' => 'Three']))->json('data.id');

        $ids = collect($this->me()->getJson('/api/v1/addresses')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$b, $c, $a], $ids);
    }

    public function test_customers_cannot_see_or_change_each_others_addresses(): void
    {
        $other = User::factory()->create();
        $theirs = Address::factory()->for($other)->create(['name' => 'Private', 'is_default' => true]);
        $mine = Address::factory()->for($this->user)->create(['is_default' => true]);

        $this->me()->getJson("/api/v1/addresses/{$theirs->id}")->assertNotFound();
        $this->me()->patchJson("/api/v1/addresses/{$theirs->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->me()->patchJson("/api/v1/addresses/{$theirs->id}/default")->assertNotFound();
        $this->me()->deleteJson("/api/v1/addresses/{$theirs->id}")->assertNotFound();

        $this->assertSame('Private', $theirs->fresh()->name);
        $this->assertTrue($theirs->fresh()->is_default, 'their default is untouched');
        $this->assertTrue($mine->fresh()->is_default);
        $this->assertSame(2, Address::count());
        $this->me()->getJson('/api/v1/addresses/999999')->assertNotFound();
    }

    public function test_update_changes_only_the_given_fields(): void
    {
        $id = $this->me()->postJson('/api/v1/addresses', $this->payload())->json('data.id');

        $this->me()->patchJson("/api/v1/addresses/{$id}", ['city' => 'Udaipur', 'phone' => '9123456780', 'type' => 'WORK'])->assertOk()
            ->assertJsonPath('data.city', 'Udaipur')->assertJsonPath('data.phone', '+919123456780')->assertJsonPath('data.type', 'work')
            ->assertJsonPath('data.name', 'Asha Rao')->assertJsonPath('data.line1', '12 MI Road');
        $this->me()->patchJson("/api/v1/addresses/{$id}", ['pincode' => 'bad'])->assertStatus(422);
        $this->me()->patchJson("/api/v1/addresses/{$id}", ['landmark' => null])->assertOk()->assertJsonPath('data.landmark', null);
    }

    public function test_a_customer_can_hold_at_most_ten_addresses(): void
    {
        foreach (range(1, 10) as $i) {
            $this->me()->postJson('/api/v1/addresses', $this->payload())->assertCreated();
        }

        $this->me()->postJson('/api/v1/addresses', $this->payload())->assertStatus(422)->assertJsonStructure(['errors' => ['address']]);
        $this->assertSame(10, Address::count());
        $this->assertSame(1, $this->defaults());

        $other = User::factory()->create();
        $this->actingAsCustomer($other)->postJson('/api/v1/addresses', $this->payload())->assertCreated();     // the cap is per customer
    }

    public function test_suspended_customer_is_blocked(): void
    {
        $this->user->update(['status' => 'suspended']);

        $this->me()->getJson('/api/v1/addresses')->assertForbidden();
    }
}
