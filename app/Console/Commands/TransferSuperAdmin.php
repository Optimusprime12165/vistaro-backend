<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\Admin\StaffService;
use Illuminate\Console\Command;

/** php artisan vistaro:transfer-super-admin someone@example.com : the only way to hand the single Super Admin role to another account. */
class TransferSuperAdmin extends Command
{
    protected $signature = 'vistaro:transfer-super-admin {email : e-mail of the existing admin account that becomes Super Admin}';

    protected $description = 'Make another admin account the (one and only) Super Admin; the previous Super Admin becomes Staff.';

    public function handle(StaffService $staff): int
    {
        $admin = Admin::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();

        if ($admin === null) {
            $this->error('No admin account with that e-mail.');

            return self::FAILURE;
        }

        $staff->transferSuperAdmin($admin);
        $this->info("{$admin->email} is now the Super Admin. All sessions of both accounts were signed out.");

        return self::SUCCESS;
    }
}
