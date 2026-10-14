<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Livewire\CallReport;
use App\Models\AuditLog;
use App\Models\CallAttempt;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    private function attempt(Campaign $c, string $phone, string $status, int $nth = 0): CallAttempt
    {
        $r = $c->recipients()->orderBy('id')->skip($nth)->first();

        return CallAttempt::create(['call_ref' => (string) Str::uuid(), 'campaign_id' => $c->id, 'recipient_id' => $r->id, 'did_id' => $c->did_id, 'user_id' => $c->user_id, 'phone' => $phone, 'attempt_no' => 1, 'status' => $status]);
    }

    public function test_export_downloads_only_own_filtered_rows_and_is_audited(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser(attrs: ['email' => 'b@example.com']);
        $ca = $this->makeCampaign($a, $this->makeDid(3, $a), CampaignStatus::RUNNING, 2, ['name' => '=cmd|calc']);
        $cb = $this->makeCampaign($b, $this->makeDid(3, $b), CampaignStatus::RUNNING, 1);
        $this->attempt($ca, '8801711111111', 'ANSWERED');
        $this->attempt($ca, '8801722222222', 'BUSY', 1);
        $this->attempt($cb, '8801733333333', 'ANSWERED'); // another user's call

        $this->actingAs($a);
        $all = Livewire::test(CallReport::class)->call('export');
        $all->assertFileDownloaded();
        $body = $all->effects['download']['content'] ?? '';
        $this->assertNotSame('', $body);
        $csv = base64_decode($body);
        $this->assertStringContainsString('8801711111111', $csv);
        $this->assertStringContainsString('8801722222222', $csv);
        $this->assertStringNotContainsString('8801733333333', $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv); // formula injection neutralised
        $this->assertStringStartsWith("\xEF\xBB\xBFTime,", $csv);

        $busy = base64_decode(Livewire::test(CallReport::class)->set('status', 'BUSY')->call('export')->effects['download']['content']);
        $this->assertStringContainsString('8801722222222', $busy);
        $this->assertStringNotContainsString('8801711111111', $busy);

        $this->assertSame(2, AuditLog::where('action', 'report.exported')->where('actor_id', $a->id)->count());
    }

    public function test_date_filter_single_and_custom_range(): void
    {
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(3, $u), CampaignStatus::RUNNING, 3);
        $old = $this->attempt($c, '8801710000001', 'ANSWERED', 0);
        $mid = $this->attempt($c, '8801710000002', 'ANSWERED', 1);
        $new = $this->attempt($c, '8801710000003', 'ANSWERED', 2);
        $old->forceFill(['created_at' => '2026-09-01 10:00:00'])->save();
        $mid->forceFill(['created_at' => '2026-09-05 23:30:00'])->save();
        $new->forceFill(['created_at' => '2026-09-10 08:00:00'])->save();
        $this->actingAs($u);

        $phones = fn ($t) => $t->viewData('attempts')->pluck('phone')->all();
        $open = fn () => Livewire::withQueryParams(['dateMode' => 'range'])->test(CallReport::class); // a date in the URL skips the last-7-days default

        // Single date: only that calendar day (including late-evening rows).
        $t = $open()->set('dateMode', 'single')->set('date', '2026-09-05');
        $this->assertSame(['8801710000002'], $phones($t));

        // Custom range is inclusive on both ends.
        $t = $open()->set('dateMode', 'range')->set('from', '2026-09-05')->set('to', '2026-09-10');
        $this->assertEqualsCanonicalizing(['8801710000002', '8801710000003'], $phones($t));

        // From only / To only.
        $this->assertSame(['8801710000001'], $phones($open()->set('to', '2026-09-02')));
        $this->assertCount(3, $phones($open()->set('from', '2026-09-01')));

        // In single mode the range fields are ignored, and vice versa.
        $t = $open()->set('from', '2026-09-10')->set('to', '2026-09-10')->set('dateMode', 'single')->set('date', '2026-09-01');
        $this->assertSame(['8801710000001'], $phones($t));
        $t = $open()->set('date', '2026-09-01')->set('dateMode', 'range');
        $this->assertCount(3, $phones($t));

        // Garbage dates never break the query or widen anything: they are ignored.
        $t = $open()->set('dateMode', 'single')->set('date', "x' OR 1=1 --");
        $this->assertCount(3, $phones($t));

        // Unknown mode falls back to range.
        $t = $open()->set('dateMode', 'evil');
        $t->assertSet('dateMode', 'range');

        // Search applies the pending filters together; Reset clears them and goes back to the last 7 days.
        $t = $open()->set('status', 'NO_ANSWER')->set('phone', '88017')->set('from', '2026-09-10')->call('applyFilters');
        $this->assertSame([], $phones($t));
        $t->call('resetFilters')->assertSet('status', '')->assertSet('phone', '')->assertSet('from', now()->subDays(6)->toDateString())->assertSet('to', now()->toDateString())->assertSet('dateMode', 'range')->assertSet('campaignId', null);
        $this->assertSame([], $phones($t)); // the test calls are from September
        $t->set('from', '')->set('to', '');
        $this->assertCount(3, $phones($t)); // no dates = all time
    }

    public function test_report_opens_on_the_last_7_days_by_default(): void
    {
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(3, $u), CampaignStatus::RUNNING, 3);
        $recent = $this->attempt($c, '8801710000001', 'ANSWERED', 0);
        $edge = $this->attempt($c, '8801710000002', 'ANSWERED', 1);
        $old = $this->attempt($c, '8801710000003', 'ANSWERED', 2);
        $edge->forceFill(['created_at' => now()->subDays(6)->startOfDay()->addHour()])->save();
        $old->forceFill(['created_at' => now()->subDays(7)->endOfDay()])->save();
        $this->actingAs($u);

        $t = Livewire::test(CallReport::class)->assertSet('dateMode', 'range')->assertSet('from', now()->subDays(6)->toDateString())->assertSet('to', now()->toDateString());
        $this->assertEqualsCanonicalizing(['8801710000001', '8801710000002'], $t->viewData('attempts')->pluck('phone')->all());

        // A date in the URL wins over the default.
        $t = Livewire::withQueryParams(['from' => now()->subDays(10)->toDateString()])->test(CallReport::class);
        $this->assertCount(3, $t->viewData('attempts')->pluck('phone')->all());
    }

