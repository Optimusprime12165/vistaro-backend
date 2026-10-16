<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role model: exactly ONE active Super Admin, everybody else is Staff.
 *
 *  - super_admin_slot is 1 for the active super admin and NULL for everyone else. A UNIQUE index on it makes
 *    "two active super admins" impossible at the database level (NULLs never collide).
 *  - created_by records which super admin created a staff account (audit information).
 *  - Legacy role 'admin' is converted to 'staff' (the 'admin' tier no longer exists; its catalogue powers are now Super Admin only).
 *  - If several super admins exist, the active one with the lowest id is kept; the others become staff. Accounts are
 *    never deleted or deactivated here, so nobody is locked out by this migration.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->unsignedTinyInteger('super_admin_slot')->nullable()->after('is_active');
            $table->unsignedBigInteger('created_by')->nullable()->after('super_admin_slot');
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->string('role', 24)->default('staff')->change();      // the old default 'admin' no longer exists as a role
        });

        DB::table('admins')->where('role', 'admin')->update(['role' => 'staff']);

        $keep = DB::table('admins')->where('role', 'super_admin')->where('is_active', true)->orderBy('id')->value('id');
        DB::table('admins')->where('role', 'super_admin')->when($keep, fn ($q) => $q->where('id', '!=', $keep))->update(['role' => 'staff']);

        if ($keep) {
            DB::table('admins')->where('id', $keep)->update(['super_admin_slot' => 1]);
        }

        Schema::table('admins', function (Blueprint $table) {
            $table->unique('super_admin_slot', 'admins_single_super_admin_unique');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropUnique('admins_single_super_admin_unique');
            $table->dropIndex(['role']);
            $table->dropColumn(['super_admin_slot', 'created_by']);
        });
    }
};
