<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\SubCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));
        $price = fake()->randomElement([799, 999, 1299, 1499, 1999, 2499]);

        return [
            // category_id is taken from the sub-category in configure() so the pair is always consistent
            'sub_category_id' => SubCategory::factory(),
            'category_id' => null,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . fake()->unique()->numberBetween(1, 999999),
            'sku' => 'VS-' . fake()->unique()->bothify('???-#####'),
            'description' => fake()->sentence(),
            'price' => $price,
            'mrp' => $price + 500,
            'currency' => 'INR',
            'attributes' => ['fit' => 'Regular', 'fabric' => 'Cotton', 'pattern' => 'Solid'],
            'status' => ProductStatus::Active,
            'is_available' => true,
            'is_offline_available' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Product $p) {
            if ($p->category_id === null) {
                $p->category_id = SubCategory::query()->whereKey($p->sub_category_id)->value('category_id');
            }
        });
    }

    public function archived(): static
    {
        return $this->state(['status' => ProductStatus::Archived]);
    }
}
