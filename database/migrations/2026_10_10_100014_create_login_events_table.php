<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Append-only; backs the admin "Customer logins" page (cursor-paginated, newest first) and the dashboard counters.
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mobile', 16);                           // snapshot
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('method', 12)->default('otp');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
            $table->index('created_at');                            // "logins today"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
