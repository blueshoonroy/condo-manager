<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_resident_invoices_and_can_return_without_changing_session_owner(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->create(['is_admin' => true]);
        $resident = User::factory()->create();
        $own = Invoice::factory()->create(['household_id' => $resident->household_id]);
        $other = Invoice::factory()->create();
        $this->actingAs($admin)->withSession([Auth::guard()->getName() => $admin->id])->post('/admin/impersonate/'.$resident->id)->assertRedirect('/');
        $this->get('/invoices')->assertOk()->assertSee($own->number)->assertDontSee($other->number)->assertSee('Viewing as '.$resident->name)->assertSee('Return to my account')->assertDontSee('>Administration</a>', false);
        $this->get('/invoices/'.$other->id)->assertNotFound();
        $this->get('/admin')->assertForbidden();
        $this->assertAuthenticatedAs($admin);
        $this->assertSame($admin->id, session(Auth::guard()->getName()));
        $this->assertDatabaseMissing('sessions', ['user_id' => $resident->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $admin->id]);
        $this->post('/impersonation/stop')->assertRedirect('/admin?tab=residents')->assertSessionMissing('impersonated_user_id');
        $this->get('/admin?tab=residents')->assertOk()->assertDontSee('Read-only resident preview');
        $this->assertDatabaseHas('audit_events', ['action' => 'impersonation.started', 'user_id' => $admin->id, 'subject' => 'user:'.$resident->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'impersonation.ended', 'user_id' => $admin->id]);
    }

    public function test_residents_and_guests_cannot_start_or_restore_admin_access(): void
    {
        $resident = User::factory()->create();
        $this->post('/admin/impersonate/'.$resident->id)->assertRedirect('/login');
        $this->actingAs($resident)->post('/admin/impersonate/'.$resident->id)->assertForbidden();
        $this->post('/impersonation/stop')->assertForbidden();
        $this->withSession(['impersonated_user_id' => $resident->id])->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_preview_blocks_changes_google_linking_and_nested_impersonation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $resident = User::factory()->create();
        $this->actingAs($admin)->post('/admin/impersonate/'.$resident->id);
        $this->post('/admin/residents/'.$resident->id, ['name' => 'Changed'])->assertForbidden();
        $this->post('/admin/impersonate/'.$resident->id)->assertForbidden();
        $this->get('/auth/google/link')->assertForbidden();
        $this->get('/auth/google/callback?code=ignored')->assertForbidden();
        $this->assertNotSame('Changed', $resident->fresh()->name);
        foreach (['/', '/finances', '/directory', '/documents', '/services'] as $url) {
            $this->get($url)->assertOk()->assertSee('Read-only resident preview');
        }
    }

    public function test_inactive_former_and_admin_accounts_cannot_be_impersonated(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $disabled = User::factory()->create(['active' => false]);
        $former = User::factory()->create();
        $former->household->update(['active' => false]);
        $this->actingAs($admin);
        foreach ([$admin, $disabled, $former] as $user) {
            $this->post('/admin/impersonate/'.$user->id)->assertStatus(422);
        }
        $this->assertNull(session('impersonated_user_id'));
    }

    public function test_disabling_target_ends_preview_and_disabled_admin_cannot_recover_access(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $resident = User::factory()->create();
        $this->actingAs($admin)->post('/admin/impersonate/'.$resident->id);
        $resident->update(['active' => false]);
        $this->get('/')->assertRedirect('/admin?tab=residents')->assertSessionMissing('impersonated_user_id');
        $resident->update(['active' => true]);
        $this->post('/admin/impersonate/'.$resident->id);
        $admin->update(['active' => false]);
        $this->post('/impersonation/stop')->assertRedirect('/login')->assertSessionMissing('impersonated_user_id');
        $this->assertGuest();
    }

    public function test_logout_during_preview_ends_both_sessions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $resident = User::factory()->create();
        $this->actingAs($admin)->post('/admin/impersonate/'.$resident->id);
        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('impersonated_user_id');
        $this->assertGuest();
        $this->post('/impersonation/stop')->assertRedirect('/login');
    }
}
