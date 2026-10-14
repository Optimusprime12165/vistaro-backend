<?php

use App\Enums\Size;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 20);                          // 'public' | 's3' | 'external' (path holds an absolute URL, used by dev seed data)
            $table->string('path', 500);
            $table->string('alt', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);       // exactly one per product, maintained in the service
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        // Colour is a product option only (no per-colour stock yet).
        // Future per-colour inventory: add nullable product_colour_id to product_sizes and widen its unique key (new migration).
        Schema::create('product_colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->char('hex', 7);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
        });

        // One row per size the product is sold in. Single source of truth for ONLINE stock.
        // quantity = units available to sell; reserved = units held by not-yet-fulfilled orders (moved out of quantity).
        // UNSIGNED: on MySQL/MariaDB a decrement below zero errors out instead of going negative (atomic oversell guard).
        Schema::create('product_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->enum('size', Size::values());                // DB-level restriction to XS..4XL
            $table->string('sku', 80)->nullable()->unique();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'size']);              // no duplicate product-size inventory rows
            $table->index('quantity');                           // low-stock / out-of-stock reports
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sizes');
        Schema::dropIfExists('product_colors');
        Schema::dropIfExists('product_images');
    }
};
