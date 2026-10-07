<?php

namespace Tests\Feature;

use App\Jobs\ProcessNumberImport;
use App\Models\Campaign;
use App\Models\NumberImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class CampaignCreateWithNumbersTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    private function payload(int $didId, array $extra = []): array
    {
        return ['name' => 'Promo', 'did_id' => $didId, 'max_attempts' => 2, 'requested_concurrency' => 1, 'retry_delay_seconds' => 300] + $extra;
    }

    public function test_numbers_file_on_create_queues_an_import(): void
    {
        Storage::fake();
        Bus::fake([ProcessNumberImport::class]);
        $user = $this->makeUser();
        $did = $this->makeDid(3, $user);

        $this->actingAs($user)->post(route('campaigns.store'), $this->payload($did->id, [
            'numbers' => UploadedFile::fake()->createWithContent('n.csv', "8801700000001\n8801700000002\n", 'text/csv'),
        ]))->assertSessionHasNoErrors();

        $campaign = Campaign::firstWhere('name', 'Promo');
        $this->assertNotNull($campaign);
        $this->assertSame(1, NumberImport::where('campaign_id', $campaign->id)->count());
        Bus::assertDispatched(ProcessNumberImport::class);
    }

    public function test_sample_csv_downloads_and_imports_nothing_by_mistake(): void
    {
        Storage::fake();
        $user = $this->makeUser();
        $did = $this->makeDid(3, $user);

        $res = $this->actingAs($user)->get(route('campaigns.sample'));
        $res->assertOk()->assertDownload('campaign-numbers-sample.csv');
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("phone\r\n", $csv);

        // Uploading the untouched sample must not create any recipient (placeholders are invalid).
        $campaign = $this->makeCampaign($user, $did, \App\Enums\CampaignStatus::DRAFT, 0);
        Storage::disk('local')->put('imports/s.csv', $csv);
        $import = NumberImport::create(['campaign_id' => $campaign->id, 'user_id' => $user->id, 'path' => 'imports/s.csv', 'status' => 'PENDING']);
        (new ProcessNumberImport($import->id))->handle();

        $import->refresh();
        $this->assertSame('DONE', $import->status);
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(0, $import->imported_rows);
    }

    public function test_header_row_is_skipped_and_real_numbers_import(): void
    {
        Storage::fake();
        $user = $this->makeUser();
        $campaign = $this->makeCampaign($user, $this->makeDid(3, $user), \App\Enums\CampaignStatus::DRAFT, 0);
        Storage::disk('local')->put('imports/r.csv', "phone\n8801712345678\n01812345678\n");
        $import = NumberImport::create(['campaign_id' => $campaign->id, 'user_id' => $user->id, 'path' => 'imports/r.csv', 'status' => 'PENDING']);
        (new ProcessNumberImport($import->id))->handle();

        $import->refresh();
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, $import->imported_rows);
    }

    public function test_create_without_numbers_still_works(): void
    {
        $user = $this->makeUser();
        $did = $this->makeDid(3, $user);

        $this->actingAs($user)->post(route('campaigns.store'), $this->payload($did->id))->assertSessionHasNoErrors();
        $this->assertSame(0, NumberImport::count());
    }

    public function test_invalid_numbers_file_type_is_rejected(): void
    {
        $user = $this->makeUser();
        $did = $this->makeDid(3, $user);

        $this->actingAs($user)->post(route('campaigns.store'), $this->payload($did->id, [
            'numbers' => UploadedFile::fake()->create('n.exe', 5, 'application/octet-stream'),
        ]))->assertSessionHasErrors('numbers');
        $this->assertSame(0, Campaign::count());
    }
}
