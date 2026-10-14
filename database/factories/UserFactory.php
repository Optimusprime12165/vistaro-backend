<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => '+91' . fake()->unique()->numerify(fake()->randomElement(['6', '7', '8', '9']) . '#########'),
            'mobile_verified_at' => now(),
            'status' => AccountStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => AccountStatus::Suspended]);
    }
}
