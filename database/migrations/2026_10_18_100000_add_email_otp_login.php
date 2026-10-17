<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email OTP sign-in.
 *  - otp tables: the identifier column ("mobile") now also holds a lower-cased email, so it is widened.
 *  - users.mobile becomes nullable (an email-only customer has no mobile); users.email_verified_at added.
 *  - login_events: mobile nullable + email column.
 * Requires Laravel 11+ (native ->change(), no doctrine/dbal).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('otp_verifications', fn (Blueprint $t) => $t->string('mobile', 191)->change());
        Schema::table('otp_throttles', fn (Blueprint $t) => $t->string('mobile', 191)->change());

        Schema::table('users', function (Blueprint $t) {
            $t->string('mobile', 16)->nullable()->change();
            $t->timestamp('email_verified_at')->nullable()->after('email');
        });

        Schema::table('login_events', function (Blueprint $t) {
            $t->string('mobile', 16)->nullable()->change();
            $t->string('email', 191)->nullable()->after('mobile');
            $t->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('login_events', function (Blueprint $t) {
            $t->dropIndex(['email']);
            $t->dropColumn('email');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('email_verified_at'));
    }
};
