<?php

namespace App\Jobs;

use App\Services\Asterisk\AsteriskEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAsteriskEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public array $event)
    {
    }

    public function handle(AsteriskEventProcessor $processor): void
    {
        $processor->process($this->event);
    }
}
