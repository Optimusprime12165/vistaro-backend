<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Customers. Mobile + OTP only: there is deliberately no password column.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->nullable();            // unknown until the profile is completed
            $table->string('email')->nullable()->unique();      // optional profile / checkout info
            $table->string('mobile', 16)->unique();             // normalised E.164, e.g. +919876543210
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('status', 16)->default('active');    // active | suspended
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('name');                              // admin customer search (prefix)
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
