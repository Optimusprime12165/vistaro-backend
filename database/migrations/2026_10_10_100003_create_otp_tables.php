<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One row per issued OTP (hashed). Verification state lives here.
        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 16);
            $table->string('purpose', 24)->default('login');
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();    // set when a newer OTP supersedes this one
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['mobile', 'purpose', 'id']);         // "latest OTP for this mobile"
            $table->index('expires_at');                        // pruning
        });

        // One row per (mobile, purpose): the issuance counter and cooldown live HERE, not on otp_verifications,
        // so creating new OTP rows can never reset the daily limit. Updated atomically under a row lock.
        Schema::create('otp_throttles', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 16);
            $table->string('purpose', 24)->default('login');
            $table->timestamp('window_started_at');
            $table->unsignedTinyInteger('issuance_count')->default(0);
            $table->timestamp('next_allowed_at')->nullable();
            $table->timestamps();

            $table->unique(['mobile', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_throttles');
        Schema::dropIfExists('otp_verifications');
    }
};
