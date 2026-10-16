<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSize;
use App\Support\SizeCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductSize> */
class ProductSizeFactory extends Factory
{
    protected $model = ProductSize::class;

    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'size' => 'M', 'quantity' => 20, 'reserved' => 0, 'is_enabled' => true];
    }

    /** @param \App\Enums\Size|string $size any size code (sizes are dynamic) */
    public function size(\App\Enums\Size|string $size): static
    {
        return $this->state(['size' => SizeCode::normalize($size instanceof \App\Enums\Size ? $size->value : $size)]);
    }

    public function quantity(int $q): static
    {
        return $this->state(['quantity' => $q]);
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }
}
