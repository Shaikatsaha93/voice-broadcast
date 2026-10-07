<?php

namespace Tests\Support;

use App\Enums\CampaignStatus;
use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Did;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

trait MakesFixtures
{
    protected function seedRoles(): void
    {
        (new DatabaseSeeder)->run();
    }

    protected function makeUser(string $role = Role::USER, array $attrs = []): User
    {
        $this->seedRoles();
        $u = User::create($attrs + ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'password' => 'secret-password-123', 'status' => 'active']);
        $u->roles()->attach(Role::where('name', $role)->value('id'));

        return $u->load('roles');
    }

    protected function makeAdmin(): User
    {
        return $this->makeUser(Role::SUPER_ADMIN);
    }

    protected function makeDid(int $max = 5, ?User $user = null): Did
    {
        $d = Did::create(['number' => (string) fake()->unique()->numerify('8809########'), 'label' => 'L', 'status' => 'active', 'max_concurrent_calls' => $max]);
        $user && $d->users()->attach($user->id);

        return $d;
    }

    protected function makeCampaign(User $user, Did $did, CampaignStatus $status = CampaignStatus::RUNNING, int $recipients = 3, array $attrs = []): Campaign
    {
        $audio = AudioFile::create(['user_id' => $user->id, 'original_name' => 'a.wav', 'path' => 'audio/a.wav', 'mime' => 'audio/wav', 'size' => 10, 'status' => 'READY']);
        $c = Campaign::create($attrs + ['user_id' => $user->id, 'did_id' => $did->id, 'audio_file_id' => $audio->id, 'name' => 'C '.fake()->word(), 'status' => $status, 'max_attempts' => 3, 'requested_concurrency' => 5, 'retry_delay_seconds' => 60]);
        for ($i = 0; $i < $recipients; $i++) {
            CampaignRecipient::create(['campaign_id' => $c->id, 'phone' => '88017'.str_pad((string) (random_int(0, 99999999) + $i), 8, '0'), 'status' => 'PENDING']);
        }

        return $c->refresh();
    }
}
