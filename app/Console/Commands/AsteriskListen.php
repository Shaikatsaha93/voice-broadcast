<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAsteriskEvent;
use App\Services\Asterisk\AsteriskAMIService;
use Illuminate\Console\Command;

class AsteriskListen extends Command
{
    protected $signature = 'asterisk:listen';

    protected $description = 'Listen to AMI events and queue them for processing (run under Supervisor).';

    private const WANTED = ['Newchannel', 'Newstate', 'DialBegin', 'DialEnd', 'Hangup', 'UserEvent', 'OriginateResponse'];

    public function handle(AsteriskAMIService $ami): int
    {
        while (true) {
            if (! $ami->connect(10)) {
                $this->warn('AMI unavailable, retrying in 5s');
                sleep(5);

                continue;
            }
            $this->info('AMI connected');

            while ($ami->connected()) {
                $msg = $ami->readMessage();
                if ($msg && in_array($msg['Event'] ?? '', self::WANTED, true)) {
                    ProcessAsteriskEvent::dispatch($msg);
                }
            }

            $ami->disconnect(); // AMI disconnect: reconnect loop; Reconciler covers missed events
            sleep(2);
        }
    }
}
