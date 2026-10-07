<?php

namespace App\Services\Calls;

use App\Enums\CallStatus;
use App\Enums\CampaignStatus;
use App\Jobs\DispatchCampaignCalls;
use App\Jobs\OriginateBroadcastCall;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Did\DidSlotManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CampaignDispatcher
{
    public function __construct(private DidSlotManager $slots)
    {
    }

    /**
     * Claim recipients one by one while DID + campaign capacity is available.
     * Returns number of calls queued.
     */
    public function dispatch(Campaign $campaign, int $max = 50): int
    {
        $queued = 0;

        while ($queued < $max) {
            $campaign->refresh();
            if (! $campaign->status->canDial() || ! $campaign->did->isActive()) {
                break;
            }

            $attempt = $this->claimNext($campaign);
            if (! $attempt) {
                break;
            }

            if (! $this->slots->acquire($attempt)) {
                $this->undoClaim($attempt);
                break; // capacity full; slots freed by finalize re-trigger dispatch
            }

            OriginateBroadcastCall::dispatch($attempt->id);
            $queued++;
        }

        return $queued;
    }

    private function claimNext(Campaign $campaign): ?CallAttempt
    {
        return DB::transaction(function () use ($campaign) {
            $r = CampaignRecipient::where('campaign_id', $campaign->id)
                ->whereIn('status', ['PENDING', 'RETRY_PENDING'])
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')->lockForUpdate()->first();

            if (! $r) {
                return null;
            }

            $r->update(['status' => 'IN_PROGRESS', 'attempts_count' => $r->attempts_count + 1]);

            return CallAttempt::create([
                'call_ref' => (string) Str::uuid(),
                'campaign_id' => $campaign->id,
                'recipient_id' => $r->id,
                'did_id' => $campaign->did_id,
                'user_id' => $campaign->user_id,
                'phone' => $r->phone,
                'attempt_no' => $r->attempts_count,
                'status' => CallStatus::QUEUED,
                'status_rank' => CallStatus::QUEUED->rank(),
            ]);
        });
    }

    private function undoClaim(CallAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt) {
            $r = CampaignRecipient::whereKey($attempt->recipient_id)->lockForUpdate()->first();
            $attempt->delete();
            $r->update(['status' => $r->attempts_count > 1 ? 'RETRY_PENDING' : 'PENDING', 'attempts_count' => $r->attempts_count - 1]);
        });
    }

    /** Complete the campaign when nothing is pending, retrying or in flight. */
    public function finalizeIfDone(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign) {
            $c = Campaign::whereKey($campaign->id)->lockForUpdate()->first();
            if ($c->status !== CampaignStatus::RUNNING) {
                return;
            }
            $open = CampaignRecipient::where('campaign_id', $c->id)->whereIn('status', ['PENDING', 'RETRY_PENDING', 'IN_PROGRESS'])->exists();
            if (! $open) {
                app(CampaignService::class)->transition($c, CampaignStatus::COMPLETED, ['completed_at' => now()], 'campaign.completed');
            }
        });
    }
}
