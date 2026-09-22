<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarkInvoicePaidTest extends TestCase
{
    use RefreshDatabase;

    private function payment(int $balance = 43000): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_balance' => $balance, 'paid_on' => now('America/Chicago')->toDateString(), 'payment_method' => 'zelle', 'note' => 'Confirmed Zelle receipt'];
    }

    public function test_admin_marks_remaining_balance_paid_with_method_and_date_and_can_reverse_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create(['historical_paid_cents' => 10000, 'source' => 'freshbooks']);
        $data = $this->payment(33000);
        $this->actingAs($admin)->get('/invoices/'.$invoice->id)->assertOk()->assertSee('Mark this invoice as paid')->assertSee('Payment method');
        $this->post('/admin/invoices/'.$invoice->id.'/mark-paid', $data)->assertRedirect('/invoices/'.$invoice->id);
        $this->assertSame('paid', $invoice->fresh()->status());
        $this->assertDatabaseHas('payments', ['household_id' => $invoice->household_id, 'amount_cents' => 33000, 'payment_method' => 'zelle', 'paid_on' => $data['paid_on'], 'user_id' => $admin->id, 'bank_transaction_id' => null]);
        $this->assertDatabaseCount('bank_transactions', 0);
        $this->get('/invoices/'.$invoice->id)->assertSee('Zelle')->assertDontSee('Mark this invoice as paid');
        $this->get('/admin?tab=payments')->assertSee('Zelle');
        $this->assertDatabaseHas('audit_events', ['action' => 'payment.recorded', 'user_id' => $admin->id]);
        app(PaymentService::class)->reverse(DB::table('payments')->value('id'), 'Recorded in error');
        $this->assertSame(33000, $invoice->fresh()->balanceCents());
    }

    public function test_repeated_submit_does_not_create_a_second_payment(): void
    {
        $invoice = Invoice::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $data = $this->payment();
        $url = '/admin/invoices/'.$invoice->id.'/mark-paid';
        $this->post($url, $data)->assertRedirect();
        $this->post($url, $data)->assertRedirect();
        $this->post($url, $this->payment())->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_stale_balance_void_invoice_and_reused_request_for_another_invoice_are_rejected(): void
    {
        $invoice = Invoice::factory()->create(['historical_paid_cents' => 10000]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/invoices/'.$invoice->id.'/mark-paid', $this->payment())->assertSessionHasErrors('payment');
        $invoice->update(['void_reason' => 'Cancelled']);
        $this->post('/admin/invoices/'.$invoice->id.'/mark-paid', $this->payment(33000))->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 0);
        $other = Invoice::factory()->create();
        $data = $this->payment();
        $this->post('/admin/invoices/'.$other->id.'/mark-paid', $data)->assertRedirect();
        $this->post('/admin/invoices/'.$invoice->id.'/mark-paid', $data)->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_method_and_non_future_date_are_required(): void
    {
        $invoice = Invoice::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $data = $this->payment();
        $this->post('/admin/invoices/'.$invoice->id.'/mark-paid', array_replace($data, ['payment_method' => 'invalid', 'paid_on' => now('America/Chicago')->addDay()->toDateString()]))->assertSessionHasErrors(['payment_method', 'paid_on']);
        unset($data['payment_method'], $data['paid_on']);
        $this->post('/admin/invoices/'.$invoice->id.'/mark-paid', $data)->assertSessionHasErrors(['payment_method', 'paid_on']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_residents_guests_and_impersonation_cannot_mark_paid(): void
    {
        $resident = User::factory()->create();
        $invoice = Invoice::factory()->create(['household_id' => $resident->household_id]);
        $url = '/admin/invoices/'.$invoice->id.'/mark-paid';
        $this->post($url, $this->payment())->assertRedirect('/login');
        $this->actingAs($resident)->get('/invoices/'.$invoice->id)->assertDontSee('Mark this invoice as paid');
        $this->post($url, $this->payment())->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post('/admin/impersonate/'.$resident->id);
        $this->post($url, $this->payment())->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }
}
