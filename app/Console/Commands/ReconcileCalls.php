<?php

namespace App\Console\Commands;

use App\Services\Calls\Reconciler;
use Illuminate\Console\Command;

class ReconcileCalls extends Command
{
    protected $signature = 'calls:reconcile';

    protected $description = 'Recover stale DID slots and stuck call attempts.';

    public function handle(Reconciler $reconciler): int
    {
        $r = $reconciler->run();
        $this->info("Recovered {$r['recovered']} stale, {$r['orphans']} orphan slots.");

        return self::SUCCESS;
    }
}
