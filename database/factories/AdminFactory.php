<?php

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Admin> */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',   // hashed by the model cast
            'role' => 'staff',
            'is_active' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(['role' => 'super_admin']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
