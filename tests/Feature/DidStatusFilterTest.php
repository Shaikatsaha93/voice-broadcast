<?php

namespace Tests\Feature;

use App\Livewire\DashboardStats;
use App\Models\Did;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class DidStatusFilterTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    private function dids(): array
    {
        $a = $this->makeDid(); $a->update(['number' => '8809000000001']);
        $b = $this->makeDid(); $b->update(['number' => '8809000000002']);
        $c = $this->makeDid(); $c->update(['number' => '8809000000003', 'status' => 'inactive']);

        return [$a, $b, $c];
    }

    public function test_active_dids_card_links_to_the_filtered_dids_page(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        Livewire::test(DashboardStats::class)
            ->assertSee('Active DIDs')
            ->assertSeeHtml('href="'.route('admin.dids.index', ['status' => 'active']).'"');
    }

    public function test_status_active_shows_only_active_dids_and_a_way_back(): void
    {
        $this->dids();
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->get(route('admin.dids.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee('8809000000001')->assertSee('8809000000002')
            ->assertDontSee('8809000000003')
            ->assertSee('Showing only active DIDs')->assertSee('Show all DIDs');

        $this->get(route('admin.dids.index'))
            ->assertOk()->assertSee('8809000000001')->assertSee('8809000000003')
            ->assertDontSee('Showing only');
    }

    public function test_inactive_filter_works_and_unknown_values_are_ignored(): void
    {
        $this->dids();
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->get(route('admin.dids.index', ['status' => 'inactive']))
            ->assertOk()->assertSee('8809000000003')->assertDontSee('8809000000001');

        // Anything else is ignored (no error, no SQL surprises): the full list is shown.
        $this->get(route('admin.dids.index', ['status' => "x' OR 1=1 --"]))
            ->assertOk()->assertSee('8809000000001')->assertSee('8809000000003')->assertDontSee('Showing only');
    }

    public function test_the_filter_never_widens_what_an_admin_may_see(): void
    {
        [$a] = $this->dids();
        $admin = $this->makeUser(Role::ADMIN, ['email' => 'plain-admin@example.com']);
        $this->actingAs($admin);

        // Plain Admin only sees DIDs created by themselves/their team: none of these.
        $this->get(route('admin.dids.index', ['status' => 'active']))
            ->assertOk()->assertDontSee($a->number);
    }

    public function test_pagination_keeps_the_filter(): void
    {
        for ($i = 0; $i < 27; $i++) {
            Did::create(['number' => '88091'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'label' => 'L', 'status' => 'active', 'max_concurrent_calls' => 1]);
        }
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->get(route('admin.dids.index', ['status' => 'active']))
            ->assertOk()->assertSee('status=active', false);
    }
}
