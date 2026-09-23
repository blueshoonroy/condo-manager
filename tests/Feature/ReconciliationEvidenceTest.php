<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\PaymentService;
use App\Services\ReconciliationEvidence;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconciliationEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(User $user, array $invoices): array
    {
        return app(ReconciliationEvidence::class)->enrich([
            'transactions' => [['id' => 1, 'date' => '2026-09-15', 'description' => 'Zelle from '.$user->name, 'amount_cents' => 43000]],
            'invoices' => array_map(fn ($invoice) => ['id' => $invoice->id, 'household_id' => $invoice->household_id, 'issued_on' => $invoice->issued_on->toDateString(), 'balance_cents' => $invoice->balanceCents()], $invoices),
        ]);
    }

    private function payment(User $user, int $amount, array $allocations = []): int
    {
        return app(PaymentService::class)->record(['household_id' => $user->household_id, 'request_key' => (string) Str::uuid(), 'amount_cents' => $amount, 'paid_on' => '2026-09-14', 'note' => 'Private payment note', 'allocations' => $allocations], $user->id);
    }

    public function test_exact_totals_and_nearby_dates_rank_above_partial_and_old_invoices(): void
    {
        $user = User::factory()->create();
        $recent = Invoice::factory()->create(['household_id' => $user->household_id, 'issued_on' => '2026-09-01']);
        $old = Invoice::factory()->create(['household_id' => $user->household_id, 'issued_on' => '2026-04-01']);
        $partial = Invoice::factory()->create(['household_id' => $user->household_id, 'issued_on' => '2026-09-01', 'total_cents' => 50000]);
        $snapshot = $this->snapshot($user, [$recent, $old, $partial]);
        $candidates = collect($snapshot['transactions'][0]['ranked_candidates'])->keyBy('invoice_id');
        $this->assertSame($recent->id, $snapshot['transactions'][0]['ranked_candidates'][0]['invoice_id']);
        $this->assertTrue($candidates[$recent->id]['exact_total']);
        $this->assertSame(14, $candidates[$recent->id]['days_after_issue']);
        $this->assertSame('high', $candidates[$recent->id]['confidence_cap']);
        $this->assertSame('low', $candidates[$old->id]['confidence_cap']);
        $this->assertFalse($candidates[$partial->id]['exact_total']);
        $this->assertGreaterThan($candidates[$partial->id]['score'], $candidates[$recent->id]['score']);
        $bank = $snapshot['transactions'][0];
        $bank['description'] = 'Deposit';
        $this->assertSame('medium', app(ReconciliationEvidence::class)->assess($snapshot, $bank, [$recent->id => 43000])['confidence_cap']);
        $bank['amount_cents'] = 86000;
        $combined = app(ReconciliationEvidence::class)->assess($snapshot, $bank, [$recent->id => 43000, $old->id => 43000]);
        $this->assertTrue($combined['exact_total']);
        $this->assertSame('low', $combined['confidence_cap']);
    }

    public function test_ledger_counts_receipts_once_and_keeps_former_households_separate(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['household_id' => $user->household_id, 'issued_on' => '2026-09-01']);
        $historical = Invoice::factory()->create(['household_id' => $user->household_id, 'issued_on' => '2026-08-01', 'historical_paid_cents' => 43000, 'paid_on' => '2026-09-14']);
        Invoice::factory()->create(['unit_id' => $invoice->unit_id, 'total_cents' => 99000]);
        $this->payment($user, 50000, [$invoice->id => 20000]);
        $reversed = $this->payment($user, 43000);
        DB::table('payments')->where('id', $reversed)->update(['reversed_at' => now()]);
        $snapshot = $this->snapshot($user, [$invoice]);
        $ledger = collect($snapshot['household_ledgers'])->firstWhere('household_id', $user->household_id);
        $this->assertSame(86000, $ledger['invoice_total_cents']);
        $this->assertSame(93000, $ledger['recorded_payment_total_cents']);
        $this->assertSame(63000, $ledger['allocated_payment_cents']);
        $this->assertSame(30000, $ledger['unapplied_credit_cents']);
        $this->assertSame(23000, $ledger['outstanding_cents']);
        $this->assertCount(1, $snapshot['recorded_receipts']);
        $this->assertSame($historical->id, $snapshot['recorded_receipts'][0]['id']);
        $this->assertStringNotContainsString($user->email, json_encode($snapshot));
        $this->assertStringNotContainsString('Private payment note', json_encode($snapshot));
        $this->assertSame('low', $snapshot['transactions'][0]['ranked_candidates'][0]['confidence_cap']);
    }

    public function test_unknown_historical_payment_dates_are_only_possible_duplicates_near_issue(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['household_id' => $user->household_id, 'issued_on' => '2026-09-01']);
        Invoice::factory()->create(['household_id' => $user->household_id, 'issued_on' => '2026-08-01', 'historical_paid_cents' => 43000]);
        Invoice::factory()->create(['household_id' => $user->household_id, 'issued_on' => '2026-04-01', 'historical_paid_cents' => 43000]);
        $snapshot = $this->snapshot($user, [$invoice]);
        $this->assertCount(1, $snapshot['recorded_receipts']);
        $this->assertNull($snapshot['recorded_receipts'][0]['paid_on']);
    }

    private function runFixture(bool $existingPayment): array
    {
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 9, 20));
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create(['household_id' => $admin->household_id, 'issued_on' => '2026-09-01']);
        if ($existingPayment) {
            $this->payment($admin, 43000);
        }
        $batch = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'bank.csv', 'checksum' => str_repeat('a', 64)]);
        $bank = DB::table('bank_transactions')->insertGetId(['reference' => 'REF1', 'posted_on' => '2026-09-15', 'description' => 'Zelle from '.$admin->name, 'amount_cents' => 43000, 'import_batch_id' => $batch]);
        app(AiSettings::class)->save('openai', 'test-key-not-real', false, $admin->id);
        $run = app(ReconciliationService::class)->start('2026-09-01', '2026-09-20', $admin->id);
        DB::table('reconciliation_runs')->where('id', $run)->update(['status' => 'processing']);
        app(ReconciliationService::class)->saveSuggestions($run, ['matches' => [['bank_transaction_id' => $bank, 'allocations' => [['invoice_id' => $invoice->id, 'amount_cents' => 43000]], 'confidence' => 'high', 'reason' => 'Exact amount.']]]);

        return [$admin, $run, DB::table('reconciliation_suggestions')->first()];
    }

    public function test_possible_duplicate_is_low_confidence_and_requires_explicit_review(): void
    {
        [$admin, $run, $suggestion] = $this->runFixture(true);
        $this->assertSame('low', $suggestion->confidence);
        $this->actingAs($admin)->get('/admin/reconciliation/'.$run)->assertOk()->assertSee('Possible payment already recorded.')->assertSee('14 days')->assertSee('acknowledge_existing');
        $this->post('/admin/reconciliation/suggestions/'.$suggestion->id, ['decision' => 'approve'])->assertSessionHasErrors('match');
        $this->assertDatabaseCount('payments', 1);
        $this->post('/admin/reconciliation/suggestions/'.$suggestion->id, ['decision' => 'approve', 'acknowledge_existing' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_new_manual_receipt_blocks_stale_approval_even_when_another_invoice_is_unchanged(): void
    {
        [$admin, $run, $suggestion] = $this->runFixture(false);
        $this->payment($admin, 43000);
        $this->actingAs($admin)->post('/admin/reconciliation/suggestions/'.$suggestion->id, ['decision' => 'approve', 'acknowledge_existing' => 1])->assertSessionHasErrors('match');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('reconciliation_suggestions', ['id' => $suggestion->id, 'status' => 'pending']);
    }

    public function test_unmatched_deposits_show_existing_payment_clues_without_creating_payments(): void
    {
        [$admin, $run, $suggestion] = $this->runFixture(true);
        DB::table('reconciliation_suggestions')->where('id', $suggestion->id)->delete();
        $this->actingAs($admin)->get('/admin/reconciliation/'.$run)->assertOk()
            ->assertSee('Receipts to check against existing payments')->assertSee('Payment #1')
            ->assertDontSee('Approve and record payment');
        $this->assertDatabaseCount('payments', 1);
    }
}
