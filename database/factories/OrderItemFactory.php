<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_name' => fake()->words(3, true),
            'sku' => 'VS-TST-001-M',
            'size' => 'M',
            'unit_price' => 999,
            'mrp' => 1499,
            'quantity' => 1,
            'line_total' => 999,
        ];
    }
}
