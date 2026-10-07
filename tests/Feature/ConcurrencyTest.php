<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Enums\CampaignStatus;
use App\Models\CallAttempt;
use App\Models\DidSlot;
use App\Services\Calls\CampaignDispatcher;
use App\Services\Calls\Reconciler;
use App\Services\Did\DidSlotManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    public function test_did_cannot_exceed_maximum_and_slots_release(): void
    {
        Queue::fake();
        $u = $this->makeUser();
        $did = $this->makeDid(3, $u);
        $c = $this->makeCampaign($u, $did, CampaignStatus::RUNNING, 10, ['requested_concurrency' => 3]);

        $queued = app(CampaignDispatcher::class)->dispatch($c);

        $this->assertSame(3, $queued);
        $this->assertSame(3, DidSlot::count());
        $this->assertSame(0, app(DidSlotManager::class)->available($did));

        app(DidSlotManager::class)->release(CallAttempt::first());
        $this->assertSame(2, DidSlot::count());
        $this->assertSame(1, app(CampaignDispatcher::class)->dispatch($c));
        $this->assertSame(3, DidSlot::count());
    }

    public function test_campaign_requested_concurrency_is_respected(): void
    {
        Queue::fake();
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(10, $u), CampaignStatus::RUNNING, 10, ['requested_concurrency' => 2]);

        $this->assertSame(2, app(CampaignDispatcher::class)->dispatch($c));
    }

    public function test_campaigns_on_different_dids_run_simultaneously(): void
    {
        Queue::fake();
        $u = $this->makeUser();
        $d1 = $this->makeDid(2, $u);
        $d2 = $this->makeDid(3, $u);
        $a = $this->makeCampaign($u, $d1, CampaignStatus::RUNNING, 10, ['requested_concurrency' => 2]);
        $b = $this->makeCampaign($u, $d2, CampaignStatus::RUNNING, 10, ['requested_concurrency' => 3]);

        app(CampaignDispatcher::class)->dispatch($a);
        app(CampaignDispatcher::class)->dispatch($b);

        $this->assertSame(2, DidSlot::where('did_id', $d1->id)->count());
        $this->assertSame(3, DidSlot::where('did_id', $d2->id)->count());
        $this->assertSame(5, DidSlot::count());
    }

    public function test_campaigns_on_same_did_share_capacity(): void
    {
        Queue::fake();
        $u = $this->makeUser();
        $did = $this->makeDid(10, $u);
        $a = $this->makeCampaign($u, $did, CampaignStatus::RUNNING, 20, ['requested_concurrency' => 7]);
        $b = $this->makeCampaign($u, $did, CampaignStatus::RUNNING, 20, ['requested_concurrency' => 8]);

        app(CampaignDispatcher::class)->dispatch($a);
        app(CampaignDispatcher::class)->dispatch($b);

        $this->assertSame(10, DidSlot::where('did_id', $did->id)->count());
        $this->assertSame(7, DidSlot::whereIn('call_attempt_id', CallAttempt::where('campaign_id', $a->id)->select('id'))->count());
        $this->assertSame(3, DidSlot::whereIn('call_attempt_id', CallAttempt::where('campaign_id', $b->id)->select('id'))->count());
        // rejected claims must not leak attempts / recipient state
        $this->assertSame(10, CallAttempt::count());
        $this->assertSame(7 + 3, \App\Models\CampaignRecipient::where('status', 'IN_PROGRESS')->count());
    }

    public function test_stale_slots_are_recovered(): void
    {
        Queue::fake();
        $u = $this->makeUser();
        $did = $this->makeDid(3, $u);
        $c = $this->makeCampaign($u, $did, CampaignStatus::RUNNING, 3, ['requested_concurrency' => 3]);
        app(CampaignDispatcher::class)->dispatch($c);
        CallAttempt::query()->update(['status' => CallStatus::DIALING, 'status_rank' => 2]);
        DidSlot::query()->update(['expires_at' => now()->subSeconds(500)]);

        $this->assertSame(3, DidSlot::count());
        app(Reconciler::class)->run(); // dry-run Asterisk (unknown) + slot older than 2x TTL => recovered

        $this->assertSame(0, DidSlot::count());
        $this->assertSame(3, CallAttempt::where('finalized', true)->count());
        $this->assertSame(3, \App\Models\CampaignRecipient::where('status', 'RETRY_PENDING')->count());
    }

    public function test_orphan_slot_of_finalized_attempt_is_removed(): void
    {
        Queue::fake();
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::RUNNING, 1);
        app(CampaignDispatcher::class)->dispatch($c);
        CallAttempt::query()->update(['finalized' => true]);

        $this->assertSame(1, app(Reconciler::class)->run()['orphans']);
        $this->assertSame(0, DidSlot::count());
    }
}
