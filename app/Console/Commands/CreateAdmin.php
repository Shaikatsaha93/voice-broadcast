<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email} {--name=Super Admin} {--password=}';

    protected $description = 'Create a Super Admin user (prompts for password when not given).';

    public function handle(): int
    {
        (new DatabaseSeeder)->run();
        $password = $this->option('password') ?: $this->secret('Password (min 12 chars)');
        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $this->argument('email')], ['name' => $this->option('name'), 'password' => $password, 'status' => 'active']);
        $user->roles()->sync([Role::where('name', Role::SUPER_ADMIN)->value('id')]);
        $this->info("Super Admin {$user->email} ready.");

        return self::SUCCESS;
    }
}
