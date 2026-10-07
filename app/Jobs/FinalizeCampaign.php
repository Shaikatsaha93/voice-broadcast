<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Calls\CampaignDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinalizeCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $campaignId)
    {
    }

    public function handle(CampaignDispatcher $dispatcher): void
    {
        if ($campaign = Campaign::find($this->campaignId)) {
            $dispatcher->finalizeIfDone($campaign);
        }
    }
}
