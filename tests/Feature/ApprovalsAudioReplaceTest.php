<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\ProcessAudioFile;
use App\Models\Campaign;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class ApprovalsAudioReplaceTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    /** A tiny but real WAV (8 kHz, 16 bit mono, 0.1 s of silence), so the MIME check passes. */
    private function wav(string $name = 'new.wav'): UploadedFile
    {
        $data = str_repeat("\0\0", 800);
        $hdr = 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($data));

        return UploadedFile::fake()->createWithContent($name, $hdr.$data);
    }

    private function pending(string $audioStatus = 'READY'): Campaign
    {
        $owner = $this->makeUser();
        $c = $this->makeCampaign($owner, $this->makeDid(3, $owner), CampaignStatus::PENDING_APPROVAL, 1);
        $c->audio->update(['status' => $audioStatus, 'original_name' => 'old-message.wav']);

        return $c->fresh();
    }

    public function test_approver_sees_replace_and_download_and_approve_is_enabled_when_ready(): void
    {
        $c = $this->pending('READY');
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->get(route('admin.approvals'))->assertOk()
            ->assertSee('old-message.wav')
            ->assertSee('Replace audio')
            ->assertSee('Download original')
            ->assertSeeHtml('action="'.route('audio.replace', $c->audio).'"')
            ->assertDontSeeHtml('title="Wait until the audio is READY"');
    }

    public function test_replacing_from_the_approvals_page_reprocesses_the_audio_and_blocks_approval_until_ready(): void
    {
        Storage::fake();
        Queue::fake();
        $c = $this->pending('READY');
        $admin = $this->makeUser(Role::SYSTEM_ADMIN);

        $this->actingAs($admin)->from(route('admin.approvals'))
            ->post(route('audio.replace', $c->audio), ['audio' => $this->wav()])
            ->assertRedirect(route('admin.approvals'))->assertSessionHasNoErrors();

        $audio = $c->audio->fresh();
        $this->assertSame('UPLOADED', $audio->status);
        $this->assertSame('new.wav', $audio->original_name);
        Queue::assertPushed(ProcessAudioFile::class);

        // The page now says it is processing and the Approve button is disabled.
        $this->get(route('admin.approvals'))->assertOk()
            ->assertSee('UPLOADED')
            ->assertSeeHtml('title="Wait until the audio is READY"');

        // Even a hand-crafted POST cannot approve while the audio is not READY.
        $this->post(route('admin.approvals.approve', $c))->assertSessionHasErrors('audio');
        $this->assertSame(CampaignStatus::PENDING_APPROVAL, $c->fresh()->status);

        // Once processing finished, approving works again.
        $audio->update(['status' => 'READY']);
        $this->post(route('admin.approvals.approve', $c))->assertSessionHasNoErrors();
        $this->assertNotSame(CampaignStatus::PENDING_APPROVAL, $c->fresh()->status);
    }

    public function test_replace_is_refused_for_a_plain_admin_and_for_the_owner_after_submit(): void
    {
        Storage::fake();
        Queue::fake();
        $c = $this->pending('READY');

        $this->actingAs($this->makeUser(Role::ADMIN, ['email' => 'plain@example.com']))
            ->post(route('audio.replace', $c->audio), ['audio' => $this->wav()])->assertForbidden();

        $this->actingAs($c->user)->post(route('audio.replace', $c->audio), ['audio' => $this->wav()])->assertForbidden();

        $this->assertSame('READY', $c->audio->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_bad_file_type_is_rejected_and_the_audio_is_untouched(): void
    {
        Storage::fake();
        $c = $this->pending('READY');

        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN))
            ->post(route('audio.replace', $c->audio), ['audio' => UploadedFile::fake()->create('x.exe', 5, 'application/octet-stream')])
            ->assertSessionHasErrors('audio');
        $this->assertSame('old-message.wav', $c->audio->fresh()->original_name);
    }
}
