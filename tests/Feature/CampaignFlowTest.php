<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Enums\CampaignStatus;
use App\Jobs\DispatchCampaignCalls;
use App\Jobs\OriginateBroadcastCall;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\DidSlot;
use App\Services\Asterisk\AsteriskEventProcessor;
use App\Services\Asterisk\AsteriskService;
use App\Services\Calls\CampaignDispatcher;
use App\Services\Calls\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class CampaignFlowTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    private function fakeAsterisk(bool $ok = true): object
    {
        $fake = new class($ok) extends AsteriskService
        {
            public array $originated = [];

            public function __construct(public bool $ok)
            {
            }

            public function originate(CallAttempt $attempt): bool
            {
                $this->originated[] = $attempt->call_ref;

                return $this->ok;
            }

            public function activeChannelIds(): ?array
            {
                return null;
            }
        };
        $this->app->instance(AsteriskService::class, $fake);

        return $fake;
    }

    public function test_campaign_cannot_run_before_approval_and_rejected_cannot_run(): void
    {
        $u = $this->makeUser();
        $svc = app(CampaignService::class);
        foreach ([CampaignStatus::DRAFT, CampaignStatus::PENDING_APPROVAL, CampaignStatus::REJECTED] as $s) {
            $c = $this->makeCampaign($u, $this->makeDid(2, $u), $s);
            try {
                $svc->start($c);
                $this->fail("start allowed from {$s->value}");
            } catch (ValidationException) {
            }
            $this->assertSame($s, $c->fresh()->status);
            $this->assertSame(0, app(CampaignDispatcher::class)->dispatch($c));
        }
    }

    public function test_full_workflow_submit_approve_start(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::DRAFT, 3, ["requested_concurrency" => 2]);
        $svc = app(CampaignService::class);

        $svc->submit($c);
        $this->assertSame(CampaignStatus::PENDING_APPROVAL, $c->fresh()->status);
        Queue::assertNothingPushed(); // not called automatically

        $svc->approve($c->fresh(), $admin);
        Queue::assertNothingPushed(); // approval alone does not dial

        $svc->start($c->fresh());
        $this->assertSame(CampaignStatus::RUNNING, $c->fresh()->status);
        Queue::assertPushed(DispatchCampaignCalls::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.approved']);
    }

    public function test_reject_requires_reason_via_http(): void
    {
        $admin = $this->makeAdmin();
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::PENDING_APPROVAL);

        $this->actingAs($admin)->post(route('admin.approvals.reject', $c), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.approvals.reject', $c), ['reason' => 'bad audio'])->assertSessionHasNoErrors();
        $this->assertSame('bad audio', $c->fresh()->rejection_reason);
    }

    public function test_pause_resume_cancel(): void
    {
        Queue::fake();
        $fake = $this->fakeAsterisk();
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::RUNNING, 6, ['requested_concurrency' => 2]);
        $svc = app(CampaignService::class);

        app(CampaignDispatcher::class)->dispatch($c); // 2 queued attempts, slots held
        $this->assertSame(2, CallAttempt::count());

        $svc->pause($c); // queued-not-originated attempts are aborted, no call is made
        $this->assertSame(CampaignStatus::PAUSED, $c->fresh()->status);
        $this->assertSame(0, CallAttempt::count());
        $this->assertSame(0, DidSlot::count());
        $this->assertSame(0, app(CampaignDispatcher::class)->dispatch($c->fresh()));
        $this->assertSame(6, CampaignRecipient::where('status', 'PENDING')->count());

        $svc->resume($c->fresh());
        $this->assertSame(2, app(CampaignDispatcher::class)->dispatch($c->fresh()));

        $svc->cancel($c->fresh());
        $this->assertSame(CampaignStatus::CANCELLED, $c->fresh()->status);
        $this->assertSame(0, DidSlot::count());
        $this->assertSame(0, CampaignRecipient::whereIn('status', ['PENDING', 'IN_PROGRESS', 'RETRY_PENDING'])->count());
        $this->assertSame([], $fake->originated);
    }

    public function test_call_attempt_created_and_events_update_state_idempotently_and_out_of_order(): void
    {
        Queue::fake();
        $this->fakeAsterisk();
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::RUNNING, 1);
        app(CampaignDispatcher::class)->dispatch($c);
        $a = CallAttempt::first();
        $this->assertSame(CallStatus::QUEUED, $a->status);

        (new OriginateBroadcastCall($a->id))->handle(app(AsteriskService::class), app(\App\Services\Calls\CallLifecycle::class));
        $this->assertSame(CallStatus::DIALING, $a->fresh()->status);

        $p = app(AsteriskEventProcessor::class);
        $ref = $a->call_ref;
        $up = ['Event' => 'Newstate', 'Uniqueid' => $ref, 'Linkedid' => $ref, 'Channel' => 'PJSIP/x-1', 'ChannelStateDesc' => 'Up', 'Timestamp' => '1.1'];
        $ring = ['Event' => 'Newstate', 'Uniqueid' => $ref, 'Linkedid' => $ref, 'ChannelStateDesc' => 'Ringing', 'Timestamp' => '1.0'];

        $p->process($up);
        $p->process($up); // duplicate
        $p->process($ring); // delayed / out of order: must not move backwards
        $this->assertSame(CallStatus::ANSWERED, $a->fresh()->status);

        $p->process(['Event' => 'UserEvent', 'UserEvent' => 'PlaybackStart', 'Uniqueid' => $ref, 'Timestamp' => '2']);
        $p->process(['Event' => 'UserEvent', 'UserEvent' => 'PlaybackComplete', 'Uniqueid' => $ref, 'Timestamp' => '3']);
        $this->assertSame(CallStatus::PLAYBACK_COMPLETED, $a->fresh()->status);

        $hang = ['Event' => 'Hangup', 'Uniqueid' => $ref, 'Cause' => '16', 'Timestamp' => '4'];
        $p->process($hang);
        $p->process($hang);
        $a->refresh();
        $this->assertSame(CallStatus::COMPLETED, $a->status);
        $this->assertTrue($a->finalized);
        $this->assertSame(0, DidSlot::count()); // slot released
        $this->assertSame('ANSWERED', $c->recipients()->first()->status);
    }

    public function test_retry_until_exhausted_and_each_attempt_is_stored(): void
    {
        Queue::fake();
        $this->fakeAsterisk();
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::RUNNING, 1, ['max_attempts' => 3]);
        $p = app(AsteriskEventProcessor::class);

        foreach ([[1, ['DialStatus' => 'NOANSWER'], 'RETRY_PENDING'], [2, ['DialStatus' => 'BUSY'], 'RETRY_PENDING'], [3, ['DialStatus' => 'NOANSWER'], 'EXHAUSTED']] as [$n, $dial, $expect]) {
            CampaignRecipient::query()->update(['next_attempt_at' => null]); // retry delay elapsed
            $this->assertSame(1, app(CampaignDispatcher::class)->dispatch($c));
            $a = CallAttempt::where('attempt_no', $n)->firstOrFail();
            $this->assertSame($n, $a->attempt_no);
            (new OriginateBroadcastCall($a->id))->handle(app(AsteriskService::class), app(\App\Services\Calls\CallLifecycle::class));
            $p->process(['Event' => 'DialEnd', 'Uniqueid' => $a->call_ref, 'Timestamp' => "d$n"] + $dial);
            $p->process(['Event' => 'Hangup', 'Uniqueid' => $a->call_ref, 'Cause' => '19', 'Timestamp' => "h$n"]);
            $this->assertSame($expect, $c->recipients()->first()->status, "after attempt $n");
        }

        $this->assertSame(['NO_ANSWER', 'BUSY', 'NO_ANSWER'], CallAttempt::orderBy('attempt_no')->get()->map->status->map->value->all());
        $this->assertSame(0, app(CampaignDispatcher::class)->dispatch($c)); // never a 4th attempt
    }

    public function test_answered_and_invalid_numbers_are_not_retried_and_originate_failure_is_retryable(): void
    {
        Queue::fake();
        $this->fakeAsterisk(false);
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::RUNNING, 1);

        app(CampaignDispatcher::class)->dispatch($c);
        $a = CallAttempt::first();
        (new OriginateBroadcastCall($a->id))->handle(app(AsteriskService::class), app(\App\Services\Calls\CallLifecycle::class));
        $this->assertSame('RETRY_PENDING', $c->recipients()->first()->status); // TEMPORARY_FAILURE is retryable
        $this->assertSame(0, DidSlot::count());

        $c2 = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::RUNNING, 1);
        app(CampaignDispatcher::class)->dispatch($c2);
        $b = CallAttempt::where('campaign_id', $c2->id)->first();
        app(AsteriskEventProcessor::class)->process(['Event' => 'Hangup', 'Uniqueid' => $b->call_ref, 'Cause' => '1', 'Timestamp' => 'x']);
        $this->assertSame('FAILED', $c2->recipients()->first()->status);
        $this->assertSame(CallStatus::INVALID_NUMBER, $b->fresh()->status);
    }

    public function test_duplicate_originate_jobs_do_not_duplicate_calls(): void
    {
        Queue::fake();
        $fake = $this->fakeAsterisk();
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::RUNNING, 1);
        app(CampaignDispatcher::class)->dispatch($c);
        $a = CallAttempt::first();

        foreach (range(1, 4) as $_) {
            (new OriginateBroadcastCall($a->id))->handle(app(AsteriskService::class), app(\App\Services\Calls\CallLifecycle::class));
        }
        $this->assertCount(1, $fake->originated);

        // duplicate dispatcher runs cannot create a second attempt for the same recipient/attempt number
        app(CampaignDispatcher::class)->dispatch($c);
        app(CampaignDispatcher::class)->dispatch($c);
        $this->assertSame(1, CallAttempt::count());
    }

    public function test_submit_validations(): void
    {
        $u = $this->makeUser();
        $did = $this->makeDid(2, $u);
        $c = $this->makeCampaign($u, $did, CampaignStatus::DRAFT, 0); // no recipients
        $this->expectException(ValidationException::class);
        app(CampaignService::class)->submit($c);
    }
}
