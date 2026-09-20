<?php

namespace Tests\Feature;

use App\Jobs\SendInvoice;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BulkInvoiceEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['association.invoice_emails_enabled' => true]);
        Queue::fake();
    }

    private function preview(array $ids): string
    {
        $this->post('/admin/invoice-emails/preview', ['invoice_ids' => $ids])->assertOk();

        return session('invoice_email_draft.key');
    }

    public function test_bulk_send_reaches_each_active_unit_contact_once_and_replays_do_not_duplicate(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $contact = User::factory()->create();
        User::factory()->create(['household_id' => $contact->household_id]);
        $disabled = User::factory()->create(['household_id' => $contact->household_id, 'active' => false]);
        $first = Invoice::factory()->create(['household_id' => $contact->household_id]);
        $second = Invoice::factory()->create(['household_id' => $contact->household_id]);
        $this->actingAs($admin)->get('/invoices')->assertOk()->assertSee('Review email recipients');
        $key = $this->preview([$first->id, $second->id]);
        $this->assertDatabaseCount('invoice_deliveries', 0);
        Queue::assertNothingPushed();
        $this->post('/admin/invoice-emails/send', ['request_key' => $key])->assertRedirect('/invoices');
        $this->assertDatabaseCount('invoice_deliveries', 4);
        $this->assertDatabaseMissing('invoice_deliveries', ['user_id' => $disabled->id]);
        Queue::assertPushed(SendInvoice::class, 4);
        $this->post('/admin/invoice-emails/send', ['request_key' => $key])->assertSessionHasErrors();
        $this->assertDatabaseCount('invoice_deliveries', 4);
    }

    public function test_a_confirmed_resend_keeps_original_delivery_history_but_pending_and_uncertain_sends_are_skipped(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $contact = User::factory()->create();
        $invoice = Invoice::factory()->create(['household_id' => $contact->household_id]);
        DB::table('invoice_deliveries')->insert(['invoice_id' => $invoice->id, 'user_id' => $contact->id, 'status' => 'sent', 'sent_at' => now()]);
        $this->actingAs($admin);
        $key = $this->preview([$invoice->id]);
        $this->post('/admin/invoice-emails/send', ['request_key' => $key])->assertRedirect('/invoices');
        $this->assertDatabaseCount('invoice_deliveries', 2);
        $key = $this->preview([$invoice->id]);
        $this->post('/admin/invoice-emails/send', ['request_key' => $key])->assertRedirect('/invoices');
        $this->assertDatabaseCount('invoice_deliveries', 2);
        DB::table('invoice_deliveries')->where('status', 'pending')->update(['status' => 'review_required']);
        $key = $this->preview([$invoice->id]);
        $this->post('/admin/invoice-emails/send', ['request_key' => $key])->assertRedirect('/invoices');
        $this->assertDatabaseCount('invoice_deliveries', 2);
    }

    public function test_residents_and_impersonation_cannot_send_email(): void
    {
        $resident = User::factory()->create();
        $this->actingAs($resident)->get('/invoices')->assertDontSee('Review email recipients');
        $this->post('/admin/invoice-emails/preview')->assertForbidden();
        $this->post('/admin/invoice-emails/send')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post('/admin/impersonate/'.$resident->id);
        $this->post('/admin/invoice-emails/send')->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_void_former_owner_and_missing_contacts_block_the_whole_selection(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $contact = User::factory()->create();
        $valid = Invoice::factory()->create(['household_id' => $contact->household_id]);
        $void = Invoice::factory()->create(['household_id' => $contact->household_id, 'void_reason' => 'Cancelled']);
        $missing = Invoice::factory()->create();
        foreach ([$void, $missing] as $invalid) {
            $this->post('/admin/invoice-emails/preview', ['invoice_ids' => [$valid->id, $invalid->id]])->assertSessionHasErrors('invoices');
        }
        $contact->household->update(['active' => false]);
        $this->post('/admin/invoice-emails/preview', ['invoice_ids' => [$valid->id]])->assertSessionHasErrors('invoices');
        $this->assertDatabaseCount('invoice_deliveries', 0);
    }

    public function test_recipient_changes_require_a_new_preview(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $contact = User::factory()->create();
        $invoice = Invoice::factory()->create(['household_id' => $contact->household_id]);
        $key = $this->preview([$invoice->id]);
        $contact->update(['email' => 'changed@example.test']);
        $this->post('/admin/invoice-emails/send', ['request_key' => $key])->assertSessionHasErrors('invoices');
        $this->assertDatabaseCount('invoice_deliveries', 0);
    }

    public function test_disabled_delivery_prevents_manual_send(): void
    {
        config(['association.invoice_emails_enabled' => false]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $invoice = Invoice::factory()->create();
        $this->post('/admin/invoice-emails/preview', ['invoice_ids' => [$invoice->id]])->assertSessionHasErrors('invoices');
    }

    public function test_recurring_dues_automatically_queue_and_send_to_both_contacts_once(): void
    {
        $contact = User::factory()->create();
        $second = User::factory()->create(['household_id' => $contact->household_id]);
        DB::table('dues_rates')->insert(['unit_id' => $contact->household->unit_id, 'effective_on' => '2026-09-01', 'amount_cents' => 43000]);
        config(['association.billing_enabled' => true, 'association.billing_start_month' => '2026-10', 'association.billing_issue_day' => 1, 'association.billing_due_days' => 14, 'mail.default' => 'resend', 'services.resend.key' => 'test']);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00:00', 'America/Chicago'));
        $this->artisan('association:bill')->assertSuccessful();
        $this->artisan('association:bill')->assertSuccessful();
        Queue::assertPushed(SendInvoice::class, 2);
        $this->assertDatabaseCount('invoice_deliveries', 2);
        $this->assertSame('2026-10-15', Invoice::first()->due_on->toDateString());
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'sent'])]);
        foreach (DB::table('invoice_deliveries')->get() as $delivery) {
            (new SendInvoice($delivery->id))->handle();
            (new SendInvoice($delivery->id))->handle();
        }
        Http::assertSentCount(2);
        $recipients = Http::recorded()->map(fn ($pair) => $pair[0]->data()['to'][0])->all();
        $this->assertEqualsCanonicalizing([$contact->email, $second->email], $recipients);
        $html = Http::recorded()[0][0]->data()['html'];
        $this->assertStringContainsString('1262brynmawr@gmail.com', $html);
        $this->assertStringContainsString('1262 W Bryn Mawr #3, Chicago IL 60660', $html);
        $this->assertStringContainsString('October 15, 2026', $html);
    }

    public function test_paid_email_is_a_receipt_and_escapes_item_text(): void
    {
        $contact = User::factory()->create();
        $invoice = Invoice::factory()->create(['household_id' => $contact->household_id, 'historical_paid_cents' => 43000, 'items' => [['name' => '<script>bad()</script>', 'amount_cents' => 43000]]]);
        $html = view('mail.invoice', ['invoice' => $invoice, 'resident' => $contact])->render();
        $this->assertStringContainsString('No payment needed', $html);
        $this->assertStringNotContainsString('<script>bad()', $html);
        $this->assertStringContainsString('$0.00', $html);
    }
}
