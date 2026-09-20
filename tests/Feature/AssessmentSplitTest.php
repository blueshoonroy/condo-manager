<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssessmentSplitTest extends TestCase
{
    use RefreshDatabase;

    private function households(): array
    {
        $ids = [];
        for ($number = 1; $number <= 5; $number++) {
            $unit = DB::table('units')->insertGetId(['number' => $number]);
            $household = Household::factory()->create(['unit_id' => $unit]);
            User::factory()->create(['household_id' => $household->id]);
            $ids[$number] = $household->id;
        }

        return $ids;
    }

    public function test_assessment_split_preserves_total_and_queues_one_invoice_per_unit_once(): void
    {
        $households = $this->households();
        $billing = app(BillingService::class);
        $key = (string) Str::uuid();
        $this->assertSame([1 => 1400, 2 => 2100, 3 => 2100, 4 => 2100, 5 => 2301], $billing->assessmentSplit(10001));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->assertSame(5, $billing->buildingAssessment('Roof repair', 10001, now()->addDays(30)->toDateString(), $key, $households));
        }
        $this->assertSame(10001, (int) Invoice::sum('total_cents'));
        $this->assertDatabaseCount('invoices', 5);
        $this->assertDatabaseCount('invoice_deliveries', 5);
        $this->assertStringContainsString('23%', Invoice::where('unit_id', 5)->first()->items[0]['description']);
    }

    public function test_changed_ownership_rolls_back_all_assessment_invoices(): void
    {
        $households = $this->households();
        Household::find($households[5])->update(['active' => false]);
        try {
            app(BillingService::class)->buildingAssessment('Roof', 10000, now()->addDays(30)->toDateString(), (string) Str::uuid(), $households);
            $this->fail('Expected missing owner to fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('invoice_deliveries', 0);
        }
    }

    public function test_admin_previews_then_confirms_server_calculated_amounts(): void
    {
        $households = $this->households();
        $admin = User::where('household_id', $households[3])->first();
        $admin->update(['is_admin' => true]);
        $this->actingAs($admin)->post('/admin/assessments/preview', ['title' => 'Roof', 'amount' => '100.01', 'due_on' => now()->addDays(30)->toDateString()])->assertOk()->assertSee('$23.01');
        $this->assertDatabaseCount('invoices', 0);
        $key = session('assessment_draft.request_key');
        $this->post('/admin/assessments/confirm', ['request_key' => $key, 'amount' => '1.00'])->assertRedirect('/admin');
        $this->assertSame(10001, (int) Invoice::sum('total_cents'));
        $this->post('/admin/assessments/confirm', ['request_key' => $key])->assertSessionHasErrors('assessment');
        $this->assertDatabaseCount('invoices', 5);
    }

    public function test_monthly_dues_start_october_first_and_are_due_on_fifteenth(): void
    {
        $households = $this->households();
        config(['association.billing_enabled' => true, 'association.billing_start_month' => '2026-10', 'association.billing_issue_day' => 1, 'association.billing_due_days' => 14]);
        foreach ($households as $unit => $household) {
            DB::table('dues_rates')->insert(['unit_id' => $unit, 'effective_on' => '2026-09-01', 'amount_cents' => $unit === 1 ? 31000 : 43000]);
        }
        $this->travelTo(CarbonImmutable::parse('2026-09-30 23:59:00', 'America/Chicago'));
        $this->artisan('association:bill')->assertSuccessful();
        $this->assertDatabaseCount('invoices', 0);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00:00', 'America/Chicago'));
        $this->artisan('association:bill')->assertSuccessful();
        $this->artisan('association:bill')->assertSuccessful();
        $this->assertDatabaseCount('invoices', 5);
        $october = Invoice::where('number', 'BM-202610-U1')->firstOrFail();
        $this->assertSame('2026-10-15', $october->due_on->toDateString());
        $this->assertSame(31000, $october->total_cents);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00:00', 'America/Chicago'));
        $this->artisan('association:bill')->assertSuccessful();
        $this->assertDatabaseCount('invoices', 10);
        $november = Invoice::where('number', 'BM-202611-U5')->firstOrFail();
        $this->assertSame('2026-11-15', $november->due_on->toDateString());
        $this->assertSame(43000, $november->total_cents);
        $invoice = Invoice::first();
        config(['association.payment_instructions' => "Pay via Zelle: 1262brynmawr@gmail.com\nPay by check to: 1262 W Bryn Mawr #3, Chicago IL 60660"]);
        $html = view('mail.invoice', ['invoice' => $invoice, 'resident' => User::first()])->render();
        $this->assertStringContainsString('1262brynmawr@gmail.com', $html);
        $this->assertStringContainsString('1262 W Bryn Mawr #3, Chicago IL 60660', $html);
    }
}
