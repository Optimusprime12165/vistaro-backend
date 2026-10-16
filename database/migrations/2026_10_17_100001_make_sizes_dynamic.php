<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sizes are no longer restricted to the fixed XXXS..4XL list: the size code becomes a short free-form string
 * (validated in the application: letters, digits, space, . + -). Existing values are unchanged.
 * The widened columns keep the stored snapshots on order items and in the stock ledger valid for the new codes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_sizes', function (Blueprint $table) {
            $table->string('size', 16)->change();
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('size', 16)->change();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('size', 16)->change();
        });
    }

    /** Intentionally a no-op: narrowing the columns back could truncate order history and the ledger. */
    public function down(): void
    {
    }
};
