<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Audit;
use Illuminate\Console\Command;

class ExpireUsers extends Command
{
    protected $signature = 'users:expire';

    protected $description = 'Set Admin / User accounts whose validity date is over to inactive';

    public function handle(): int
    {
        // valid_until is the last valid day: the account stops working from the next day.
        $users = User::where('status', 'active')->whereNotNull('valid_until')->whereDate('valid_until', '<', today())
            ->whereHas('roles', fn ($r) => $r->whereIn('name', [Role::ADMIN, Role::USER]))->get();

        foreach ($users as $u) {
            $u->update(['status' => 'inactive']);
            Audit::log('user.expired', $u, null, ['status' => 'active'], ['status' => 'inactive', 'valid_until' => $u->valid_until->toDateString()]);
        }
        $this->info("{$users->count()} account(s) expired.");

        return self::SUCCESS;
    }
}
