<?php

namespace Tests\Feature;

use App\Jobs\ReconcileBankTransactions;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AiMatcher;
use App\Services\AiSettings;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function fixture(string $provider = 'openai'): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create(['household_id' => $admin->household_id, 'issued_on' => now()->subDays(10)->toDateString()]);
        $batch = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'bank.csv', 'checksum' => str_repeat('a', 64)]);
        $bank = DB::table('bank_transactions')->insertGetId(['reference' => 'REF1', 'posted_on' => now()->toDateString(), 'description' => 'Zelle from '.$admin->name, 'amount_cents' => 43000, 'import_batch_id' => $batch]);
        app(AiSettings::class)->save($provider, 'test-key-not-a-real-secret', false, $admin->id);
        $run = app(ReconciliationService::class)->start(now()->subDays(30)->toDateString(), now()->toDateString(), $admin->id);

        return [$admin, $invoice, $bank, $run];
    }

    private function match(int $bank, int $invoice, int $amount = 43000): array
    {
        return ['bank_transaction_id' => $bank, 'allocations' => [['invoice_id' => $invoice, 'amount_cents' => $amount]], 'confidence' => 'high', 'reason' => 'Payer name, date and amount match.'];
    }

    private function fakeProvider(array $matches, string $provider = 'openai'): void
    {
        $json = json_encode(['matches' => $matches]);
        Http::fake($provider === 'openai' ? ['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => $json]]]]])]
            : ['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $json]]])]);
    }

    private function process(int $run): void
    {
        (new ReconcileBankTransactions($run))->handle(app(AiMatcher::class), app(ReconciliationService::class));
    }

    public function test_ai_preview_never_records_payment_and_approval_records_it_once(): void
    {
        [$admin, $invoice, $bank, $run] = $this->fixture();
        $this->fakeProvider([$this->match($bank, $invoice->id)]);
        $this->process($run);
        Queue::assertPushed(ReconcileBankTransactions::class);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(43000, $invoice->balanceCents());
        $suggestion = DB::table('reconciliation_suggestions')->first();
        $this->actingAs($admin)->get('/admin/reconciliation/'.$run)->assertOk()->assertSee('Approve and record payment');
        $this->post('/admin/reconciliation/suggestions/'.$suggestion->id, ['decision' => 'approve'])->assertRedirect();
        $this->assertSame(0, $invoice->balanceCents());
        $this->post('/admin/reconciliation/suggestions/'.$suggestion->id, ['decision' => 'approve'])->assertSessionHasErrors('match');
        $this->assertDatabaseCount('payments', 1);
        Http::assertSent(fn ($request) => $request['store'] === false && ! str_contains(json_encode($request->data()), $admin->email));
    }

    public function test_anthropic_preview_and_rejection_leave_balances_unchanged(): void
    {
        [$admin, $invoice, $bank, $run] = $this->fixture('anthropic');
        $this->fakeProvider([$this->match($bank, $invoice->id)], 'anthropic');
        $this->process($run);
        $suggestion = DB::table('reconciliation_suggestions')->first();
        $this->actingAs($admin)->post('/admin/reconciliation/suggestions/'.$suggestion->id, ['decision' => 'reject'])->assertRedirect();
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(43000, $invoice->balanceCents());
        Http::assertSent(fn ($request) => $request->hasHeader('anthropic-version', '2023-06-01') && $request['output_config']['format']['type'] === 'json_schema');
    }

    public function test_unknown_ids_excessive_amounts_and_duplicate_credits_are_discarded(): void
    {
        [$admin, $invoice, $bank, $run] = $this->fixture();
        $this->fakeProvider([$this->match(999999, $invoice->id), $this->match($bank, 999999), $this->match($bank, $invoice->id, 50000), $this->match($bank, $invoice->id), $this->match($bank, $invoice->id)]);
        $this->process($run);
        $this->assertDatabaseCount('reconciliation_suggestions', 1);
        $this->assertDatabaseHas('reconciliation_runs', ['id' => $run, 'status' => 'completed', 'skipped' => 4]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_stale_invoice_and_previously_recorded_bank_credit_cannot_be_approved(): void
    {
        [$admin, $invoice, $bank, $run] = $this->fixture();
        $this->fakeProvider([$this->match($bank, $invoice->id)]);
        $this->process($run);
        $suggestion = DB::table('reconciliation_suggestions')->first();
        $invoice->update(['historical_paid_cents' => 100]);
        $this->actingAs($admin)->post('/admin/reconciliation/suggestions/'.$suggestion->id, ['decision' => 'approve'])->assertSessionHasErrors('match');
        $this->assertDatabaseCount('payments', 0);
        $invoice->update(['historical_paid_cents' => 0]);
        app(PaymentService::class)->record(['household_id' => $admin->household_id, 'bank_transaction_id' => $bank, 'request_key' => (string) Str::uuid(), 'amount_cents' => 43000, 'paid_on' => now()->toDateString(), 'note' => 'Already recorded'], $admin->id);
        $this->post('/admin/reconciliation/suggestions/'.$suggestion->id, ['decision' => 'approve'])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_settings_encrypt_keys_and_never_flash_them_on_validation_errors(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $key = 'sk-test-private-key';
        $this->actingAs($admin)->post('/admin/reconciliation/settings', ['provider' => 'openai', 'api_key' => $key])->assertRedirect();
        $encrypted = DB::table('ai_settings')->where('provider', 'openai')->value('api_key');
        $this->assertNotSame($key, $encrypted);
        $this->assertSame($key, Crypt::decryptString($encrypted));
        $this->get('/admin/reconciliation')->assertOk()->assertDontSee($key);
        $this->post('/admin/reconciliation/settings', ['provider' => 'invalid', 'api_key' => $key])->assertSessionHasErrors('provider')->assertSessionMissing('_old_input.api_key');
        $this->assertStringNotContainsString($key, DB::table('audit_events')->get()->toJson());
        $this->post('/admin/reconciliation/settings', ['provider' => 'openai', 'remove_key' => 1])->assertRedirect();
        $this->assertNull(DB::table('ai_settings')->where('provider', 'openai')->value('api_key'));
    }

    public function test_residents_cannot_access_ai_settings_runs_or_approve_suggestions(): void
    {
        [$admin, $invoice, $bank, $run] = $this->fixture();
        $resident = User::factory()->create();
        $this->actingAs($resident)->get('/admin/reconciliation')->assertForbidden();
        $this->get('/admin/reconciliation/'.$run)->assertForbidden();
        $this->post('/admin/reconciliation/settings', ['provider' => 'openai', 'api_key' => 'test-private'])->assertForbidden();
        $this->post('/admin/reconciliation/suggestions/1', ['decision' => 'approve'])->assertForbidden();
        $this->post('/admin/assessments/preview', [])->assertForbidden();
    }

    public function test_provider_failure_does_not_leak_response_or_create_payments(): void
    {
        [$admin, $invoice, $bank, $run] = $this->fixture();
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'secret-private-provider-body'], 401)]);
        $this->process($run);
        $record = DB::table('reconciliation_runs')->find($run);
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('HTTP 401', $record->error);
        $this->assertStringNotContainsString('secret-private', $record->error);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('reconciliation_suggestions', 0);
    }

    public function test_cross_household_and_pre_invoice_receipts_are_discarded(): void
    {
        [$admin, $invoice, $bank, $run] = $this->fixture();
        $other = Invoice::factory()->create(['issued_on' => now()->subDays(5)->toDateString()]);
        $future = Invoice::factory()->create(['household_id' => $admin->household_id, 'issued_on' => now()->addDay()->toDateString()]);
        // Include deliberately unsafe candidates to exercise validation independently of selection.
        $record = DB::table('reconciliation_runs')->find($run);
        $snapshot = json_decode($record->snapshot, true);
        foreach ([$other, $future] as $extra) {
            $snapshot['invoices'][] = ['id' => $extra->id, 'household_id' => $extra->household_id, 'issued_on' => $extra->issued_on->toDateString(), 'balance_cents' => 43000];
        }
        DB::table('reconciliation_runs')->where('id', $run)->update(['snapshot' => json_encode($snapshot)]);
        $crossHousehold = $this->match($bank, $invoice->id, 10000);
        $crossHousehold['allocations'][] = ['invoice_id' => $other->id, 'amount_cents' => 10000];
        $this->fakeProvider([$crossHousehold, $this->match($bank, $future->id)]);
        $this->process($run);
        $this->assertDatabaseCount('reconciliation_suggestions', 0);
        $this->assertDatabaseHas('reconciliation_runs', ['id' => $run, 'skipped' => 2]);
    }

    public function test_partial_allocation_leaves_credit_and_pending_receipts_are_not_sent_again(): void
    {
        [$admin, $invoice, $bank, $run] = $this->fixture();
        $this->fakeProvider([$this->match($bank, $invoice->id, 30000)]);
        $this->process($run);
        $suggestion = DB::table('reconciliation_suggestions')->first();
        $newRun = app(ReconciliationService::class)->start(now()->subDays(30)->toDateString(), now()->toDateString(), $admin->id);
        $snapshot = json_decode(DB::table('reconciliation_runs')->find($newRun)->snapshot, true);
        $this->assertSame([], $snapshot['transactions']);
        $this->actingAs($admin)->get('/admin/reconciliation/'.$run)->assertSee('$130.00 will remain unapplied household credit.');
        $this->post('/admin/reconciliation/suggestions/'.$suggestion->id, ['decision' => 'approve'])->assertRedirect();
        $this->assertSame(13000, $invoice->balanceCents());
        $this->assertDatabaseHas('payments', ['amount_cents' => 43000]);
        $this->assertSame(30000, (int) DB::table('payment_allocations')->sum('amount_cents'));
    }

    public function test_repeated_start_is_blocked_and_invalid_json_creates_no_suggestions(): void
    {
        [$admin, $invoice, $bank, $run] = $this->fixture();
        $this->actingAs($admin)->post('/admin/reconciliation', ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()])->assertSessionHasErrors('ai');
        $this->assertDatabaseCount('reconciliation_runs', 1);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => 'not JSON']]]]])]);
        $this->process($run);
        $this->assertDatabaseHas('reconciliation_runs', ['id' => $run, 'status' => 'failed']);
        $this->assertDatabaseCount('reconciliation_suggestions', 0);
    }
}
