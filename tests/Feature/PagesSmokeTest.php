<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class PagesSmokeTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    public function test_pages_render_for_user_and_admin(): void
    {
        $u = $this->makeUser();
        $admin = $this->makeAdmin();
        $did = $this->makeDid(3, $u);
        $c = $this->makeCampaign($u, $did, CampaignStatus::PENDING_APPROVAL);

        foreach ([$u, $admin] as $who) {
            foreach (['dashboard', 'campaigns.index', 'audio.index', 'reports'] as $r) {
                $this->actingAs($who)->get(route($r))->assertOk();
            }
            $this->actingAs($who)->get(route('campaigns.show', $c))->assertOk();
        }
        $this->actingAs($u)->get(route('campaigns.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dids.show', $did))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.show', $u))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.edit', $u))->assertOk();
        $this->actingAs($admin)->get(route('admin.dids.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.approvals'))->assertOk()->assertSee($c->name);
        auth()->logout();
        $this->get('/login')->assertOk();
    }

    public function test_admin_creates_user_did_and_assigns_with_audit(): void
    {
        $admin = $this->makeAdmin();
        $this->seedRoles();
        $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'N', 'email' => 'n@x.com', 'password' => 'a-very-long-pass-1', 'role' => 'user', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.dids.store'), ['number' => '8809611000001', 'status' => 'active', 'max_concurrent_calls' => 4])->assertSessionHasNoErrors();
        $user = \App\Models\User::where('email', 'n@x.com')->first();
        $did = \App\Models\Did::first();
        $this->actingAs($admin)->post(route('admin.dids.assign', $did), ['user_id' => $user->id])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.dids.update', $did), ['number' => '8809611000001', 'status' => 'active', 'max_concurrent_calls' => 9])->assertSessionHasNoErrors();

        foreach (['user.created', 'did.created', 'did.assigned', 'did.concurrency_changed'] as $a) {
            $this->assertDatabaseHas('audit_logs', ['action' => $a]);
        }
        $this->actingAs($user)->get(route('campaigns.create'))->assertSee('8809611000001');
    }
}
