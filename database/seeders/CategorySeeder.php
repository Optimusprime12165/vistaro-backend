<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Database\Seeder;

/**
 * Preserves the storefront's category hierarchy and URLs:
 *   categories     = Topwear / Bottomwear / Outerwear (frontend "groups")
 *   sub_categories = t-shirts, shirts, jeans ... (frontend "categories"; slug == /collections/{slug})
 * Idempotent and non-destructive: existing rows (e.g. edited by an admin) are left untouched.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $d = require __DIR__ . '/data_catalog.php';
        $img = fn (string $slug) => sprintf(
            'https://images.unsplash.com/%s?auto=format&fit=crop&w=900&q=75',
            $d['image_ids'][$d['cat_imgs'][$slug][0] % 10],
        );

        $categories = [];
        foreach ($d['groups'] as $i => [$slug, $label]) {
            $firstSub = collect($d['subcategories'])->firstWhere(2, $slug)[0];
            $categories[$slug] = Category::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $label, 'image_path' => $img($firstSub), 'is_active' => true, 'sort_order' => $i + 1],
            );
        }

        foreach ($d['subcategories'] as $i => [$slug, $label, $group, $blurb]) {
            SubCategory::query()->firstOrCreate(
                ['slug' => $slug],
                ['category_id' => $categories[$group]->id, 'name' => $label, 'description' => $blurb, 'image_path' => $img($slug), 'is_active' => true, 'sort_order' => $i + 1],
            );
        }
    }
}
