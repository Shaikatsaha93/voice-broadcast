<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class UserCompanyNameTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return $extra + [
            'name' => 'Rahim Uddin', 'email' => 'rahim@acme.test', 'password' => 'a-very-long-pass-1',
            'role' => 'user', 'status' => 'active', 'valid_until' => now()->addMonth()->toDateString(),
        ];
    }

    public function test_company_name_is_saved_on_create_and_shown_in_the_list_and_on_the_user_page(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->get(route('admin.users.create'))->assertOk()->assertSee('Company name')->assertSee('name="company_name"', false);

        $this->post(route('admin.users.store'), $this->payload(['company_name' => 'Acme Telecom Ltd']))->assertSessionHasNoErrors();

        $user = User::firstWhere('email', 'rahim@acme.test');
        $this->assertSame('Acme Telecom Ltd', $user->company_name);
        $this->get(route('admin.users.index'))->assertOk()->assertSee('Company')->assertSee('Acme Telecom Ltd');
        $this->get(route('admin.users.show', $user))->assertOk()->assertSee('Acme Telecom Ltd');
        $this->get(route('admin.users.edit', $user))->assertOk()->assertSee('value="Acme Telecom Ltd"', false);
    }

    public function test_company_name_is_optional_and_can_be_changed_or_cleared(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->post(route('admin.users.store'), $this->payload())->assertSessionHasNoErrors();
        $user = User::firstWhere('email', 'rahim@acme.test');
        $this->assertNull($user->company_name);

        $this->put(route('admin.users.update', $user), $this->payload(['company_name' => '  New Co  ']))->assertSessionHasNoErrors();
        $this->assertSame('New Co', $user->fresh()->company_name); // trimmed

        $this->put(route('admin.users.update', $user), $this->payload(['company_name' => '']))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->company_name);
    }

    public function test_company_name_is_limited_to_150_characters(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));

        $this->post(route('admin.users.store'), $this->payload(['company_name' => str_repeat('a', 151)]))->assertSessionHasErrors('company_name');
        $this->assertNull(User::firstWhere('email', 'rahim@acme.test'));

        $this->post(route('admin.users.store'), $this->payload(['company_name' => str_repeat('a', 150)]))->assertSessionHasNoErrors();
    }

    public function test_company_name_is_escaped_on_every_page(): void
    {
        $this->actingAs($this->makeUser(Role::SYSTEM_ADMIN));
        $this->post(route('admin.users.store'), $this->payload(['company_name' => '<script>alert(1)</script>']))->assertSessionHasNoErrors();
        $user = User::firstWhere('email', 'rahim@acme.test');

        foreach ([route('admin.users.index'), route('admin.users.show', $user), route('admin.users.edit', $user)] as $url) {
            $this->get($url)->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        }
    }

    public function test_an_admin_can_also_set_it_for_the_users_they_create(): void
    {
        $admin = $this->makeUser(Role::ADMIN, ['email' => 'plain-admin@example.com']);
        $admin->forceFill(['rate_per_pulse' => 1, 'pulse_seconds' => 60, 'balance' => 100])->save();
        $this->actingAs($admin);

        $this->post(route('admin.users.store'), $this->payload(['company_name' => 'Team Co']))->assertSessionHasNoErrors();
        $this->assertSame('Team Co', User::firstWhere('email', 'rahim@acme.test')->company_name);
    }
}
