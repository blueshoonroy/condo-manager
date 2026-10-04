<?php

namespace Tests\Feature;

use App\Jobs\SyncPlaid;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CsvImporter;
use App\Services\FinanceService;
use App\Services\PaymentService;
use App\Services\PlaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlaidSyncTest extends TestCase
{
    use RefreshDatabase;

    private function connection(string $environment = 'production'): int
    {
        config(['services.plaid.environment' => $environment, 'services.plaid.client_id' => 'client', 'services.plaid.secret' => 'secret']);

        return DB::table('plaid_connections')->insertGetId(['environment' => $environment, 'access_token' => Crypt::encryptString('private-access-token'), 'account_id' => 'account', 'status' => 'connected', 'starts_on' => '2026-09-01']);
    }

    private function transaction(string $id, float $amount = -430, bool $pending = false, string $date = '2026-09-10'): array
    {
        return ['transaction_id' => $id, 'account_id' => 'account', 'date' => $date, 'name' => 'Test bank transaction', 'amount' => $amount, 'pending' => $pending, 'iso_currency_code' => 'USD'];
    }

    private function page(array $added = [], array $modified = [], array $removed = [], string $cursor = 'cursor-1', bool $more = false): array
    {
        return ['added' => $added, 'modified' => $modified, 'removed' => $removed, 'next_cursor' => $cursor, 'has_more' => $more];
    }

    private function fakeSync(array $page): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/transactions/sync' => Http::response($page),
            '*/accounts/balance/get' => Http::response(['accounts' => [['account_id' => 'account', 'balances' => ['current' => 12345.67, 'available' => 12000, 'iso_currency_code' => 'USD']]]]),
        ]);
    }

    public function test_blocked_live_balance_falls_back_to_cached_balance_and_clears_the_error(): void
    {
        $id = $this->connection();
        DB::table('plaid_connections')->where('id', $id)->update(['status' => 'error', 'error_code' => 'MFA_NOT_SUPPORTED']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/transactions/sync' => Http::response($this->page([$this->transaction('credit')])),
            '*/accounts/balance/get' => Http::response(['error_code' => 'MFA_NOT_SUPPORTED', 'error_type' => 'ITEM_ERROR'], 400),
            '*/accounts/get' => Http::response(['accounts' => [['account_id' => 'account', 'balances' => ['current' => 9876.54, 'available' => 9000, 'iso_currency_code' => 'USD']]]]),
        ]);
        (new SyncPlaid(true))->handle(app(PlaidService::class));
        $this->assertDatabaseHas('plaid_connections', ['id' => $id, 'balance_cents' => 987654, 'status' => 'connected', 'error_code' => null]);
        $this->assertNotNull(DB::table('plaid_connections')->find($id)->balance_fetched_at);
        Http::swap(new Factory);
        Http::fake([
            '*/transactions/sync' => Http::response($this->page(cursor: 'cursor-2')),
            '*/accounts/balance/get' => Http::response(['error_code' => 'MFA_NOT_SUPPORTED'], 400),
            '*/accounts/get' => Http::response(['error_code' => 'ITEM_LOGIN_REQUIRED'], 400),
        ]);
        (new SyncPlaid(true))->handle(app(PlaidService::class));
        $this->assertDatabaseHas('plaid_connections', ['id' => $id, 'balance_cents' => 987654, 'status' => 'error', 'error_code' => 'MFA_NOT_SUPPORTED']);
    }

    public function test_sandbox_data_never_enters_resident_finances(): void
    {
        $this->connection('sandbox');
        $this->fakeSync($this->page([$this->transaction('test')]));
        app(PlaidService::class)->sync();
        $this->assertDatabaseCount('plaid_transactions', 1);
        $this->assertDatabaseCount('bank_transactions', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertNull(app(FinanceService::class)->snapshot()['balance']);
        $this->assertDatabaseHas('plaid_connections', ['balance_cents' => 1234567, 'cursor' => 'cursor-1']);
    }

    public function test_production_sync_filters_pending_other_accounts_and_csv_history_and_is_idempotent(): void
    {
        $this->connection();
        $other = $this->transaction('other');
        $other['account_id'] = 'different-account';
        $this->fakeSync($this->page([$this->transaction('credit'), $this->transaction('debit', 19.99), $this->transaction('pending', pending: true), $this->transaction('history', date: '2026-08-31'), $other]));
        app(PlaidService::class)->sync();
        app(PlaidService::class)->sync();
        $this->assertDatabaseCount('bank_transactions', 2);
        $this->assertDatabaseCount('plaid_transactions', 4);
        $this->assertDatabaseHas('bank_transactions', ['amount_cents' => 43000, 'source' => 'plaid']);
        $this->assertDatabaseHas('bank_transactions', ['amount_cents' => -1999]);
        $this->assertSame(1234567, app(FinanceService::class)->snapshot()['balance']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_pagination_failure_preserves_cursor_and_all_local_data(): void
    {
        $this->connection();
        Http::fake(['*/transactions/sync' => Http::sequence()->push($this->page([$this->transaction('first')], cursor: 'page-one', more: true))->push(['error_code' => 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION'], 400)]);
        (new SyncPlaid)->handle(app(PlaidService::class));
        $this->assertDatabaseCount('bank_transactions', 0);
        $this->assertDatabaseCount('plaid_transactions', 0);
        $this->assertDatabaseHas('plaid_connections', ['cursor' => null, 'error_code' => 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION']);
    }

    public function test_modified_and_removed_receipts_preserve_payments_for_review(): void
    {
        $this->connection();
        $this->fakeSync($this->page([$this->transaction('receipt')]));
        app(PlaidService::class)->sync();
        $bank = DB::table('bank_transactions')->first();
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create();
        $payment = app(PaymentService::class)->record(['household_id' => $invoice->household_id, 'bank_transaction_id' => $bank->id, 'amount_cents' => 43000, 'paid_on' => '2026-09-10', 'request_key' => (string) Str::uuid(), 'note' => 'Approved', 'allocations' => [$invoice->id => 43000]], $admin->id);
        $this->fakeSync($this->page(modified: [$this->transaction('receipt', -310)], cursor: 'cursor-2'));
        app(PlaidService::class)->sync();
        $this->assertDatabaseHas('bank_transactions', ['id' => $bank->id, 'amount_cents' => 31000, 'review_required' => true]);
        $this->assertSame(0, $invoice->balanceCents());
        $this->fakeSync($this->page(removed: [['transaction_id' => 'receipt']], cursor: 'cursor-3'));
        app(PlaidService::class)->sync();
        $this->assertNotNull(DB::table('bank_transactions')->find($bank->id)->removed_at);
        $this->assertDatabaseHas('payments', ['id' => $payment, 'reversed_at' => null]);
        app(PaymentService::class)->reverse($payment, 'Bank removed receipt');
        $this->assertSame(43000, $invoice->balanceCents());
        $this->assertDatabaseHas('bank_transactions', ['id' => $bank->id, 'review_required' => false]);
    }

    public function test_removed_receipt_cannot_be_used_for_a_new_payment(): void
    {
        $this->connection();
        $this->fakeSync($this->page([$this->transaction('receipt')]));
        app(PlaidService::class)->sync();
        $bank = DB::table('bank_transactions')->first();
        DB::table('bank_transactions')->where('id', $bank->id)->update(['removed_at' => now()]);
        $user = User::factory()->create();
        $this->expectException(ValidationException::class);
        app(PaymentService::class)->record(['household_id' => $user->household_id, 'bank_transaction_id' => $bank->id, 'amount_cents' => 43000, 'paid_on' => '2026-09-10', 'request_key' => (string) Str::uuid(), 'note' => 'Rejected'], $user->id);
    }

    private function importCsv(string $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'plaid-csv');
        file_put_contents($path, "POSTED DATE,DESCRIPTION,AMOUNT,CURRENCY,FI TRANSACTION REFERENCE,CREDIT/DEBIT\n".$rows);
        try {
            $result = app(CsvImporter::class)->inspect($path, 'bank');
            $this->assertSame([], $result['errors']);
            $batch = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'test.csv', 'checksum' => hash_file('sha256', $path), 'status' => 'preview', 'user_id' => User::factory()->create(['is_admin' => true])->id, 'created_at' => now(), 'updated_at' => now()]);

            return app(CsvImporter::class)->commit($batch, $path);
        } finally {
            unlink($path);
        }
    }

    public function test_csv_upload_overlapping_plaid_matches_synced_rows_instead_of_duplicating(): void
    {
        $this->connection();
        $this->fakeSync($this->page([$this->transaction('a'), $this->transaction('b')]));
        app(PlaidService::class)->sync();
        $summary = $this->importCsv("09/10/2026,ZELLE FROM A,430.00,USD,REF1,Credit\n09/10/2026,ZELLE FROM B,430.00,USD,REF2,Credit\n09/10/2026,ZELLE FROM C,430.00,USD,REF3,Credit\n09/11/2026,FEE,-19.99,USD,REF4,Debit\n");
        $this->assertSame(2, $summary['matched']);
        $this->assertSame(2, $summary['new']);
        $this->assertDatabaseCount('bank_transactions', 4);
        $this->assertDatabaseHas('bank_transactions', ['reference' => 'REF3', 'source' => 'csv']);
        $this->assertDatabaseMissing('bank_transactions', ['reference' => 'REF1']);
        $again = $this->importCsv("09/10/2026,ZELLE FROM C,430.00,USD,REF3,Credit\n");
        $this->assertSame(['new' => 0, 'matched' => 0, 'unchanged' => 1], ['new' => $again['new'], 'matched' => $again['matched'], 'unchanged' => $again['unchanged']]);
        $this->assertDatabaseCount('bank_transactions', 4);
    }

    public function test_plaid_sync_links_to_csv_rows_already_uploaded_and_leaves_them_untouched(): void
    {
        $this->connection();
        $this->importCsv("09/10/2026,ZELLE FROM A,430.00,USD,REF1,Credit\n09/10/2026,ZELLE FROM B,430.00,USD,REF2,Credit\n");
        $this->fakeSync($this->page([$this->transaction('a'), $this->transaction('b'), $this->transaction('c')]));
        app(PlaidService::class)->sync();
        $this->assertDatabaseCount('bank_transactions', 3);
        $this->assertSame(1, DB::table('bank_transactions')->where('source', 'plaid')->count());
        $this->assertSame(0, DB::table('plaid_transactions')->whereNull('bank_transaction_id')->count());
        $csv = DB::table('bank_transactions')->where('reference', 'REF1')->first();
        $this->assertSame('ZELLE FROM A', $csv->description);
        $this->fakeSync($this->page(removed: [['transaction_id' => 'a'], ['transaction_id' => 'b']], cursor: 'cursor-2'));
        app(PlaidService::class)->sync();
        $this->assertSame(0, DB::table('bank_transactions')->whereNotNull('removed_at')->count());
    }

    public function test_stale_plaid_balance_yields_to_csv_based_calculation(): void
    {
        $id = $this->connection();
        $admin = User::factory()->create(['is_admin' => true]);
        DB::table('balance_checkpoints')->insert(['as_of' => '2026-09-01', 'amount_cents' => 100000, 'note' => 'Statement', 'user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->importCsv("09/10/2026,ZELLE FROM A,430.00,USD,REF1,Credit\n");
        DB::table('plaid_connections')->where('id', $id)->update(['balance_cents' => 555555, 'balance_fetched_at' => now()->subMinutes(5)]);
        $this->assertSame(555555, app(FinanceService::class)->snapshot()['balance']);
        DB::table('plaid_connections')->where('id', $id)->update(['status' => 'error', 'error_code' => 'MFA_NOT_SUPPORTED']);
        $this->assertSame(143000, app(FinanceService::class)->snapshot()['balance']);
        DB::table('plaid_connections')->where('id', $id)->update(['status' => 'connected', 'balance_fetched_at' => now()->subDays(2)]);
        $this->assertSame(143000, app(FinanceService::class)->snapshot()['balance']);
    }

    public function test_link_exchange_and_account_selection_use_server_verified_accounts(): void
    {
        config(['services.plaid.environment' => 'sandbox', 'services.plaid.client_id' => 'client', 'services.plaid.secret' => 'secret']);
        $admin = User::factory()->create(['is_admin' => true]);
        Http::fake([
            '*/item/public_token/exchange' => Http::response(['item_id' => 'item', 'access_token' => 'exchanged-token']),
            '*/accounts/get' => Http::response(['accounts' => [['account_id' => 'owned-account', 'type' => 'depository', 'subtype' => 'checking', 'name' => 'Test checking', 'mask' => '1234', 'balances' => ['iso_currency_code' => 'USD']]]]),
        ]);
        app(PlaidService::class)->exchange('public-token', $admin->id);
        $this->assertSame('exchanged-token', Crypt::decryptString(DB::table('plaid_connections')->value('access_token')));
        $this->actingAs($admin)->post('/admin/bank/account', ['account_id' => 'unowned', 'starts_on' => '2026-09-01'])->assertSessionHasErrors('bank');
        $this->assertDatabaseHas('plaid_connections', ['status' => 'select_account', 'account_id' => null]);
        Queue::fake();
        $this->post('/admin/bank/account', ['account_id' => 'owned-account', 'starts_on' => '2026-09-01'])->assertRedirect('/admin/bank/connect');
        $this->assertDatabaseHas('plaid_connections', ['account_id' => 'owned-account', 'status' => 'connected']);
        Queue::assertPushed(SyncPlaid::class);
    }

    public function test_pending_to_posted_transition_across_pages_is_atomic(): void
    {
        $this->connection();
        $this->fakeSync($this->page([$this->transaction('pending', pending: true)]));
        app(PlaidService::class)->sync();
        Http::swap(new Factory);
        Http::fake(['*/transactions/sync' => Http::sequence()
            ->push($this->page([$this->transaction('posted')], cursor: 'page-2', more: true))
            ->push($this->page(removed: [['transaction_id' => 'pending']], cursor: 'page-3'))]);
        app(PlaidService::class)->sync();
        $this->assertDatabaseCount('bank_transactions', 1);
        $this->assertDatabaseHas('plaid_transactions', ['transaction_id' => 'pending', 'removed' => true]);
        $this->assertDatabaseHas('plaid_connections', ['cursor' => 'page-3']);
    }

    public function test_only_admin_can_manage_bank_and_access_token_is_encrypted(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $resident = User::factory()->create();
        $this->actingAs($resident)->get('/admin/bank/connect')->assertForbidden();
        foreach (['link-token', 'exchange', 'account', 'sync', 'disconnect'] as $path) {
            $this->post('/admin/bank/'.$path)->assertForbidden();
        }
        $this->connection('sandbox');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/bank/connect')->assertOk()->assertSee('Sandbox test mode')->assertDontSee('private-access-token');
        $stored = DB::table('plaid_connections')->value('access_token');
        $this->assertNotSame('private-access-token', $stored);
        $this->assertSame('private-access-token', Crypt::decryptString($stored));
    }
}
