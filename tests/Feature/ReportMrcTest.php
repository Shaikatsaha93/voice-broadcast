<?php

namespace Tests\Feature;

use App\Livewire\CallReport;
use App\Models\Did;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class ReportMrcTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    private function ownDid(string $number, string $mrc): Did
    {
        return Did::create(['number' => $number, 'label' => 'Own', 'did_type' => 'own', 'mrc' => $mrc, 'valid_until' => now()->addMonth(), 'status' => 'active', 'max_concurrent_calls' => 2]);
    }

    public function test_system_admin_sees_the_mrc_of_own_dids_and_the_total(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));
        $this->ownDid('8801700000001', '1500');
        $this->ownDid('8801700000002', '2500.50');
        Did::create(['number' => '8801700000003', 'label' => 'Resell', 'did_type' => 'reseller', 'status' => 'active', 'max_concurrent_calls' => 1]);

        Livewire::test(CallReport::class)
            ->assertSee('MRC of DIDs')->assertSee('8801700000001')->assertSee('8801700000002')
            ->assertSee('MRC expense')->assertSee('Net profit')->assertSee('4000.50')->assertSee('933.45')->assertDontSeeHtml('<td class="tabular-nums">8801700000003</td>');
    }

    public function test_did_filter_narrows_the_mrc(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));
        $a = $this->ownDid('8801700000001', '1500');
        $this->ownDid('8801700000002', '2500');

        Livewire::test(CallReport::class)->set('didId', $a->id)->assertSeeHtml('<td class="tabular-nums">8801700000001</td>')->assertDontSeeHtml('<td class="tabular-nums">8801700000002</td>');
    }

    public function test_a_normal_user_does_not_see_mrc(): void
    {
        $this->ownDid('8801700000001', '1500');
        $this->actingAs($this->makeUser(Role::USER));

        Livewire::test(CallReport::class)->assertDontSee('MRC');
    }

    public function test_cost_by_user_table_has_an_mrc_column_counted_once_per_did(): void
    {
        $admin = $this->makeUser(Role::SYSTEM_ADMIN);
        $user = $this->makeUser();
        $did = $this->ownDid('8801700000009', '1200');
        $c = $this->makeCampaign($user, $did, \App\Enums\CampaignStatus::RUNNING, 2);
        foreach ($c->recipients()->orderBy('id')->get() as $i => $r) {
            \App\Models\CallAttempt::create(['call_ref' => (string) \Illuminate\Support\Str::uuid(), 'campaign_id' => $c->id, 'recipient_id' => $r->id, 'did_id' => $did->id, 'user_id' => $user->id, 'phone' => $r->phone, 'attempt_no' => 1, 'status' => 'COMPLETED', 'answered_at' => now(), 'billsec' => 30, 'billed_at' => now(), 'cost' => 0]);
        }

        $this->actingAs($admin);
        Livewire::test(CallReport::class)->assertSee('MRC / month')->assertSeeHtml('<td class="text-right tabular-nums">৳1200.00</td></tr>')
            ->assertSeeHtml('<td class="text-right tabular-nums">৳1200.00</td></tr></tfoot>');
    }

    public function test_mrc_expense_is_spread_over_the_period_and_taken_off_the_profit(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));
        $this->ownDid('8801700000001', '3000');

        // One day = 3000 / 30 = 100.00; a 30-day range = the whole month.
        $t = Livewire::withQueryParams(['dateMode' => 'single'])->test(CallReport::class)->set('dateMode', 'single')->set('date', now()->toDateString());
        $this->assertSame(100.0, $t->viewData('mrcExpense'));
        $this->assertSame(-100.0, $t->viewData('netProfit'));

        $t = Livewire::withQueryParams(['dateMode' => 'range'])->test(CallReport::class)->set('from', now()->subDays(29)->toDateString())->set('to', now()->toDateString());
        $this->assertSame(3000.0, $t->viewData('mrcExpense'));

        // All time: no start date, so no expense can be spread.
        $t = Livewire::withQueryParams(['dateMode' => 'range'])->test(CallReport::class)->set('from', '')->set('to', '');
        $this->assertNull($t->viewData('mrcExpense'));
        $this->assertNull($t->viewData('netProfit'));
    }
}
