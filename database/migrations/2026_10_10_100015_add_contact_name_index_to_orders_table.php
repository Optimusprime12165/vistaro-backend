<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Admin order search by customer name (prefix match) would otherwise scan the whole orders table.
        Schema::table('orders', function (Blueprint $table) {
            $table->index('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['contact_name']);
        });
    }
};
