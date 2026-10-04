<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function deposit(int $amount = 43000, ?string $date = null, string $reference = 'dep'): int
    {
        $batch = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'test', 'checksum' => str_repeat('a', 64)]);

        return DB::table('bank_transactions')->insertGetId(['reference' => $reference, 'posted_on' => $date ?? now('America/Chicago')->toDateString(), 'description' => 'ZELLE FROM RESIDENT', 'amount_cents' => $amount, 'import_batch_id' => $batch]);
    }

    private function row(Invoice $invoice, ?int $deposit = null): array
    {
        return ['invoice_id' => $invoice->id, 'expected_balance' => $invoice->balanceCents(), 'request_key' => (string) Str::uuid(), 'bank_transaction_id' => $deposit];
    }

    public function test_review_page_lists_outstanding_invoices_and_skips_paid_or_void_ones(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $open = Invoice::factory()->create();
        $paid = Invoice::factory()->create(['historical_paid_cents' => 43000]);
        $void = Invoice::factory()->create(['void_reason' => 'Cancelled']);
        $this->deposit();
        $this->actingAs($admin)->get('/invoices')->assertOk()->assertSee('Mark selected as paid');
        $response = $this->post('/admin/invoices/bulk-pay/review', ['invoice_ids' => [$open->id, $paid->id, $void->id]]);
        $response->assertOk()->assertSee('#'.$open->number)->assertSee('Skipped because already paid or void')->assertSee($paid->number)->assertSee($void->number)->assertSee('ZELLE FROM RESIDENT');
    }

    public function test_bulk_payment_records_one_payment_per_invoice_with_shared_details_and_optional_deposit(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $first = Invoice::factory()->create();
        $second = Invoice::factory()->create(['historical_paid_cents' => 13000]);
        $deposit = $this->deposit(43000, '2026-09-28');
        $this->actingAs($admin)->post('/admin/invoices/bulk-pay', [
            'payments' => [$this->row($first, $deposit), $this->row($second)],
            'payment_method' => 'zelle', 'paid_on' => '2026-10-01',
        ])->assertRedirect('/invoices?status=outstanding')->assertSessionHas('status');
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['household_id' => $first->household_id, 'amount_cents' => 43000, 'bank_transaction_id' => $deposit, 'paid_on' => '2026-09-28', 'payment_method' => 'zelle', 'note' => '']);
        $this->assertDatabaseHas('payments', ['household_id' => $second->household_id, 'amount_cents' => 30000, 'bank_transaction_id' => null, 'paid_on' => '2026-10-01']);
        $this->assertSame('paid', $first->fresh()->status());
        $this->assertSame('paid', $second->fresh()->status());
        $this->assertDatabaseHas('audit_events', ['action' => 'payments.bulk_recorded', 'user_id' => $admin->id]);
    }

    public function test_one_bad_row_rolls_back_the_whole_batch(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $good = Invoice::factory()->create();
        $stale = Invoice::factory()->create();
        $rows = [$this->row($good), $this->row($stale)];
        $rows[1]['expected_balance'] = 100;
        $this->actingAs($admin)->post('/admin/invoices/bulk-pay', ['payments' => $rows, 'payment_method' => 'check', 'paid_on' => now('America/Chicago')->toDateString()])->assertSessionHasErrors('payment');
        $this->assertStringContainsString('#'.$stale->number, session('errors')->first('payment'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_deposit_must_match_balance_and_cannot_be_used_twice(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $first = Invoice::factory()->create();
        $second = Invoice::factory()->create();
        $deposit = $this->deposit();
        $wrong = $this->deposit(10000, null, 'other');
        $date = now('America/Chicago')->toDateString();
        $this->actingAs($admin)->post('/admin/invoices/bulk-pay', ['payments' => [$this->row($first, $wrong)], 'payment_method' => 'zelle', 'paid_on' => $date])->assertSessionHasErrors('payment');
        $this->post('/admin/invoices/bulk-pay', ['payments' => [$this->row($first, $deposit), $this->row($second, $deposit)], 'payment_method' => 'zelle', 'paid_on' => $date])->assertSessionHasErrors('payments.0.bank_transaction_id');
        $this->assertDatabaseCount('payments', 0);
        $this->post('/admin/invoices/bulk-pay', ['payments' => [$this->row($first, $deposit)], 'payment_method' => 'zelle', 'paid_on' => $date])->assertRedirect();
        $this->post('/admin/invoices/bulk-pay', ['payments' => [$this->row($second, $deposit)], 'payment_method' => 'zelle', 'paid_on' => $date])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_repeated_submit_with_same_request_keys_does_not_duplicate_payments(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create();
        $data = ['payments' => [$this->row($invoice)], 'payment_method' => 'cash', 'paid_on' => now('America/Chicago')->toDateString(), 'note' => 'Office drop-off'];
        $this->actingAs($admin)->post('/admin/invoices/bulk-pay', $data)->assertRedirect();
        $this->post('/admin/invoices/bulk-pay', $data)->assertRedirect();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['note' => 'Office drop-off']);
    }

    public function test_residents_cannot_use_bulk_payment(): void
    {
        $resident = User::factory()->create();
        $invoice = Invoice::factory()->create(['household_id' => $resident->household_id]);
        $this->actingAs($resident)->post('/admin/invoices/bulk-pay/review', ['invoice_ids' => [$invoice->id]])->assertForbidden();
        $this->post('/admin/invoices/bulk-pay', ['payments' => [$this->row($invoice)], 'payment_method' => 'cash', 'paid_on' => now()->toDateString()])->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }
}
