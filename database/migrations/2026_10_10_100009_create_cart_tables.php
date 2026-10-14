<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique('user_id');                              // one cart per customer (login is required to shop)
            $table->timestamps();
        });

        // No price columns on purpose: prices are always read from products at calculation time.
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_size_id')->constrained('product_sizes')->cascadeOnDelete();
            $table->foreignId('product_color_id')->nullable()->constrained('product_colors')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);  // max 10 per line enforced in the service
            $table->timestamps();

            // NB: NULL colour is not de-duplicated by the DB (SQL NULL semantics); the cart service merges those lines.
            $table->unique(['cart_id', 'product_size_id', 'product_color_id'], 'cart_items_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
