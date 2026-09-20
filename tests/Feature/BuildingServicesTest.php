<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuildingServicesTest extends TestCase
{
    use RefreshDatabase;

    private function details(): array
    {
        return ['name' => 'Example Utility', 'category' => 'Utilities', 'username' => 'shared-login', 'service_password' => '  Exact secret!  ', 'account' => 'account-123', 'notes' => 'Recovery answer: Example', 'website' => 'https://example.com'];
    }

    public function test_shared_details_are_encrypted_and_residents_can_read_them_without_editing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/admin/services', $this->details())->assertRedirect('/admin/services');
        $record = DB::table('building_services')->first();
        $this->assertStringNotContainsString('Exact secret', $record->details);
        $details = json_decode(Crypt::decryptString($record->details), true);
        $this->assertSame('  Exact secret!  ', $details['password']);
        $this->assertSame('shared-login', $details['username']);
        $this->assertStringNotContainsString('Exact secret', DB::table('audit_events')->first()->details);
        $resident = User::factory()->create();
        $response = $this->actingAs($resident)->get('/services')->assertOk()->assertSee('Example Utility')->assertSee('shared-login')->assertSee('Exact secret!')->assertSee('Show password');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/admin/services')->assertForbidden();
        $this->get('/admin/services/'.$record->id.'/edit')->assertForbidden();
        $this->post('/admin/services', $this->details())->assertForbidden();
        $this->post('/admin/services/'.$record->id, $this->details())->assertForbidden();
        $this->delete('/admin/services/'.$record->id)->assertForbidden();
    }

    public function test_guests_and_disabled_residents_cannot_view_credentials(): void
    {
        $this->get('/services')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['active' => false]))->get('/services')->assertRedirect('/login');
        $resident = User::factory()->create();
        $resident->household->update(['active' => false]);
        $this->actingAs($resident)->get('/services')->assertRedirect('/login');
    }

    public function test_admin_can_update_preserve_clear_and_delete_a_password(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post('/admin/services', $this->details());
        $id = DB::table('building_services')->value('id');
        $this->get('/admin/services/'.$id.'/edit')->assertOk()->assertDontSee('Exact secret!');
        $data = array_replace($this->details(), ['service_password' => '', 'name' => 'Renamed Utility']);
        $this->post('/admin/services/'.$id, $data)->assertRedirect('/admin/services');
        $this->get('/services')->assertSee('Renamed Utility')->assertSee('Exact secret!');
        $this->post('/admin/services/'.$id, array_replace($data, ['service_password' => 'replacement']))->assertRedirect('/admin/services');
        $this->get('/services')->assertSee('replacement')->assertDontSee('Exact secret!');
        $this->post('/admin/services/'.$id, $data + ['clear_password' => '1'])->assertRedirect('/admin/services');
        $this->get('/services')->assertDontSee('Show password');
        $this->delete('/admin/services/'.$id)->assertRedirect('/admin/services');
        $this->assertDatabaseCount('building_services', 0);
    }

    public function test_validation_does_not_flash_credentials_or_allow_script_urls(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->from('/admin/services/new')->post('/admin/services', array_replace($this->details(), ['website' => 'javascript:alert(1)']))->assertSessionHasErrors('website');
        foreach (['service_password', 'username', 'account', 'notes'] as $field) {
            $this->assertArrayNotHasKey($field, session('_old_input', []));
        }
        $this->assertDatabaseCount('building_services', 0);
    }

    public function test_resident_page_escapes_imported_text(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/services', array_replace($this->details(), ['notes' => '<script>alert(1)</script>']));
        $this->get('/services')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_settings_tabs_show_only_the_selected_section_and_keep_related_pages_linked(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin')->assertOk()->assertSee('Record a payment')->assertDontSee('Create a special assessment');
        $this->get('/admin?tab=billing')->assertOk()->assertSee('Create a special assessment')->assertSee('Dues rates')->assertDontSee('Record a payment');
        $this->get('/admin?tab=residents')->assertOk()->assertSee('Resident access')->assertDontSee('Create a special assessment');
        $this->get('/admin?tab=imports')->assertOk()->assertSee('Import a CSV')->assertSee('Import history');
        $this->get('/admin?tab=activity')->assertOk()->assertSee('Activity log');
        foreach (['/admin/services', '/admin/services/new', '/admin/bank/connect', '/admin/reconciliation'] as $url) {
            $this->get($url)->assertOk()->assertSee('Administration sections');
        }
        $this->get('/admin?tab=invalid')->assertNotFound();
    }

    public function test_unknown_service_cannot_be_updated_or_deleted(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/services/999', $this->details())->assertNotFound();
        $this->delete('/admin/services/999')->assertNotFound();
    }
}
