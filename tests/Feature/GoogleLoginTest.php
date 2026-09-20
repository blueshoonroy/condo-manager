<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function google(string $email, bool $verified = true, ?string $domain = null, string $id = 'google-123'): void
    {
        $google = (new GoogleUser)->setRaw(['email_verified' => $verified, 'hd' => $domain])->map(['id' => $id, 'email' => $email]);
        Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
        Socialite::shouldReceive('user')->once()->andReturn($google);
    }

    private function flow(?int $linkUser = null): array
    {
        return ['google_login' => ['expires' => now()->addMinutes(10)->timestamp, 'link_user' => $linkUser]];
    }

    public function test_registered_verified_gmail_resident_can_sign_in_and_link(): void
    {
        $user = User::factory()->create(['email' => 'resident@gmail.com']);
        $this->google($user->email);
        $this->withSession($this->flow())->get('/auth/google/callback?code=test')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-123', $user->fresh()->google_id);
    }

    public function test_workspace_email_can_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'resident@example.com']);
        $this->google($user->email, domain: 'example.com');
        $this->withSession($this->flow())->get('/auth/google/callback?code=test')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_email_cannot_create_an_account(): void
    {
        $this->google('outsider@gmail.com');
        $this->withSession($this->flow())->get('/auth/google/callback?code=test')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'resident@gmail.com']);
        $this->google($user->email, false);
        $this->withSession($this->flow())->get('/auth/google/callback?code=test')->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_disabled_household_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'resident@gmail.com']);
        $user->household->update(['active' => false]);
        $this->google($user->email);
        $this->withSession($this->flow())->get('/auth/google/callback?code=test')->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_google_subject_cannot_change_after_linking(): void
    {
        $user = User::factory()->create(['email' => 'resident@gmail.com']);
        $user->forceFill(['google_id' => 'original-google-id'])->save();
        $this->google($user->email);
        $this->withSession($this->flow())->get('/auth/google/callback?code=test')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertSame('original-google-id', $user->fresh()->google_id);
    }

    public function test_third_party_email_requires_an_authenticated_link_first(): void
    {
        $user = User::factory()->create(['email' => 'resident@example.com']);
        $this->google($user->email);
        $this->withSession($this->flow())->get('/auth/google/callback?code=test')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_authenticated_resident_can_link_their_third_party_email(): void
    {
        $user = User::factory()->create(['email' => 'resident@example.com']);
        $this->google($user->email);
        $this->actingAs($user)->withSession($this->flow($user->id))->get('/auth/google/callback?code=test')->assertRedirect('/');
        $this->assertSame('google-123', $user->fresh()->google_id);
    }

    public function test_link_cannot_switch_to_another_resident(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['email' => 'other@gmail.com']);
        $this->google($other->email);
        $this->actingAs($user)->withSession($this->flow($user->id))->get('/auth/google/callback?code=test')->assertSessionHasErrors('google');
        $this->assertNull($other->fresh()->google_id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_real_socialite_redirect_uses_state_and_rejects_mismatched_callback(): void
    {
        config(['services.google' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'redirect' => 'https://1262bryn.com/auth/google/callback']]);
        $response = $this->get('/auth/google');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('https://1262bryn.com/auth/google/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
        $this->get('/auth/google/callback?code=test&state=wrong')->assertSessionHasErrors('google');
        $this->assertGuest();
    }
}
