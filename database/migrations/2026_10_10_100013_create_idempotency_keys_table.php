<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 60);                            // e.g. 'orders.store'
            $table->string('idempotency_key', 100);                 // client-supplied header value
            $table->char('request_hash', 64);                       // same key + different payload => 422/409
            $table->string('state', 12)->default('processing');     // processing | completed
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'idempotency_key'], 'idempotency_unique');
            $table->index('expires_at');                            // pruning
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
