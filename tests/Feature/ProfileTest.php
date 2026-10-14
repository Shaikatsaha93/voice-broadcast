<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MakesFixtures;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use MakesFixtures, RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('profile'))->assertRedirect(route('login'));
    }

    public function test_every_role_can_open_their_own_profile(): void
    {
        foreach ([Role::SYSTEM_ADMIN, Role::SUPER_ADMIN, Role::ADMIN, Role::USER] as $role) {
            $u = $this->makeUser($role);
            $u->update(['company_name' => 'Acme Ltd']);
            $this->actingAs($u)->get(route('profile'))->assertOk()->assertSee($u->email)->assertSee('Acme Ltd')->assertSee('Change password');
        }
    }

    public function test_password_can_be_changed_with_the_current_password(): void
    {
        $u = $this->makeUser(Role::USER);
        $u->update(['password' => 'old-password-123']);

        $this->actingAs($u)->put(route('profile.password'), ['current_password' => 'old-password-123', 'password' => 'brand-new-pass-456', 'password_confirmation' => 'brand-new-pass-456'])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile'));

        $this->assertTrue(Hash::check('brand-new-pass-456', $u->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_changed']);
    }

    public function test_wrong_current_password_weak_short_or_unconfirmed_new_password_is_rejected(): void
    {
        $u = $this->makeUser(Role::USER);
        $u->update(['password' => 'old-password-123']);
        $hash = $u->fresh()->password;
        $this->actingAs($u);

        $this->put(route('profile.password'), ['current_password' => 'nope', 'password' => 'brand-new-pass-456', 'password_confirmation' => 'brand-new-pass-456'])->assertSessionHasErrors('current_password');
        $this->put(route('profile.password'), ['current_password' => 'old-password-123', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->put(route('profile.password'), ['current_password' => 'old-password-123', 'password' => 'brand-new-pass-456', 'password_confirmation' => 'different-pass-789'])->assertSessionHasErrors('password');
        $this->put(route('profile.password'), ['current_password' => 'old-password-123', 'password' => 'old-password-123', 'password_confirmation' => 'old-password-123'])->assertSessionHasErrors('password');

        $this->assertSame($hash, $u->fresh()->password);
    }
}
