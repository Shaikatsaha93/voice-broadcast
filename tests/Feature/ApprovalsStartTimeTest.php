<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class ApprovalsStartTimeTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    public function test_the_approver_sees_when_each_campaign_is_meant_to_start(): void
    {
        $owner = $this->makeUser();
        $did = $this->makeDid(3, $owner);
        $future = now()->addHours(5)->startOfMinute();
        $past = now()->subHours(2)->startOfMinute();

        $this->makeCampaign($owner, $did, CampaignStatus::PENDING_APPROVAL, 1, ['name' => 'Scheduled Later', 'scheduled_at' => $future]);
        $this->makeCampaign($owner, $did, CampaignStatus::PENDING_APPROVAL, 1, ['name' => 'Overdue Schedule', 'scheduled_at' => $past]);
        $this->makeCampaign($owner, $did, CampaignStatus::PENDING_APPROVAL, 1, ['name' => 'No Schedule']);

        $page = $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN))->get(route('admin.approvals'))->assertOk();

        $page->assertSee('Start time')
            ->assertSee($future->format('Y-m-d H:i'))      // the exact start the user asked for
            ->assertSee('from now')                        // relative hint ("in 5 hours" style)
            ->assertSee($past->format('Y-m-d H:i'))
            ->assertSee('time passed')                     // warns the approver it would start at once
            ->assertSee('starts immediately on approval')
            ->assertSee('no schedule set');
    }

    public function test_the_displayed_start_matches_what_approving_actually_does(): void
    {
        $owner = $this->makeUser();
        $did = $this->makeDid(3, $owner);
        $later = $this->makeCampaign($owner, $did, CampaignStatus::PENDING_APPROVAL, 1, ['scheduled_at' => now()->addHours(5)]);

        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN))->post(route('admin.approvals.approve', $later));

        // A future start is only queued, it does not start now (what the page said).
        $this->assertSame(CampaignStatus::QUEUED, $later->fresh()->status);
    }
}
