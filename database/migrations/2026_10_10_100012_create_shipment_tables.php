<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12)->default('forward');        // forward | return
            $table->string('provider', 30);                        // 'mock' or a real courier key
            $table->boolean('is_mock')->default(false);            // simulated shipment, NOT a courier booking
            $table->string('awb', 64)->nullable()->unique();
            $table->string('carrier', 80)->nullable();
            $table->string('status', 24)->default('created');
            $table->string('pickup_reference', 80)->nullable();
            $table->timestamp('pickup_requested_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'type']);
            $table->index('status');
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100)->nullable();        // provider event id => webhook de-duplication
            $table->string('status', 32);
            $table->string('location', 160)->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['shipment_id', 'external_id']);
            $table->index(['shipment_id', 'occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
    }
};
