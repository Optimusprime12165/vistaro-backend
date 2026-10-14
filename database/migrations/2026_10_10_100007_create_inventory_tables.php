<?php

use App\Enums\Size;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Physical-shop stock. Fully separate from online stock and from orders.
        // The composite FK ties every offline row to a real product_sizes row, so both inventories always
        // describe the same set of product-size combinations. A product_sizes row cannot exist without
        // (at least a zero) offline row: the ProductSize model creates it automatically.
        Schema::create('offline_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->enum('size', Size::values());
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'size']);
            $table->foreign(['product_id', 'size'], 'offline_stocks_product_size_fk')
                ->references(['product_id', 'size'])->on('product_sizes')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->index('quantity');
        });

        // Append-only ledger of every stock change (online and offline).
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_size_id')->nullable()->constrained('product_sizes')->nullOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();   // snapshot, survives deletion of the product
            $table->string('size', 4);                               // snapshot
            $table->string('location', 8);                           // online | offline
            $table->string('type', 24);
            $table->integer('quantity_change');                      // signed change to available quantity
            $table->integer('reserved_change')->default(0);          // signed change to reserved (online only)
            $table->unsignedInteger('quantity_after');
            $table->string('reference_type', 60)->nullable();       // e.g. 'order'
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('actor_type', 60)->nullable();           // 'admin' | 'user' | null (system)
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_size_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('offline_stocks');
    }
};
