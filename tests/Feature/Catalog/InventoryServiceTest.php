<?php

namespace Tests\Feature\Catalog;

use App\Enums\InventoryMovementType;
use App\Enums\Size;
use App\Exceptions\InsufficientStockException;
use App\Models\Admin;
use App\Models\InventoryMovement;
use App\Models\OfflineStock;
use App\Models\Product;
use App\Models\ProductSize;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv = app(InventoryService::class);
    }

    private function stocked(int $qty = 5, Size $size = Size::M): ProductSize
    {
        return ProductSize::factory()->for(Product::factory())->size($size)->quantity($qty)->create();
    }

    public function test_set_records_one_movement_with_the_delta_and_actor(): void
    {
        $ps = $this->stocked(5);
        $admin = Admin::factory()->create();

        $this->inv->setOnline($ps->id, 12, $admin, 'recount');

        $this->assertSame(12, $ps->fresh()->quantity);
        $m = InventoryMovement::sole();
        $this->assertSame(7, $m->quantity_change);
        $this->assertSame(12, $m->quantity_after);
        $this->assertSame('online', $m->location->value);
        $this->assertSame('admin', $m->actor_type);
        $this->assertSame($admin->id, $m->actor_id);
        $this->assertSame('recount', $m->note);

        $this->inv->setOnline($ps->id, 12, $admin);                      // no change => no movement
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_stock_can_never_go_negative(): void
    {
        $ps = $this->stocked(3);

        try {
            $this->inv->adjustOnline($ps->id, -4);
            $this->fail('must refuse');
        } catch (InsufficientStockException $e) {
            $this->assertSame(409, $e->status());
        }

        $this->assertSame(3, $ps->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::count(), 'a refused change leaves no movement');

        $this->inv->adjustOnline($ps->id, -3);                           // exactly to zero is fine
        $this->assertSame(0, $ps->fresh()->quantity);
        $this->expectException(\InvalidArgumentException::class);
        $this->inv->setOnline($ps->id, -1);
    }

    public function test_reserve_moves_available_to_reserved_and_release_moves_it_back(): void
    {
        $ps = $this->stocked(5);

        $this->inv->reserve($ps->id, 2, 'order', 77);
        $this->assertSame([3, 2], [$ps->fresh()->quantity, $ps->fresh()->reserved]);

        $m = InventoryMovement::latest('id')->first();
        $this->assertSame([-2, 2, 3, 'order_reserved', 'order', 77], [$m->quantity_change, $m->reserved_change, $m->quantity_after, $m->type->value, $m->reference_type, $m->reference_id]);

        $this->inv->release($ps->id, 2, 'order', 77);
        $this->assertSame([5, 0], [$ps->fresh()->quantity, $ps->fresh()->reserved]);
        $this->assertSame(InventoryMovementType::OrderReleased, InventoryMovement::latest('id')->first()->type);

        $this->expectException(InsufficientStockException::class);     // nothing reserved any more
        $this->inv->release($ps->id, 1, 'order', 77);
    }

    public function test_the_last_unit_can_only_be_reserved_once(): void
    {
        $ps = $this->stocked(1);

        $this->inv->reserve($ps->id, 1, 'order', 1);
        try {
            $this->inv->reserve($ps->id, 1, 'order', 2);
            $this->fail('second buyer must be refused');
        } catch (InsufficientStockException) {
        }

        $this->assertSame([0, 1], [$ps->fresh()->quantity, $ps->fresh()->reserved]);
        $this->assertSame(1, InventoryMovement::where('type', 'order_reserved')->count());
    }

    public function test_offline_stock_is_separate_from_online_stock(): void
    {
        $ps = $this->stocked(10);
        $this->assertSame(0, OfflineStock::where('product_id', $ps->product_id)->sole()->quantity);

        $this->inv->setOffline($ps->product_id, Size::M, 4);
        $this->inv->adjustOffline($ps->product_id, Size::M, -1);

        $this->assertSame(3, OfflineStock::where('product_id', $ps->product_id)->sole()->quantity);
        $this->assertSame(10, $ps->fresh()->quantity, 'online stock untouched');
        $this->assertSame(['offline', 'offline'], InventoryMovement::pluck('location')->map->value->all());
        $this->assertSame($ps->id, InventoryMovement::first()->product_size_id);

        $this->expectException(InsufficientStockException::class);
        $this->inv->adjustOffline($ps->product_id, Size::M, -4);
    }

    public function test_offline_stock_requires_an_existing_product_size(): void
    {
        $p = Product::factory()->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->inv->setOffline($p->id, Size::XL, 3);
    }
}
