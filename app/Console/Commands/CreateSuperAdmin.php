<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * php artisan vistaro:create-super-admin "Owner Name" owner@example.com
 *
 * Bootstraps the ONE Super Admin on a fresh database (the dev AdminSeeder runs only in local/testing, so production
 * needs this). Refuses if an active Super Admin already exists (use vistaro:transfer-super-admin to change hands).
 * Password is prompted (hidden) or read from the VISTARO_SUPER_ADMIN_PASSWORD environment variable; never passed as an argument.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'vistaro:create-super-admin {name : display name} {email : login e-mail}';

    protected $description = 'Create the one and only Super Admin on a fresh database.';

    public function handle(): int
    {
        if (Admin::query()->where('super_admin_slot', 1)->exists()) {
            $this->error('An active Super Admin already exists. Use vistaro:transfer-super-admin to hand the role over.');

            return self::FAILURE;
        }

        $password = (string) (getenv('VISTARO_SUPER_ADMIN_PASSWORD') ?: $this->secret('Password (min 12 characters)'));

        $validator = Validator::make(
            ['name' => $this->argument('name'), 'email' => mb_strtolower((string) $this->argument('email')), 'password' => $password],
            ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', 'unique:admins,email'], 'password' => ['required', 'string', 'min:12', 'max:128']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();
        Admin::query()->create($data + ['role' => AdminRole::SuperAdmin, 'is_active' => true]);   // password hashed by the model cast

        $this->info("Super Admin {$data['email']} created.");

        return self::SUCCESS;
    }
}
