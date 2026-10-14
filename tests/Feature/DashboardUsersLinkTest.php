<?php

namespace Tests\Feature;

use App\Livewire\DashboardStats;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class DashboardUsersLinkTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    public function test_total_users_card_links_to_the_users_page_for_an_admin(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test(DashboardStats::class)
            ->assertSee('Total users')
            ->assertSeeHtml('href="'.route('admin.users.index').'"');

        $this->get(route('admin.users.index'))->assertOk();
    }

    public function test_total_dids_card_links_to_the_dids_page_for_every_manager_role(): void
    {
        foreach ([Role::SYSTEM_ADMIN, Role::SUPER_ADMIN, Role::ADMIN] as $role) {
            $user = $this->makeUser($role, ['email' => $role.'@example.com']);
            $this->actingAs($user);

            Livewire::test(DashboardStats::class)
                ->assertSee('Total DIDs')
                ->assertSeeHtml('href="'.route('admin.dids.index').'"');

            $this->get(route('admin.dids.index'))->assertOk(); // the link never leads to a 403
        }
    }
    public function test_a_normal_user_has_no_total_users_card_or_link(): void
    {
        $this->actingAs($this->makeUser());

        Livewire::test(DashboardStats::class)
            ->assertDontSee('Total users')
            ->assertDontSeeHtml('href="'.route('admin.users.index').'"')
            ->assertDontSeeHtml('href="'.route('admin.dids.index').'"');
    }
}
