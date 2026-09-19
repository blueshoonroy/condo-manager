<?php

namespace Tests\Feature;

use App\Mail\LoginCode;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_private_pages(): void
    {
        foreach (['/', '/invoices', '/finances', '/directory', '/documents', '/admin'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_residents_can_view_household_invoices_but_not_other_households(): void
    {
        $resident = User::factory()->create();
        $own = Invoice::factory()->create(['household_id' => $resident->household_id]);
        $other = Invoice::factory()->create();
        $this->actingAs($resident)->get('/invoices')->assertOk()->assertSee($own->number)->assertDontSee($other->number);
        $this->get('/invoices/'.$other->id)->assertNotFound();
        $this->get('/invoices/'.$own->id)->assertOk();
        $this->get('/admin')->assertForbidden();
        $this->post('/admin/balance', [])->assertForbidden();
    }

    public function test_former_owner_invoices_are_not_visible_to_current_unit_residents(): void
    {
        $resident = User::factory()->create();
        $former = Household::factory()->create(['unit_id' => $resident->household->unit_id, 'active' => false]);
        $invoice = Invoice::factory()->create(['household_id' => $former->id]);
        $this->actingAs($resident)->get('/invoices/'.$invoice->id)->assertNotFound();
    }

    public function test_administrator_can_view_all_portal_screens(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create();
        foreach (['/', '/invoices', '/invoices/'.$invoice->id, '/finances', '/directory', '/documents', '/admin', '/admin/payments/new/'.$admin->household_id] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    public function test_email_code_is_single_use_and_unknown_email_gets_same_response(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email])->assertRedirect('/login/verify');
        $code = Mail::sent(LoginCode::class)->first()->code;
        $challenge = session('login_challenge');
        $this->post('/login/verify', ['code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        $this->withSession(['login_challenge' => $challenge])->post('/login/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/login', ['email' => 'unknown@example.test'])->assertRedirect('/login/verify');
        Mail::assertSentCount(1);
    }

    public function test_expired_codes_and_disabled_residents_cannot_sign_in(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email]);
        $code = Mail::sent(LoginCode::class)->first()->code;
        $this->travel(11)->minutes();
        $this->post('/login/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
        $user->update(['active' => false]);
        $this->actingAs($user)->get('/')->assertRedirect('/login');
    }

    public function test_five_wrong_attempts_exhaust_a_code(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email]);
        $code = Mail::sent(LoginCode::class)->first()->code;
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post('/login/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }
}
