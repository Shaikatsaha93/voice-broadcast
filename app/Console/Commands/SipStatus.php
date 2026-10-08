<?php

namespace App\Console\Commands;

use App\Services\Asterisk\TrunkProvisioner;
use Illuminate\Console\Command;

class SipStatus extends Command
{
    protected $signature = 'dids:sip-status';

    protected $description = 'Refresh the SIP registration status of every DID from Asterisk.';

    public function handle(TrunkProvisioner $trunks): int
    {
        return $trunks->refreshStatuses() ? self::SUCCESS : self::FAILURE;
    }
}
