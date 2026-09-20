<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_upload_requires_preview_then_commits_once(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);
        $csv = "POSTED DATE,DESCRIPTION,AMOUNT,CURRENCY,FI TRANSACTION REFERENCE,CREDIT/DEBIT\n09/01/2026,Receipt,430.00,USD,REF1,Credit\n";
        $this->actingAs($admin)->post('/admin/imports', ['kind' => 'bank', 'file' => UploadedFile::fake()->createWithContent('bank.csv', $csv)])->assertRedirect();
        $this->assertDatabaseCount('bank_transactions', 0);
        $batch = DB::table('import_batches')->first();
        $this->assertNull($batch->path);
        $this->assertSame($csv, base64_decode($batch->source_base64));
        // A new replica has none of the previous request's local files.
        Storage::fake('local');
        $this->get('/admin/imports/'.$batch->id)->assertOk()->assertSee('Apply validated import');
        $this->post('/admin/imports/'.$batch->id)->assertRedirect('/admin?tab=imports');
        $this->assertDatabaseCount('bank_transactions', 1);
        $this->post('/admin/imports/'.$batch->id)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('bank_transactions', 1);
    }

    public function test_special_assessment_and_payment_workflow(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/admin/assessments', [
            'household_id' => $admin->household_id, 'title' => 'Roof repair', 'amount' => '125.50',
            'due_on' => now()->addDays(30)->toDateString(), 'request_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $invoice = Invoice::first();
        $this->assertSame(12550, $invoice->total_cents);
        $this->post('/admin/payments', [
            'household_id' => $admin->household_id, 'amount' => '150.00', 'paid_on' => now()->toDateString(),
            'note' => 'Check received', 'request_key' => (string) Str::uuid(), 'allocations' => [$invoice->id => '100.00'],
        ])->assertRedirect('/admin');
        $payment = DB::table('payments')->first();
        $this->assertSame(2550, $invoice->balanceCents());
        $this->get('/admin/payments/'.$payment->id.'/allocate')->assertOk();
        $this->post('/admin/payments/'.$payment->id.'/allocate', ['allocations' => [$invoice->id => '125.50']])->assertRedirect('/admin');
        $this->assertSame(0, $invoice->balanceCents());
        $this->get('/admin')->assertOk()->assertSee('Manage allocations');
    }

    public function test_administrator_cannot_disable_self_and_can_revoke_resident_access(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $resident = User::factory()->create();
        $this->actingAs($admin)->post('/admin/residents/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'active' => 0])->assertSessionHasErrors('active');
        $this->post('/admin/residents/'.$resident->id, ['name' => $resident->name, 'email' => $resident->email, 'active' => 0])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $resident->id, 'active' => false]);
    }

    public function test_duplicate_bank_receipts_and_overallocation_are_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = Invoice::factory()->create(['household_id' => $admin->household_id]);
        $batch = DB::table('import_batches')->insertGetId(['kind' => 'bank', 'filename' => 'test', 'checksum' => str_repeat('a', 64)]);
        $bank = DB::table('bank_transactions')->insertGetId(['reference' => 'receipt', 'posted_on' => now()->toDateString(), 'description' => 'Receipt', 'amount_cents' => 43000, 'import_batch_id' => $batch]);
        $data = ['household_id' => $admin->household_id, 'amount' => '430.00', 'paid_on' => now()->toDateString(), 'bank_transaction_id' => $bank, 'note' => 'Bank receipt', 'request_key' => (string) Str::uuid(), 'allocations' => [$invoice->id => '500.00']];
        $this->actingAs($admin)->post('/admin/payments', $data)->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 0);
        $data['allocations'][$invoice->id] = '430.00';
        $this->post('/admin/payments', $data)->assertRedirect('/admin');
        $data['request_key'] = (string) Str::uuid();
        $this->post('/admin/payments', $data)->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 1);
    }
}
