<?php

namespace Tests\Feature;

use App\Jobs\SuggestBudgetCategories;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\AiStructuredResponse;
use App\Services\BudgetCategorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BudgetCategorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function bank(string $description = 'DDA CHECK', int $amount = -10000): int
    {
        $batch = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'test.csv', 'checksum' => Str::random(64)]);

        return DB::table('bank_transactions')->insertGetId(['reference' => Str::uuid(), 'posted_on' => '2026-09-01', 'description' => $description, 'amount_cents' => $amount, 'import_batch_id' => $batch]);
    }

    private function review(int $bank, string $category, User $admin, bool $remember = false): void
    {
        $service = app(BudgetCategorizer::class);
        $service->review($bank, $service->fingerprint(DB::table('bank_transactions')->find($bank)), $category, 'Resident clarification', $remember, $admin->id);
    }

    public function test_vendor_rules_apply_to_matching_debits_but_not_refunds_or_changed_records(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $bank = $this->bank('  ACME CLEANING  ');
        $this->review($bank, 'cleaning', $admin, true);
        $next = $this->bank('acme cleaning', -11000);
        $refund = $this->bank('acme cleaning', 5000);
        $entries = app(BudgetCategorizer::class)->entries(2026)->keyBy('bank.id');
        $this->assertSame('cleaning', $entries[$next]['category']);
        $this->assertNull($entries[$refund]['category']);
        DB::table('bank_transactions')->where('id', $bank)->update(['amount_cents' => -99999]);
        $entry = app(BudgetCategorizer::class)->entries(2026)->keyBy('bank.id')[$bank];
        $this->assertNull($entry['category']);
        $this->assertTrue($entry['stale']);
        $this->actingAs($admin)->get('/budget/review?year=2026')->assertOk()->assertSee('changed since it was categorized');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_generic_check_rules_and_stale_approvals_are_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $bank = $this->bank();
        $fingerprint = app(BudgetCategorizer::class)->fingerprint(DB::table('bank_transactions')->find($bank));
        $this->actingAs($admin)->post('/admin/budget/transactions/'.$bank, ['fingerprint' => $fingerprint, 'category' => 'cleaning', 'remember' => 1])->assertSessionHasErrors('remember');
        DB::table('bank_transactions')->where('id', $bank)->update(['description' => 'Updated check']);
        $this->post('/admin/budget/transactions/'.$bank, ['fingerprint' => $fingerprint, 'category' => 'cleaning'])->assertSessionHasErrors('transaction');
        $this->assertDatabaseCount('budget_classifications', 0);
    }

    public function test_sol_suggestions_require_confirmation_and_never_change_payments(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $bank = $this->bank('Plumbing company repair');
        app(AiSettings::class)->save('openai', 'not-a-real-key-123', false, $admin->id);
        $run = app(BudgetCategorizer::class)->startAi(2026, $admin->id);
        $result = ['suggestions' => [['id' => $bank, 'category' => 'repairs', 'confidence' => 'high', 'reason' => 'Plumbing repair vendor.']]];
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($result)]]]]])]);
        (new SuggestBudgetCategories($run))->handle(app(AiStructuredResponse::class), app(BudgetCategorizer::class));
        $this->assertDatabaseHas('budget_ai_runs', ['id' => $run, 'status' => 'completed', 'model' => 'gpt-6-sol']);
        $this->assertDatabaseHas('budget_classifications', ['bank_transaction_id' => $bank, 'category' => null, 'suggested_category' => 'repairs']);
        $this->assertNull(app(BudgetCategorizer::class)->entries(2026)->first()['category']);
        $this->assertDatabaseCount('payments', 0);
        $this->actingAs($admin)->get('/budget/review?year=2026')->assertOk()->assertSee('Plumbing repair vendor.');
        $this->review($bank, 'repairs', $admin);
        $this->assertSame('repairs', app(BudgetCategorizer::class)->entries(2026)->first()['category']);
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-6-sol' && $request['reasoning']['effort'] === 'medium' && $request['store'] === false && ! str_contains(json_encode($request->data()), $admin->email));
    }

    public function test_ai_cannot_overwrite_a_concurrent_human_review_or_accept_unknown_ids(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $bank = $this->bank();
        app(AiSettings::class)->save('openai', 'not-a-real-key-123', false, $admin->id);
        $run = app(BudgetCategorizer::class)->startAi(2026, $admin->id);
        DB::table('budget_ai_runs')->where('id', $run)->update(['status' => 'processing']);
        $this->review($bank, 'legal', $admin);
        app(BudgetCategorizer::class)->saveSuggestions($run, ['suggestions' => [
            ['id' => $bank, 'category' => 'cleaning', 'confidence' => 'high', 'reason' => 'Guess'],
            ['id' => 99999, 'category' => 'cleaning', 'confidence' => 'high', 'reason' => 'Unknown'],
        ]]);
        $this->assertDatabaseHas('budget_classifications', ['bank_transaction_id' => $bank, 'category' => 'legal', 'suggested_category' => null]);
        $this->assertDatabaseCount('budget_classifications', 1);
    }

    public function test_failed_ai_run_is_sanitized_and_read_only_preview_cannot_start_one(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $resident = User::factory()->create(['is_admin' => true]);
        $this->bank();
        app(AiSettings::class)->save('openai', 'not-a-real-key-123', false, $admin->id);
        $run = app(BudgetCategorizer::class)->startAi(2026, $admin->id);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'PRIVATE KEY CONTENT'], 401)]);
        (new SuggestBudgetCategories($run))->handle(app(AiStructuredResponse::class), app(BudgetCategorizer::class));
        $this->assertDatabaseHas('budget_ai_runs', ['id' => $run, 'status' => 'failed']);
        $this->assertStringNotContainsString('PRIVATE', DB::table('budget_ai_runs')->find($run)->error);
        $this->actingAs($admin)->post('/admin/impersonate/'.$resident->id);
        $this->post('/admin/budget/suggest', ['year' => 2026])->assertForbidden();
        $this->assertDatabaseCount('budget_classifications', 0);
    }

    public function test_ai_reconsidering_a_changed_bank_record_does_not_restore_its_old_approval(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $bank = $this->bank('Vendor bill');
        $this->review($bank, 'cleaning', $admin);
        DB::table('bank_transactions')->where('id', $bank)->update(['amount_cents' => -50000]);
        app(AiSettings::class)->save('openai', 'not-a-real-key-123', false, $admin->id);
        $run = app(BudgetCategorizer::class)->startAi(2026, $admin->id);
        DB::table('budget_ai_runs')->where('id', $run)->update(['status' => 'processing']);
        app(BudgetCategorizer::class)->saveSuggestions($run, ['suggestions' => [['id' => $bank, 'category' => 'repairs', 'confidence' => 'low', 'reason' => 'Amount changed; check the bill.']]]);
        $this->assertDatabaseHas('budget_classifications', ['bank_transaction_id' => $bank, 'category' => null, 'reviewed_at' => null, 'suggested_category' => 'repairs']);
        $this->assertNull(app(BudgetCategorizer::class)->entries(2026)->first()['category']);
    }
}
