<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\AudioFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    public function test_user_cannot_see_another_users_campaign(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $c = $this->makeCampaign($b, $this->makeDid(2, $b), CampaignStatus::DRAFT);

        $this->actingAs($a)->get(route('campaigns.show', $c))->assertForbidden();
        $this->actingAs($a)->post(route('campaigns.pause', $c))->assertForbidden();
        $this->actingAs($a)->post(route('campaigns.cancel', $c))->assertForbidden();
        $this->actingAs($a)->delete(route('campaigns.destroy', $c))->assertForbidden();
        $this->actingAs($a)->put(route('campaigns.update', $c), ['name' => 'x'])->assertForbidden();
        $this->actingAs($a)->get(route('campaigns.index'))->assertDontSee($c->name);
    }

    public function test_user_cannot_use_another_users_did_or_audio(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $mine = $this->makeDid(2, $a);
        $theirs = $this->makeDid(2, $b);
        $foreignAudio = AudioFile::create(['user_id' => $b->id, 'original_name' => 'x.wav', 'path' => 'p', 'mime' => 'audio/wav', 'size' => 1, 'status' => 'READY']);

        $this->actingAs($a)->post(route('campaigns.store'), ['name' => 'n', 'did_id' => $theirs->id, 'max_attempts' => 1, 'requested_concurrency' => 1])->assertSessionHasErrors('did_id');
        $this->actingAs($a)->post(route('campaigns.store'), ['name' => 'n', 'did_id' => $mine->id, 'audio_file_id' => $foreignAudio->id, 'max_attempts' => 1, 'requested_concurrency' => 1])->assertSessionHasErrors('audio_file_id');
        $this->actingAs($a)->post(route('campaigns.store'), ['name' => 'n', 'did_id' => $mine->id, 'max_attempts' => 1, 'requested_concurrency' => 3])->assertSessionHasErrors('requested_concurrency');
        $this->actingAs($a)->get(route('audio.stream', $foreignAudio))->assertForbidden();
    }

    public function test_user_cannot_access_another_users_report_and_admin_can_access_all(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $c = $this->makeCampaign($b, $this->makeDid(2, $b), CampaignStatus::RUNNING, 1);
        $r = $c->recipients()->first();
        \App\Models\CallAttempt::create(['call_ref' => (string) \Illuminate\Support\Str::uuid(), 'campaign_id' => $c->id, 'recipient_id' => $r->id, 'did_id' => $c->did_id, 'user_id' => $b->id, 'phone' => '8801700000001', 'attempt_no' => 1, 'status' => 'NO_ANSWER']);

        \Livewire\Livewire::actingAs($a)->test(\App\Livewire\CallReport::class)->set('campaignId', $c->id)->assertDontSee('8801700000001');
        \Livewire\Livewire::actingAs($this->makeAdmin())->test(\App\Livewire\CallReport::class)->assertSee('8801700000001');
        \Livewire\Livewire::actingAs($b)->test(\App\Livewire\CallReport::class)->assertSee('8801700000001');
    }

    public function test_admin_only_routes_forbidden_for_normal_user_and_admin_can_view_all(): void
    {
        $u = $this->makeUser();
        $admin = $this->makeAdmin();
        $c = $this->makeCampaign($u, $this->makeDid(2, $u), CampaignStatus::DRAFT);

        foreach (['admin.users.index', 'admin.dids.index', 'admin.approvals', 'admin.audit'] as $route) {
            $this->actingAs($u)->get(route($route))->assertForbidden();
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
        $this->actingAs($admin)->get(route('campaigns.show', $c))->assertOk();
        $this->actingAs($u)->post(route('admin.approvals.approve', $c))->assertForbidden();
    }

    public function test_inactive_user_is_blocked_and_login_requires_active(): void
    {
        $u = $this->makeUser(attrs: ['status' => 'inactive']);
        $this->post('/login', ['email' => $u->email, 'password' => 'secret-password-123'])->assertSessionHasErrors('email');
        $this->actingAs($u)->get('/')->assertRedirect('/login');
    }

    public function test_did_shared_assignment_disabled_enforces_one_user_per_did(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeUser();
        $b = $this->makeUser();
        $did = $this->makeDid(2, $a);

        $this->actingAs($admin)->post(route('admin.dids.assign', $did), ['user_id' => $b->id])->assertSessionHasErrors('user_id');
        config(['broadcast.shared_did_assignment' => true]);
        $this->actingAs($admin)->post(route('admin.dids.assign', $did), ['user_id' => $b->id])->assertSessionHasNoErrors();
        $this->assertEquals(2, $did->users()->count());
    }
}
