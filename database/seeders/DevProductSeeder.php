<?php

namespace Database\Seeders;

use App\Enums\InventoryLocation;
use App\Enums\InventoryMovementType;
use App\Enums\Size;
use App\Models\InventoryMovement;
use App\Models\OfflineStock;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Models\SubCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DEVELOPMENT ONLY. Re-creates the frontend's 32 generated demo products with the same ids, slugs, prices,
 * colours, attributes and the same online/offline stock figures the mock admin used.
 * Ratings/reviews are intentionally NOT seeded (they were fake). Skips products that already exist (by id).
 */
class DevProductSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DevProductSeeder skipped: demo products are for local/testing only.');
            return;
        }

        $d = require __DIR__ . '/data_catalog.php';
        $img = fn (string $slug, int $k) => sprintf(
            'https://images.unsplash.com/%s?auto=format&fit=crop&w=900&q=75',
            $d['image_ids'][(($d['cat_imgs'][$slug][$k % 4]) % 10 + 10) % 10],
        );
        $subs = SubCategory::query()->get()->keyBy('slug');

        DB::transaction(function () use ($d, $img, $subs) {
            $n = 0;
            foreach ($d['source'] as $cat => [$names, $fabs, $fits]) {
                foreach ($names as $name) {
                    $i = $n++;
                    $id = $i + 1;
                    if (Product::query()->whereKey($id)->exists()) {
                        continue;
                    }

                    $price = $d['prices'][($i * 3 + 2) % count($d['prices'])];
                    $mrp = (int) round($price * (1.25 + ($i % 4) * 0.1) / 100) * 100 + 99;
                    $stock = 5 + ($i * 11) % 40;
                    $sub = $subs[$cat];
                    $skuBase = sprintf('VS-%s-%03d', $d['sku_codes'][$cat], $id);

                    $product = new Product([
                        'category_id' => $sub->category_id,
                        'sub_category_id' => $sub->id,
                        'name' => $name,
                        'slug' => preg_replace('/[^a-z]+/', '-', strtolower($name)) . '-' . $id,
                        'sku' => $skuBase,
                        'description' => "The {$name} from VISTARO: considered proportions, quiet detailing and a finish built to last through everyday movement.",
                        'price' => $price,
                        'mrp' => $mrp,
                        'currency' => 'INR',
                        'attributes' => ['fit' => $fits[$i % count($fits)], 'fabric' => $fabs[$i % count($fabs)], 'pattern' => $d['patterns'][$i % count($d['patterns'])]],
                        'status' => 'active',
                        'is_available' => true,
                        'is_offline_available' => true,
                        'is_bestseller' => $i % 4 === 1,
                        'is_new_arrival' => $i % 3 === 0,
                    ]);
                    $product->id = $id;
                    $product->save();

                    foreach ([0, 1, 2, 3] as $k) {
                        ProductImage::query()->create([
                            'product_id' => $id, 'disk' => 'external', 'path' => $img($cat, $i + $k),
                            'alt' => "{$name} view " . ($k + 1), 'sort_order' => $k, 'is_primary' => $k === 0,
                        ]);
                    }

                    foreach (array_slice($d['colors'], $i % 3, 3) as $c => [$cname, $hex]) {
                        ProductColor::query()->create(['product_id' => $id, 'name' => $cname, 'hex' => $hex, 'sort_order' => $c]);
                    }

                    foreach ($d['sizes'] as $k => $sizeCode) {
                        $size = Size::from($sizeCode);
                        $online = max(0, $stock - $k * 6);
                        $offline = max(0, ($id * 7 + $k * 5) % 13 - ($k > 3 ? 3 : 0));

                        // Creating the size also creates its (zero) offline row via the model observer.
                        $ps = ProductSize::query()->create([
                            'product_id' => $id, 'size' => $size, 'sku' => "{$skuBase}-{$sizeCode}", 'quantity' => $online, 'reserved' => 0,
                        ]);
                        OfflineStock::query()->where(['product_id' => $id, 'size' => $sizeCode])->update(['quantity' => $offline]);

                        foreach ([[InventoryLocation::Online, $online], [InventoryLocation::Offline, $offline]] as [$loc, $qty]) {
                            if ($qty > 0) {
                                InventoryMovement::query()->create([
                                    'product_size_id' => $ps->id, 'product_id' => $id, 'size' => $sizeCode, 'location' => $loc->value,
                                    'type' => InventoryMovementType::Initial->value, 'quantity_change' => $qty, 'quantity_after' => $qty,
                                    'note' => 'Development seed',
                                ]);
                            }
                        }
                    }
                }
            }
        });
    }
}
