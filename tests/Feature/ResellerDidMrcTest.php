<?php

namespace Tests\Feature;

use App\Models\Did;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class ResellerDidMrcTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return $extra + [
            'number' => '8801711000001', 'status' => 'active', 'max_concurrent_calls' => 2, 'did_type' => 'reseller',
            'rate_per_pulse' => 0.5, 'pulse_seconds' => 60, 'valid_until' => now()->addMonth()->toDateString(),
            'sip_host' => 'sip.example.com', 'sip_username' => 'u1', 'sip_password' => 'secret-pass',
        ];
    }

    public function test_reseller_form_has_an_mrc_field_that_defaults_to_zero(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->get(route('admin.dids.create'))->assertOk()->assertSee('Enter 0 if this reseller DID has no monthly charge')->assertSee('placeholder="MRC per month (0 = none)"', false);
    }

    public function test_reseller_did_can_be_saved_with_an_mrc(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->post(route('admin.dids.store'), $this->payload(['mrc' => '750.50']))->assertSessionHasNoErrors();

        $this->assertEquals(750.5, Did::firstWhere('number', '8801711000001')->mrc);
    }

    public function test_mrc_can_be_zero_or_left_blank_on_a_reseller_did(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->post(route('admin.dids.store'), $this->payload(['mrc' => '0']))->assertSessionHasNoErrors();
        $this->post(route('admin.dids.store'), $this->payload(['number' => '8801711000002']))->assertSessionHasNoErrors();

        $this->assertEquals(0, Did::firstWhere('number', '8801711000001')->mrc);
        $this->assertEquals(0, Did::firstWhere('number', '8801711000002')->mrc);
    }

    public function test_negative_mrc_is_rejected_and_own_did_still_needs_an_mrc_above_zero(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->post(route('admin.dids.store'), $this->payload(['mrc' => '-5']))->assertSessionHasErrors('mrc');
        $this->post(route('admin.dids.store'), $this->payload(['did_type' => 'own', 'mrc' => '0']))->assertSessionHasErrors('mrc');
    }

    public function test_reseller_mrc_shows_on_the_did_page_and_in_the_report(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));
        $this->post(route('admin.dids.store'), $this->payload(['mrc' => '300']))->assertSessionHasNoErrors();
        $did = Did::firstWhere('number', '8801711000001');

        $this->get(route('admin.dids.show', $did))->assertOk()->assertSee('MRC ৳300.00');
        \Livewire\Livewire::test(\App\Livewire\CallReport::class)->assertSee('MRC of DIDs')->assertSeeHtml('<td class="tabular-nums">8801711000001</td>');
    }
}
