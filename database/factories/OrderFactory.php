<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->randomElement([999, 1499, 2499, 3998]);

        return [
            'order_number' => 'VS' . fake()->unique()->numberBetween(10000, 99999999),
            'user_id' => User::factory(),
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
            'payment_method' => PaymentMethod::Cod,
            'subtotal' => $subtotal,
            'mrp_total' => $subtotal + 500,
            'discount_total' => 500,
            'shipping_fee' => 0,
            'total' => $subtotal,
            'currency' => 'INR',
            'contact_name' => fake()->name(),
            'contact_phone' => '+91' . fake()->numerify('9#########'),
            'placed_at' => now(),
        ];
    }
}
