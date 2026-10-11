<?php

namespace App\Console\Commands;

use App\Models\Did;
use App\Services\Asterisk\TrunkProvisioner;
use App\Services\Audit\Audit;
use Illuminate\Console\Command;

class ExpireDids extends Command
{
    protected $signature = 'dids:expire';

    protected $description = 'Set DIDs whose validity date is over to inactive';

    public function handle(TrunkProvisioner $trunks): int
    {
        // valid_until is the last valid day: the DID stops working from the next day.
        $dids = Did::where('status', 'active')->whereNotNull('valid_until')->whereDate('valid_until', '<', today())->get();

        foreach ($dids as $d) {
            $d->update(['status' => 'inactive']);
            Audit::log('did.expired', $d, null, ['status' => 'active'], ['status' => 'inactive', 'valid_until' => $d->valid_until->toDateString()]);
        }
        if ($dids->contains(fn ($d) => $d->hasSip())) {
            $trunks->apply(); // inactive DIDs are removed from the Asterisk trunk config
            $trunks->refreshStatuses();
        }
        $this->info("{$dids->count()} DID(s) expired.");

        return self::SUCCESS;
    }
}
