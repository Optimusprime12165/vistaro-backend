<?php

namespace Database\Factories;

use App\Enums\AddressType;
use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Address> */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => AddressType::Home,
            'name' => fake()->name(),
            'phone' => '+91' . fake()->numerify('9#########'),
            'line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Rajasthan',
            'pincode' => fake()->numerify('######'),
            'is_default' => false,
        ];
    }
}
