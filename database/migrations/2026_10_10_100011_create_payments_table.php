<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Never stores card numbers, CVVs or UPI credentials: only the gateway's own reference.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('method', 8);                           // cod | upi | card
            $table->string('gateway', 20)->nullable();             // null for COD; 'mock' in development
            $table->string('status', 24)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('reference', 100)->nullable();
            $table->boolean('is_mock')->default(false);            // a mock payment is never real money
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();                      // non-sensitive provider metadata only
            $table->timestamps();

            $table->index(['order_id', 'id']);
            $table->unique(['gateway', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
