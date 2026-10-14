<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->default('home');         // home | work | other
            $table->string('name', 120);
            $table->string('phone', 16);
            $table->string('line1', 255);
            $table->string('landmark', 255)->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->char('pincode', 6);
            $table->boolean('is_default')->default(false);       // one default per user, maintained in a transaction in the service
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
