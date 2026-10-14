<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('sub_category_id');
            $table->string('name', 160);
            $table->string('slug', 190)->unique();
            $table->string('sku', 64)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);                     // selling price
            $table->decimal('mrp', 10, 2);                       // original price, >= price (validated in service)
            $table->unsignedTinyInteger('discount_percent')->default(0); // derived from price/mrp by the model; indexed for "sale"
            $table->char('currency', 3)->default('INR');
            $table->json('attributes')->nullable();              // {"fabric":"Cotton","fit":"Regular","pattern":"Solid"}
            $table->string('status', 16)->default('active');     // draft | active | archived (archive instead of deleting sold products)
            $table->boolean('is_available')->default(true);      // admin "Available for sale" toggle (online)
            $table->boolean('is_offline_available')->default(true); // admin "Available in shop" toggle (offline)
            $table->boolean('is_bestseller')->default(false);
            $table->boolean('is_new_arrival')->default(false);
            $table->timestamps();

            // category_id is stored for fast category filtering, but the composite FK makes an inconsistent pair impossible.
            $table->foreign(['sub_category_id', 'category_id'], 'products_subcategory_category_fk')
                ->references(['id', 'category_id'])->on('sub_categories')
                ->restrictOnDelete()->cascadeOnUpdate();

            // Indexes follow the storefront/admin query patterns; to be re-checked with EXPLAIN in Phase 9.
            $table->index(['status', 'is_available', 'sub_category_id'], 'products_catalog_idx');
            $table->index(['status', 'is_available', 'price'], 'products_price_idx');
            $table->index(['status', 'is_available', 'is_new_arrival'], 'products_new_idx');
            $table->index(['status', 'is_available', 'is_bestseller'], 'products_best_idx');
            $table->index('discount_percent');
            $table->index('category_id');
            $table->index('name');                               // admin search (prefix)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
