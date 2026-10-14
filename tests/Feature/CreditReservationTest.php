<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Enums\CampaignStatus;
use App\Jobs\EnforceCallCreditLimit;
use App\Models\CallAttempt;
use App\Models\Role;
use App\Services\Asterisk\AsteriskService;
use App\Services\Calls\CallLifecycle;
use App\Services\Calls\CampaignDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class CreditReservationTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    /** DID with a rate (per 60 s pulse) and balance; the campaign audio is $audioSeconds long. */
    private function setup_(float $rate, float $balance, float $audioSeconds, $user = null, int $recipients = 6)
    {
        Queue::fake();
        $user ??= $this->makeUser();
        $did = $this->makeDid(10, $user);
        $did->update(['rate_per_pulse' => $rate, 'pulse_seconds' => 60, 'balance' => $balance]);
        $c = $this->makeCampaign($user, $did, CampaignStatus::RUNNING, $recipients, ['requested_concurrency' => 10]);
        $c->audio->update(['duration' => $audioSeconds]);

        return [$did, $c->refresh()];
    }

    public function test_each_call_reserves_the_pulses_of_its_audio_length_not_just_one_pulse(): void
    {
        // 100 s audio + 10 s grace = 110 s = 2 pulses = 2.00 per call. Balance 5.00 covers two calls (4.00), not three (6.00).
        [, $c] = $this->setup_(1.0, 5.0, 100);

        $this->assertSame(2, app(CampaignDispatcher::class)->dispatch($c));
        $this->assertSame('INSUFFICIENT_BALANCE', $c->fresh()->blocked_reason);
    }

    public function test_a_short_audio_reserves_one_pulse(): void
    {
        // 20 s + 10 s = 30 s = 1 pulse per call: balance 3.00 covers three calls.
        [, $c] = $this->setup_(1.0, 3.0, 20);

        $this->assertSame(3, app(CampaignDispatcher::class)->dispatch($c));
    }

    public function test_the_call_gets_a_max_duration_of_its_reserved_pulses(): void
    {
        [, $c] = $this->setup_(1.0, 5.0, 100);
        app(CampaignDispatcher::class)->dispatch($c);

        $this->assertSame([120, 120], CallAttempt::orderBy('id')->pluck('max_seconds')->all()); // 2 pulses x 60 s
    }

    public function test_a_free_did_has_no_limit(): void
    {
        [, $c] = $this->setup_(0.0, 0.0, 100, recipients: 3);

        $this->assertSame(3, app(CampaignDispatcher::class)->dispatch($c));
        $this->assertNull(CallAttempt::first()->max_seconds);
    }

    public function test_admin_wallet_is_reserved_the_same_way(): void
    {
        $admin = $this->makeUser(Role::ADMIN, ['rate_per_pulse' => 0.5, 'pulse_seconds' => 60, 'balance' => 1.0]);
        $user = $this->makeUser(Role::USER, ['created_by' => $admin->id]);
        // DID is free; the Admin pays 0.50 per pulse: one call (2 pulses = 1.00) fits, a second does not.
        [, $c] = $this->setup_(0.0, 0.0, 100, $user, 4);

        $this->assertSame(1, app(CampaignDispatcher::class)->dispatch($c));
        $this->assertSame('ADMIN_BALANCE', $c->fresh()->blocked_reason);
        $this->assertSame(120, CallAttempt::first()->max_seconds);
    }

    public function test_answering_schedules_the_hangup_at_the_limit(): void
    {
        [, $c] = $this->setup_(1.0, 5.0, 100, recipients: 1);
        app(CampaignDispatcher::class)->dispatch($c);
        $a = CallAttempt::first();

        app(CallLifecycle::class)->advance($a, CallStatus::ANSWERED, ['answered_at' => now()]);
        app(CallLifecycle::class)->advance($a->fresh(), CallStatus::PLAYBACK_STARTED, ['answered_at' => now()]); // a later event must not schedule it again

        Queue::assertPushed(EnforceCallCreditLimit::class, 1);
        Queue::assertPushed(EnforceCallCreditLimit::class, fn ($j) => $j->attemptId === $a->id && $j->delay === 118);
    }

    public function test_the_limit_job_hangs_up_a_running_call_but_not_a_finished_one(): void
    {
        [, $c] = $this->setup_(1.0, 5.0, 100, recipients: 2);
        app(CampaignDispatcher::class)->dispatch($c);
        [$running, $done] = CallAttempt::orderBy('id')->get()->all();
        $running->update(['answered_at' => now(), 'status' => CallStatus::ANSWERED]);
        $done->update(['answered_at' => now(), 'finalized' => true]);

        $asterisk = \Mockery::mock(AsteriskService::class);
        $asterisk->shouldReceive('hangup')->once()->with($running->call_ref);

        (new EnforceCallCreditLimit($running->id))->handle($asterisk);
        (new EnforceCallCreditLimit($done->id))->handle($asterisk);
        $this->addToAssertionCount(1);
    }
}