public function test_export_respects_single_date(): void
    {
        $u = $this->makeUser();
        $c = $this->makeCampaign($u, $this->makeDid(3, $u), CampaignStatus::RUNNING, 2);
        $a = $this->attempt($c, '8801711111111', 'ANSWERED', 0);
        $b = $this->attempt($c, '8801722222222', 'ANSWERED', 1);
        $a->forceFill(['created_at' => '2026-09-01 10:00:00'])->save();
        $b->forceFill(['created_at' => '2026-09-02 10:00:00'])->save();
        $this->actingAs($u);

        $csv = base64_decode(Livewire::test(CallReport::class)->set('dateMode', 'single')->set('date', '2026-09-02')->call('export')->effects['download']['content']);
        $this->assertStringContainsString('8801722222222', $csv);
        $this->assertStringNotContainsString('8801711111111', $csv);
    }
    public function test_csv_safe_prefixes_formula_cells(): void
    {
        foreach (['=1+1', '+1', '-1', '@x'] as $v) {
            $this->assertSame("'".$v, CallReport::csvSafe($v));
        }
        $this->assertSame('8801711111111', CallReport::csvSafe('8801711111111'));
        $this->assertSame('', CallReport::csvSafe(null));
    }

    public function test_report_says_which_period_it_is_showing(): void
    {
        $this->actingAs($this->makeUser());
        $d = fn (string $x) => \Carbon\Carbon::parse($x)->format('d M Y');

        Livewire::test(CallReport::class)->assertSee('Showing:')->assertSee($d(now()->subDays(6)->toDateString()).' to '.$d(now()->toDateString()).' (last 7 days)');
        Livewire::withQueryParams(['dateMode' => 'range'])->test(CallReport::class)->set('from', '')->set('to', '')->assertSee('All time');
        Livewire::withQueryParams(['dateMode' => 'single'])->test(CallReport::class)->set('dateMode', 'single')->set('date', now()->toDateString())->assertSee('Today, '.$d(now()->toDateString()));
        Livewire::test(CallReport::class)->set('dateMode', 'range')->set('from', '2026-09-01')->set('to', '2026-09-05')->assertSee('01 Sep 2026 to 05 Sep 2026')->assertDontSee('last 7 days');
    }
}
