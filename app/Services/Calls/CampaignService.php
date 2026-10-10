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

        // Approval starts the campaign by itself: right away, or QUEUED until its scheduled time.
        return $this->start($c);
    }

    public function reject(Campaign $c, User $admin, string $reason): Campaign
    {
        $c = $this->transition($c, CampaignStatus::REJECTED, ['rejection_reason' => $reason], 'campaign.rejected');
        CampaignApproval::create(['campaign_id' => $c->id, 'admin_id' => $admin->id, 'decision' => 'REJECTED', 'reason' => $reason]);
        Audit::log('campaign.rejection_reason', $c, null, null, ['reason' => $reason]);

        return $c;
    }

    /** Start an APPROVED campaign (done automatically on approval). Future scheduled_at => QUEUED until the scheduler starts it. */
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

    /** Statuses a recipient can be retried from, as offered in the UI. */
    public const RETRY_SECTIONS = ['ANSWERED', 'NO_ANSWER', 'BUSY', 'FAILED', 'CANCELLED'];

    /** Restrict a recipients query to one result card: Answered / No answer / Busy / Failed / Cancelled. */
    public static function sectionScope($q, string $key)
    {
        if ($key === 'CANCELLED') {
            return $q->where('status', 'CANCELLED');
        }
        // A number belongs to a section when any of its finished calls had that result (same as the call report),
        // as long as it is not being called right now.
        $calls = \App\Models\CallAttempt::query()->select('recipient_id')->where('finalized', true)->where(fn ($w) => match ($key) {
            'ANSWERED' => $w->whereNotNull('answered_at'),
            'NO_ANSWER' => $w->whereNull('answered_at')->where('status', 'NO_ANSWER'),
            'BUSY' => $w->whereNull('answered_at')->where('status', 'BUSY'),
            'FAILED' => $w->whereNull('answered_at')->whereIn('status', ['FAILED', 'TEMPORARY_FAILURE', 'INVALID_NUMBER']),
        });

        return $q->whereNotIn('status', ['PENDING', 'IN_PROGRESS'])->whereIn('id', $calls);
    }

    /**
     * Calls (attempts) per result, the same numbers the call report shows:
     * answered / no answer / busy / failed, plus numbers cancelled before dialing.
     */
    public function callCounts(Campaign $c): array
    {
        $r = $c->attempts()->where('finalized', true)->selectRaw(
            "coalesce(sum(answered_at is not null),0) answered,
             coalesce(sum(answered_at is null and status = 'NO_ANSWER'),0) no_answer,
             coalesce(sum(answered_at is null and status = 'BUSY'),0) busy,
             coalesce(sum(answered_at is null and status in ('FAILED','TEMPORARY_FAILURE','INVALID_NUMBER')),0) failed"
        )->first();

        return [
            'ANSWERED' => (int) $r->answered, 'NO_ANSWER' => (int) $r->no_answer, 'BUSY' => (int) $r->busy, 'FAILED' => (int) $r->failed,
            'CANCELLED' => self::sectionScope($c->recipients()->getQuery(), 'CANCELLED')->count(),
        ];
    }

    /** Numbers whose last result was this one but that still have an automatic retry coming. */
    public function waitingCounts(Campaign $c): array
    {
        $out = ['ANSWERED' => 0, 'NO_ANSWER' => 0, 'BUSY' => 0, 'FAILED' => 0, 'CANCELLED' => 0];
        foreach ($c->recipients()->where('status', 'RETRY_PENDING')->selectRaw('final_result, count(*) c')->groupBy('final_result')->pluck('c', 'final_result') as $result => $n) {
            $out[match ($result) { 'NO_ANSWER' => 'NO_ANSWER', 'BUSY' => 'BUSY', default => 'FAILED' }] += (int) $n;
        }

        return $out;
    }

    /** How many numbers had a call with each result and can be retried now. */
    public function outcomeCounts(Campaign $c): array
    {
        $out = [];
        foreach (self::RETRY_SECTIONS as $key) {
            $out[$key] = self::sectionScope($c->recipients()->getQuery(), $key)->count();
        }

        return $out;
    }

    /**
     * Put finished recipients back in the queue. $sections is a list of RETRY_SECTIONS (or 'all').
     * A COMPLETED or CANCELLED campaign goes back to RUNNING; a PAUSED one waits for resume.
     * Returns the number of recipients re-queued.
     */
    public function retry(Campaign $c, array|string $sections): int
    {
        if (! in_array($c->status, [CampaignStatus::COMPLETED, CampaignStatus::CANCELLED, CampaignStatus::RUNNING, CampaignStatus::PAUSED], true)) {
            throw ValidationException::withMessages(['section' => 'Only completed, cancelled, running or paused campaigns can be retried.']);
        }
        $statuses = $sections === 'all' ? self::RETRY_SECTIONS : array_values(array_unique((array) $sections));
        if (! $statuses || array_diff($statuses, self::RETRY_SECTIONS)) {
            throw ValidationException::withMessages(['section' => 'Select at least one valid call section.']);
        }

        $count = $c->recipients()->where(function ($q) use ($statuses) {
            foreach ($statuses as $key) {
                $q->orWhere(fn ($w) => self::sectionScope($w, $key));
            }
        })->update([
            'status' => 'PENDING', 'final_result' => null, 'next_attempt_at' => null, 'retry_base' => DB::raw('attempts_count'),
        ]);
        if ($count === 0) {
            throw ValidationException::withMessages(['section' => 'No calls in that section to retry.']);
        }

        Audit::log('campaign.retry', $c, null, null, ['sections' => $statuses, 'recipients' => $count]);

        if (in_array($c->status, [CampaignStatus::COMPLETED, CampaignStatus::CANCELLED], true)) {
            $c = $this->transition($c, CampaignStatus::RUNNING, ['completed_at' => null], 'campaign.reopened');
        }
        if ($c->status === CampaignStatus::RUNNING) {
            DispatchCampaignCalls::dispatch($c->id);
        }

        return $count;
    }

    private function abortQueuedAttempts(Campaign $c, bool $cancelled): void
    {
        $life = app(CallLifecycle::class);
        CallAttempt::where('campaign_id', $c->id)->where('status', 'QUEUED')->where('finalized', false)->each(fn ($a) => $life->abortQueued($a, $cancelled));
    }
}
