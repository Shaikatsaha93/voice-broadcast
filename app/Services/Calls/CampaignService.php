<?php

namespace App\Services\Calls;

use App\Enums\CampaignStatus;
use App\Jobs\DispatchCampaignCalls;
use App\Jobs\OriginateBroadcastCall;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignApproval;
use App\Models\User;
use App\Services\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit campaign state machine. Every change goes through transition(). */
class CampaignService
{
    public function transition(Campaign $campaign, CampaignStatus $to, array $attrs = [], ?string $auditAction = null): Campaign
    {
        return DB::transaction(function () use ($campaign, $to, $attrs, $auditAction) {
            $c = Campaign::whereKey($campaign->id)->lockForUpdate()->first();

            if (! $c->status->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => "Cannot change campaign from {$c->status->value} to {$to->value}."]);
            }

            $old = ['status' => $c->status->value];
            $c->update(['status' => $to] + $attrs);
            Audit::log($auditAction ?? 'campaign.'.strtolower($to->value), $c, null, $old, ['status' => $to->value] + array_diff_key($attrs, ['rejection_reason' => 1]));
            $campaign->setRawAttributes($c->getAttributes(), true);

            return $campaign;
        });
    }

    public function submit(Campaign $c): Campaign
    {
        $errors = [];
        if (! $c->audio?->isReady()) {
            $errors['audio'] = 'A READY audio file is required.';
        }
        if (! $c->recipients()->exists()) {
            $errors['numbers'] = 'Import at least one valid number first.';
        }
        if ($c->imports()->whereIn('status', ['PENDING', 'PROCESSING'])->exists()) {
            $errors['numbers'] = 'Number import is still processing.';
        }
        if (! $c->did->isActive() || ! $c->user->dids()->whereKey($c->did_id)->exists()) {
            $errors['did'] = 'The DID is not active or not assigned to you.';
        }
        if ($c->requested_concurrency > $c->did->max_concurrent_calls) {
            $errors['requested_concurrency'] = 'Requested concurrency exceeds the DID maximum ('.$c->did->max_concurrent_calls.').';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $this->transition($c, CampaignStatus::PENDING_APPROVAL, ['submitted_at' => now(), 'rejection_reason' => null], 'campaign.submitted');
    }

    public function approve(Campaign $c, User $admin): Campaign
    {
        $c = $this->transition($c, CampaignStatus::APPROVED, ['approved_at' => now()], 'campaign.approved');
        CampaignApproval::create(['campaign_id' => $c->id, 'admin_id' => $admin->id, 'decision' => 'APPROVED']);

        return $c;
    }

    public function reject(Campaign $c, User $admin, string $reason): Campaign
    {
        $c = $this->transition($c, CampaignStatus::REJECTED, ['rejection_reason' => $reason], 'campaign.rejected');
        CampaignApproval::create(['campaign_id' => $c->id, 'admin_id' => $admin->id, 'decision' => 'REJECTED', 'reason' => $reason]);
        Audit::log('campaign.rejection_reason', $c, null, null, ['reason' => $reason]);

        return $c;
    }

    /** Start an APPROVED campaign. Future scheduled_at => QUEUED until the scheduler starts it. */
    public function start(Campaign $c): Campaign
    {
        if ($c->scheduled_at && $c->scheduled_at->isFuture()) {
            return $this->transition($c, CampaignStatus::QUEUED, [], 'campaign.queued');
        }

        $c = $this->transition($c, CampaignStatus::RUNNING, ['started_at' => $c->started_at ?? now()], 'campaign.started');
        DispatchCampaignCalls::dispatch($c->id);

        return $c;
    }

    public function startQueued(Campaign $c): Campaign
    {
        $c = $this->transition($c, CampaignStatus::RUNNING, ['started_at' => now()], 'campaign.started');
        DispatchCampaignCalls::dispatch($c->id);

        return $c;
    }

    public function pause(Campaign $c): Campaign
    {
        $c = $this->transition($c, CampaignStatus::PAUSED, [], 'campaign.paused');
        $this->abortQueuedAttempts($c, false);

        return $c;
    }

    public function resume(Campaign $c): Campaign
    {
        $c = $this->transition($c, CampaignStatus::RUNNING, [], 'campaign.resumed');
        DispatchCampaignCalls::dispatch($c->id);

        return $c;
    }

    public function cancel(Campaign $c): Campaign
    {
        $c = $this->transition($c, CampaignStatus::CANCELLED, ['completed_at' => now()], 'campaign.cancelled');
        $this->abortQueuedAttempts($c, true);
        $c->recipients()->whereIn('status', ['PENDING', 'RETRY_PENDING'])->update(['status' => 'CANCELLED', 'final_result' => 'CANCELLED']);

        return $c;
    }

    private function abortQueuedAttempts(Campaign $c, bool $cancelled): void
    {
        $life = app(CallLifecycle::class);
        CallAttempt::where('campaign_id', $c->id)->where('status', 'QUEUED')->where('finalized', false)->each(fn ($a) => $life->abortQueued($a, $cancelled));
    }
}
