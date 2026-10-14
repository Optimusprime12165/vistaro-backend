<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 20)->unique();         // "VS" + number, assigned by the order service
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // customers are never hard-deleted while orders exist
            $table->string('status', 24)->default('pending');          // fulfilment lifecycle
            $table->string('payment_status', 24)->default('pending');  // separate from fulfilment
            $table->string('payment_method', 8);                       // cod | upi | card
            $table->decimal('subtotal', 12, 2);                        // sum(unit_price * qty)
            $table->decimal('mrp_total', 12, 2);                       // sum(mrp * qty)
            $table->decimal('discount_total', 12, 2)->default(0);      // mrp_total - subtotal
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2);                           // subtotal + shipping_fee
            $table->char('currency', 3)->default('INR');
            $table->string('contact_name', 120);
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 16);
            $table->timestamp('placed_at');
            $table->timestamps();

            $table->index(['user_id', 'id']);                          // "my orders", newest first
            $table->index(['status', 'id']);                           // admin filter by status
            $table->index(['payment_status', 'id']);
            $table->index('placed_at');                                // date range filters / dashboard
            $table->index('contact_phone');                            // admin search
        });

        // Price/name/size/colour snapshot at purchase time. Product/size links become NULL if the product is later deleted.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_size_id')->nullable()->constrained('product_sizes')->nullOnDelete();
            $table->string('product_name', 160);
            $table->string('sku', 80)->nullable();
            $table->string('size', 4);
            $table->string('color_name', 40)->nullable();
            $table->char('color_hex', 7)->nullable();
            $table->string('image', 500)->nullable();                  // primary image URL/path at purchase time
            $table->decimal('unit_price', 10, 2);
            $table->decimal('mrp', 10, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index('product_id');                               // sales per product
        });

        // Immutable shipping-address snapshot (no updated_at; the model rejects updates).
        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unique('order_id');                                // exactly one address snapshot per order
            $table->string('name', 120);
            $table->string('phone', 16);
            $table->string('line1', 255);
            $table->string('landmark', 255)->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->char('pincode', 6);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_type', 60)->nullable();              // admin | user | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
