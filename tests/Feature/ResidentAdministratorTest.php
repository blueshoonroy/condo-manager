<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidentAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_promotes_current_residents_and_preserves_disabled_and_former_access(): void
    {
        $resident = User::factory()->create();
        $disabled = User::factory()->create(['active' => false]);
        $former = User::factory()->create();
        $former->household->update(['active' => false]);
        $migration = require database_path('migrations/2026_09_23_111701_grant_administrator_access_to_active_residents.php');
        $migration->up();
        $this->assertTrue($resident->fresh()->is_admin);
        $this->assertFalse($disabled->fresh()->is_admin);
        $this->assertFalse($former->fresh()->is_admin);
        $this->assertDatabaseHas('audit_events', ['action' => 'resident.administrator_granted', 'subject' => 'user:'.$resident->id]);
        $invoice = Invoice::factory()->create();
        $this->actingAs($resident->fresh())->get('/invoices/'.$invoice->id)->assertOk();
        foreach (['/invoices', '/finances', '/admin', '/admin/bank/connect', '/admin/services', '/admin/reconciliation'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->actingAs($disabled)->get('/admin')->assertRedirect('/login');
        $this->actingAs($former)->get('/admin')->assertRedirect('/login');
    }

    public function test_new_and_reactivated_residents_receive_administrator_access(): void
    {
        $attributes = User::factory()->raw();
        unset($attributes['is_admin']);
        $admin = User::create($attributes);
        $this->assertTrue($admin->is_admin);
        $resident = User::factory()->create(['active' => false]);
        $this->actingAs($admin)->post('/admin/residents/'.$resident->id, ['name' => $resident->name, 'email' => $resident->email, 'active' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($resident->fresh()->is_admin);
        $this->assertTrue($resident->fresh()->active);
        $this->post('/admin/residents/'.$resident->id, ['name' => $resident->name, 'email' => $resident->email, 'active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($resident->fresh()->active);
        $this->post('/admin/residents/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'active' => 0])->assertSessionHasErrors('active');
    }

    public function test_previewing_another_administrator_remains_read_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/admin/impersonate/'.$other->id)->assertRedirect('/');
        $this->get('/admin')->assertOk()->assertSee('Viewing as '.$other->name);
        $this->post('/admin/residents/'.$other->id, ['active' => 0])->assertForbidden();
        $this->post('/admin/reconciliation/settings', ['provider' => 'openai'])->assertForbidden();
        $this->post('/impersonation/stop')->assertRedirect('/admin?tab=residents');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_roster_grants_all_current_residents_admin_access_even_with_old_role_flags(): void
    {
        config(['association.initial_roster_base64' => base64_encode(json_encode([
            ['unit' => 1, 'dues_cents' => 31000, 'client_name' => 'Current household', 'residents' => [
                ['name' => 'Current Resident', 'email' => 'current@example.com', 'is_admin' => false],
            ]],
            ['unit' => 1, 'dues_cents' => 31000, 'client_name' => 'Former household', 'active' => false, 'residents' => [
                ['name' => 'Former Resident', 'email' => 'former@example.com'],
            ]],
        ]))]);
        $this->artisan('association:initialize --from-env')->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'current@example.com', 'is_admin' => true, 'active' => true]);
        $this->assertDatabaseHas('users', ['email' => 'former@example.com', 'is_admin' => false, 'active' => false]);
    }
}
