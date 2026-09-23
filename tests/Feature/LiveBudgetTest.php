<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\BudgetCategorizer;
use App\Services\LiveBudgetReport;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiveBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function bank(int $amount, string $description, string $date = '2026-09-01'): int
    {
        $batch = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'test.csv', 'checksum' => Str::random(64)]);

        return DB::table('bank_transactions')->insertGetId(['reference' => Str::uuid(), 'posted_on' => $date, 'description' => $description, 'amount_cents' => $amount, 'import_batch_id' => $batch]);
    }

    public function test_cash_invoices_and_manual_payments_are_not_double_counted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create(['household_id' => $admin->household_id, 'issued_on' => '2026-09-01']);
        $bank = $this->bank(43000, 'Resident payment');
        app(PaymentService::class)->record(['household_id' => $admin->household_id, 'bank_transaction_id' => $bank, 'amount_cents' => 43000, 'paid_on' => '2026-09-01', 'request_key' => (string) Str::uuid(), 'note' => 'Dues', 'allocations' => [$invoice->id => 43000]], $admin->id);
        app(PaymentService::class)->record(['household_id' => $admin->household_id, 'amount_cents' => 10000, 'paid_on' => '2026-09-01', 'request_key' => (string) Str::uuid(), 'note' => 'Unlinked credit'], $admin->id);
        $this->bank(-1000, 'PPD COMED PAYMENTS');
        $this->bank(-5000, 'DDA CHECK');
        $transfer = $this->bank(20000, 'Transfer from savings');
        $categorizer = app(BudgetCategorizer::class);
        $categorizer->review($transfer, $categorizer->fingerprint(DB::table('bank_transactions')->find($transfer)), 'transfer', 'Own savings account', false, $admin->id);
        $report = app(LiveBudgetReport::class)->build(2026);
        $this->assertSame(43000, $report['income']);
        $this->assertSame(6000, $report['expenses']);
        $this->assertSame(37000, $report['net']);
        $this->assertSame(43000, $report['billed']);
        $this->assertSame(0, $report['outstanding']);
        $this->assertSame(10000, $report['unlinkedPayments']);
        $this->assertSame(20000, $report['transfers']['in']);
        $this->assertSame(1, $report['needs_review']);
        $this->assertSame(43000, $report['rows']['dues']['total']);
        $this->actingAs($admin)->get('/budget/live?year=2026')->assertOk()->assertSee('$370.00')->assertSee('Clarify transactions (1)');
    }

    public function test_refunds_offset_expenses_and_removed_or_other_year_records_do_not_count(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->bank(-12000, 'COMED PAYMENT');
        $refund = $this->bank(2000, 'COMED REFUND');
        $categorizer = app(BudgetCategorizer::class);
        $categorizer->review($refund, $categorizer->fingerprint(DB::table('bank_transactions')->find($refund)), 'electricity', 'Refund', false, $admin->id);
        $removed = $this->bank(-99000, 'COMED REMOVED');
        DB::table('bank_transactions')->where('id', $removed)->update(['removed_at' => now()]);
        $this->bank(-50000, 'COMED PRIOR YEAR', '2025-01-01');
        $report = app(LiveBudgetReport::class)->build(2026);
        $this->assertSame(10000, $report['expenses']);
        $this->assertSame(0, $report['income']);
        $this->assertSame(2, $report['transaction_count']);
        $this->assertSame(-10000, $report['net']);
    }

    public function test_plan_is_separate_from_actuals_and_only_admins_can_change_it(): void
    {
        $resident = User::factory()->create();
        $this->actingAs($resident)->post('/admin/budget/plan', ['year' => 2026, 'targets' => ['electricity' => '1200.01']])->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/admin/budget/plan', ['year' => 2026, 'targets' => ['electricity' => '1200.01', 'water' => '0']])->assertSessionHasNoErrors();
        $report = app(LiveBudgetReport::class)->build(2026);
        $this->assertSame(120001, $report['planned_expenses']);
        $this->assertSame(0, $report['expenses']);
        $this->assertTrue($report['has_plan']);
        $this->post('/admin/budget/plan', ['year' => 2026, 'targets' => ['electricity' => '-1']])->assertSessionHasErrors('targets.electricity');
        $this->get('/budget')->assertRedirect('/budget/live?year='.now('America/Chicago')->year);
        $this->get('/budget/live?year=2025')->assertSessionHasErrors('year');
    }
}
