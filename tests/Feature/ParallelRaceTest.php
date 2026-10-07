<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\CallAttempt;
use App\Models\DidSlot;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

/** Real OS processes racing for the same DID: capacity must never be exceeded. */
class ParallelRaceTest extends TestCase
{
    use DatabaseMigrations, MakesFixtures;

    public function test_parallel_workers_never_exceed_did_capacity(): void
    {
        $u = $this->makeUser();
        $did = $this->makeDid(3, $u);
        $c = $this->makeCampaign($u, $did, CampaignStatus::RUNNING, 12, ['requested_concurrency' => 100, 'status' => CampaignStatus::RUNNING]);
        $c->update(['requested_concurrency' => 3]);

        $ids = [];
        foreach ($c->recipients as $i => $r) {
            $ids[] = CallAttempt::create(['call_ref' => (string) Str::uuid(), 'campaign_id' => $c->id, 'recipient_id' => $r->id, 'did_id' => $did->id, 'user_id' => $u->id, 'phone' => $r->phone, 'attempt_no' => 1, 'status' => 'QUEUED'])->id;
        }

        $procs = [];
        foreach ($ids as $id) {
            $code = '$a=App\Models\CallAttempt::find('.$id.'); echo app(App\Services\Did\DidSlotManager::class)->acquire($a) ? "OK" : "NO";';
            $p = new Process([PHP_BINARY, 'artisan', 'tinker', '--execute='.$code], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'broadcast_test', 'CACHE_STORE' => 'file']);
            $p->start();
            $procs[] = $p;
        }
        $granted = 0;
        foreach ($procs as $p) {
            $p->wait();
            $granted += substr_count($p->getOutput(), 'OK');
        }

        $this->assertSame(3, DidSlot::count());
        $this->assertSame(3, $granted);
    }
}
