<?php

namespace Database\Factories;

use App\Enums\Size;
use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductSize> */
class ProductSizeFactory extends Factory
{
    protected $model = ProductSize::class;

    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'size' => Size::M, 'quantity' => 20, 'reserved' => 0];
    }

    public function size(Size $size): static
    {
        return $this->state(['size' => $size]);
    }

    public function quantity(int $q): static
    {
        return $this->state(['quantity' => $q]);
    }
}
