<?php

namespace Tests\Feature;

use App\Models\Did;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class DidSyncTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    public function test_admin_creates_did_from_asterisk_trunk_in_dry_run(): void
    {
        config(['broadcast.asterisk.dry_run' => true]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('admin.dids.sync'))->assertOk()->assertSee('backup-trunk');

        $this->actingAs($admin)->post(route('admin.dids.sync.import'), [
            'trunk' => 'trunk', 'number' => '8801700000000', 'label' => 'Main', 'max_concurrent_calls' => 5,
        ])->assertSessionHasNoErrors();

        $did = Did::firstWhere('number', '8801700000000');
        $this->assertSame('PJSIP/{number}@trunk', $did->trunk);
        $this->assertSame(5, $did->max_concurrent_calls);
    }

    public function test_unknown_trunk_is_rejected(): void
    {
        config(['broadcast.asterisk.dry_run' => true]);
        $this->actingAs($this->makeAdmin())->post(route('admin.dids.sync.import'), [
            'trunk' => 'nope', 'number' => '8801700000001', 'max_concurrent_calls' => 1,
        ])->assertSessionHasErrors('trunk');
        $this->assertSame(0, Did::count());
    }

    public function test_normal_user_cannot_open_sync(): void
    {
        $this->actingAs($this->makeUser())->get(route('admin.dids.sync'))->assertForbidden();
    }
}
