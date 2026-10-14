<?php

namespace Tests\Feature;

use App\Livewire\DashboardStats;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class DashboardMyCampaignsLinkTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    public function test_my_campaigns_card_opens_the_campaigns_page_for_a_user(): void
    {
        $this->actingAs($this->makeUser(Role::USER));

        Livewire::test(DashboardStats::class)
            ->assertSee('My campaigns')
            ->assertSeeHtml('href="'.route('campaigns.index').'"');

        $this->get(route('campaigns.index'))->assertOk();
    }
}
