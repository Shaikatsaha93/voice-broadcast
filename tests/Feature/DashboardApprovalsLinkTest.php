<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Livewire\DashboardStats;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class DashboardApprovalsLinkTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    public function test_pending_campaigns_card_opens_the_approvals_page_for_system_and_super_admin(): void
    {
        foreach ([Role::SYSTEM_ADMIN, Role::SUPER_ADMIN] as $role) {
            $this->actingAs($this->makeUser($role, ['email' => $role.'@example.com']));

            Livewire::test(DashboardStats::class)
                ->assertSee('Pending campaigns')
                ->assertSeeHtml('href="'.route('admin.approvals').'"');

            $this->get(route('admin.approvals'))->assertOk(); // the link never leads to a 403
        }
    }

    public function test_the_count_matches_what_the_approvals_page_lists(): void
    {
        $owner = $this->makeUser();
        $did = $this->makeDid(3, $owner);
        $this->makeCampaign($owner, $did, CampaignStatus::PENDING_APPROVAL, 1, ['name' => 'Waiting One']);
        $this->makeCampaign($owner, $did, CampaignStatus::RUNNING, 1, ['name' => 'Already Running']);

        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        Livewire::test(DashboardStats::class)->assertSeeHtml('Pending campaigns');
        $this->get(route('admin.approvals'))->assertOk()->assertSee('Waiting One')->assertDontSee('Already Running');
    }

    public function test_a_plain_admin_and_a_normal_user_get_no_approvals_link(): void
    {
        foreach ([Role::ADMIN, Role::USER] as $role) {
            $this->actingAs($this->makeUser($role, ['email' => $role.'@example.com']));

            Livewire::test(DashboardStats::class)->assertDontSeeHtml('href="'.route('admin.approvals').'"');
        }
        $this->get(route('admin.approvals'))->assertForbidden();
    }
}
