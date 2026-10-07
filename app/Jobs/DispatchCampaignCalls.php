<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Calls\CampaignDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchCampaignCalls implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 10;

    public function __construct(public int $campaignId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    public function handle(CampaignDispatcher $dispatcher): void
    {
        if ($campaign = Campaign::find($this->campaignId)) {
            $dispatcher->dispatch($campaign);
        }
    }
}
