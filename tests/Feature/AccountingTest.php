<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CsvImporter;
use App\Services\FinanceService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_uses_exact_cents(): void
    {
        $this->assertSame(34944, Money::cents('349.44'));
        $this->assertSame(-1, Money::cents('-0.01'));
        $this->expectException(\InvalidArgumentException::class);
        Money::cents('10.999');
    }

    public function test_generation_is_idempotent_and_uses_effective_dues(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        config(['association.billing_enabled' => true, 'association.billing_start_month' => '2026-10']);
        $household = Household::factory()->create();
        DB::table('dues_rates')->insert(['unit_id' => $household->unit_id, 'effective_on' => '2026-10-01', 'amount_cents' => 43000]);
        $service = app(BillingService::class);
        $this->assertSame(1, $service->generate('2026-10'));
        $this->assertSame(0, $service->generate('2026-10'));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(43000, Invoice::first()->total_cents);
    }

    public function test_billing_is_disabled_until_configured(): void
    {
        config(['association.billing_enabled' => false]);
        $this->expectException(ValidationException::class);
        app(BillingService::class)->generate('2026-10');
    }

    public function test_generation_rejects_overlapping_freshbooks_dues(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2));
        config(['association.billing_enabled' => true, 'association.billing_start_month' => '2026-10']);
        Invoice::factory()->create(['source' => 'freshbooks', 'items' => [['name' => 'HOA Monthly Dues', 'description' => '', 'amount_cents' => 43000]]]);
        $this->expectException(ValidationException::class);
        app(BillingService::class)->generate('2026-10');
    }

    public function test_partial_payment_retries_and_reversal_preserve_balances(): void
    {
        $actor = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create();
        $service = app(PaymentService::class);
        $data = ['household_id' => $invoice->household_id, 'request_key' => (string) Str::uuid(), 'amount_cents' => 20000, 'paid_on' => now()->toDateString(), 'note' => 'Test payment', 'allocations' => [$invoice->id => 20000]];
        $id = $service->record($data, $actor->id);
        $this->assertSame($id, $service->record($data, $actor->id));
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(23000, $invoice->balanceCents());
        $service->reverse($id, 'Recorded against wrong receipt');
        $this->assertSame(43000, $invoice->balanceCents());
    }

    public function test_cross_household_allocations_are_rejected_atomically(): void
    {
        $actor = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create();
        try {
            app(PaymentService::class)->record(['household_id' => $actor->household_id, 'request_key' => (string) Str::uuid(), 'amount_cents' => 10000, 'paid_on' => now()->toDateString(), 'note' => 'Test', 'allocations' => [$invoice->id => 10000]], $actor->id);
            $this->fail('Expected validation failure.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_unapplied_credit_can_be_allocated_later_without_a_second_receipt(): void
    {
        $actor = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create();
        $service = app(PaymentService::class);
        $id = $service->record(['household_id' => $invoice->household_id, 'request_key' => (string) Str::uuid(), 'amount_cents' => 50000, 'paid_on' => now()->toDateString(), 'note' => 'Advance payment'], $actor->id);
        $service->allocate($id, [$invoice->id => 43000]);
        $service->allocate($id, [$invoice->id => 43000]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, $invoice->balanceCents());
        $this->assertSame(43000, (int) DB::table('payment_allocations')->sum('amount_cents'));
    }

    public function test_conflicting_bank_reference_blocks_the_entire_import(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bank');
        file_put_contents($path, "POSTED DATE,DESCRIPTION,AMOUNT,CURRENCY,FI TRANSACTION REFERENCE,CREDIT/DEBIT\n09/01/2026,Dues,430.00,USD,REF1,Credit\n09/02/2026,Other,310.00,USD,REF1,Credit\n");
        try {
            $id = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'test.csv', 'checksum' => hash_file('sha256', $path), 'status' => 'preview']);
            try {
                app(CsvImporter::class)->commit($id, $path);
                $this->fail('Expected conflicting reference to fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('bank_transactions', 0);
                $this->assertDatabaseHas('import_batches', ['id' => $id, 'status' => 'preview']);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_invoice_import_groups_lines_preserves_numbers_and_updates_payment_status(): void
    {
        Household::factory()->create(['client_name' => 'Sample Owner']);
        $path = tempnam(sys_get_temp_dir(), 'invoices');
        $header = "Client Name,Invoice #,Date Issued,Date Due,Invoice Status,Date Paid,Item Name,Item Description,Line Total,Currency\n";
        try {
            foreach (['overdue', 'paid'] as $status) {
                $paidOn = $status === 'paid' ? '2026-09-19' : '';
                file_put_contents($path, $header."Sample Owner,000001,2026-08-01,2026-09-01,$status,$paidOn,Dues,August,430.00,USD\nSample Owner,000001,2026-08-01,2026-09-01,$status,$paidOn,Assessment,Roof,50.00,USD\n");
                $id = DB::table('import_batches')->insertGetId(['kind' => 'invoices', 'filename' => 'test.csv', 'checksum' => hash_file('sha256', $path), 'status' => 'preview']);
                app(CsvImporter::class)->commit($id, $path);
                $this->assertDatabaseCount('invoices', 1);
                $invoice = Invoice::first();
                $this->assertSame('000001', $invoice->number);
                $this->assertSame(48000, $invoice->total_cents);
                $this->assertCount(2, $invoice->items);
                $this->assertSame($status === 'paid' ? 0 : 48000, $invoice->balanceCents());
            }
        } finally {
            unlink($path);
        }
    }

    public function test_bank_import_preserves_zero_prenotes_and_deduplicates(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bank');
        file_put_contents($path, "POSTED DATE,DESCRIPTION,AMOUNT,CURRENCY,FI TRANSACTION REFERENCE,CREDIT/DEBIT\n09/01/2026,Dues,430.00,USD,REF1,Credit\n09/02/2026,Verification,0.00,USD,REF2,Debit\n");
        try {
            $importer = app(CsvImporter::class);
            for ($i = 0; $i < 2; $i++) {
                $id = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'test.csv', 'checksum' => hash_file('sha256', $path), 'status' => 'preview']);
                $importer->commit($id, $path);
            }
            $this->assertDatabaseCount('bank_transactions', 2);
            $this->assertSame(43000, (int) DB::table('bank_transactions')->sum('amount_cents'));
        } finally {
            unlink($path);
        }
    }

    public function test_balance_needs_checkpoint_and_only_adds_later_transactions(): void
    {
        $this->assertNull(app(FinanceService::class)->snapshot()['balance']);
        $actor = User::factory()->create();
        $batch = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'test', 'checksum' => str_repeat('a', 64)]);
        DB::table('bank_transactions')->insert([
            ['reference' => 'before', 'posted_on' => '2026-09-01', 'description' => 'Before', 'amount_cents' => 43000, 'import_batch_id' => $batch],
            ['reference' => 'after', 'posted_on' => '2026-09-03', 'description' => 'After', 'amount_cents' => -10000, 'import_batch_id' => $batch],
        ]);
        DB::table('balance_checkpoints')->insert(['as_of' => '2026-09-02', 'amount_cents' => 500000, 'note' => 'Statement', 'user_id' => $actor->id]);
        $this->assertSame(490000, app(FinanceService::class)->snapshot()['balance']);
    }
}
