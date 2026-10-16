<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SHARED STOCK (one quantity per product size, used by Online orders AND manual Offline adjustments).
 *
 *  - product_sizes.quantity becomes the single authoritative stock figure for a size.
 *  - product_sizes.is_enabled is new: a size can be switched off for sale without touching its quantity.
 *  - offline_stocks (the old, separate shop counter) is folded into product_sizes.quantity and then dropped.
 *    Nothing is lost silently: every non-zero legacy shop quantity is added to the shared quantity AND written to the
 *    append-only inventory_movements ledger (location=offline, type=adjustment, note "Merged legacy shop stock ...").
 *
 * No total stock is stored anywhere; totals are always derived from the per-size rows.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('product_sizes', 'is_enabled')) {
            Schema::table('product_sizes', function (Blueprint $table) {
                $table->boolean('is_enabled')->default(true)->after('reserved');
            });
        }

        if (Schema::hasTable('offline_stocks')) {
            $rows = DB::table('offline_stocks')->where('quantity', '>', 0)->orderBy('id')->get();

            foreach ($rows as $row) {
                $size = DB::table('product_sizes')->where(['product_id' => $row->product_id, 'size' => $row->size])->first();
                if ($size === null) {
                    continue;                                   // cannot happen (composite FK), defensive
                }

                $after = (int) $size->quantity + (int) $row->quantity;
                DB::table('product_sizes')->where('id', $size->id)->update(['quantity' => $after, 'updated_at' => now()]);
                DB::table('inventory_movements')->insert([
                    'product_size_id' => $size->id, 'product_id' => $size->product_id, 'size' => $size->size,
                    'location' => 'offline', 'type' => 'adjustment',
                    'quantity_change' => (int) $row->quantity, 'reserved_change' => 0, 'quantity_after' => $after,
                    'reference_type' => null, 'reference_id' => null, 'actor_type' => null, 'actor_id' => null,
                    'note' => 'Merged legacy shop stock into shared stock', 'created_at' => now(),
                ]);
            }

            Schema::drop('offline_stocks');
        }
    }

    /** Re-creates an EMPTY offline_stocks table (the merged quantities stay in product_sizes; see the movement ledger). */
    public function down(): void
    {
        if (! Schema::hasTable('offline_stocks')) {
            Schema::create('offline_stocks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->string('size', 16);
                $table->unsignedInteger('quantity')->default(0);
                $table->timestamps();
                $table->unique(['product_id', 'size']);
                $table->index('quantity');
            });
        }

        if (Schema::hasColumn('product_sizes', 'is_enabled')) {
            Schema::table('product_sizes', function (Blueprint $table) {
                $table->dropColumn('is_enabled');
            });
        }
    }
};
