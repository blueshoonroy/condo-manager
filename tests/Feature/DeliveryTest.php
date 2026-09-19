<?php

namespace Tests\Feature;

use App\Jobs\SendInvoice;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(): int
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['household_id' => $user->household_id]);

        return DB::table('invoice_deliveries')->insertGetId(['invoice_id' => $invoice->id, 'user_id' => $user->id]);
    }

    public function test_disabled_delivery_sends_nothing(): void
    {
        Http::fake();
        config(['association.invoice_emails_enabled' => false]);
        (new SendInvoice($this->delivery()))->handle();
        Http::assertNothingSent();
    }

    public function test_retry_uses_identical_payload_and_provider_key(): void
    {
        config(['association.invoice_emails_enabled' => true, 'mail.default' => 'resend', 'services.resend.key' => 'test-only']);
        Http::fakeSequence()->push([], 500)->push(['id' => 'email-id']);
        $id = $this->delivery();
        try {
            (new SendInvoice($id))->handle();
            $this->fail('Expected provider failure.');
        } catch (RequestException) {
            $this->assertDatabaseHas('invoice_deliveries', ['id' => $id, 'status' => 'pending']);
        }
        (new SendInvoice($id))->handle();
        (new SendInvoice($id))->handle();
        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame($requests[0][0]->data(), $requests[1][0]->data());
        $this->assertSame($requests[0][0]->header('Idempotency-Key'), $requests[1][0]->header('Idempotency-Key'));
        $this->assertDatabaseHas('invoice_deliveries', ['id' => $id, 'status' => 'sent']);
    }

    public function test_ambiguous_old_attempts_require_review_instead_of_resending(): void
    {
        Http::fake();
        config(['association.invoice_emails_enabled' => true, 'mail.default' => 'resend']);
        $id = $this->delivery();
        $user = User::first();
        DB::table('invoice_deliveries')->where('id', $id)->update(['payload' => json_encode(['to' => [$user->email]]), 'first_attempt_at' => now()->subHours(24)]);
        (new SendInvoice($id))->handle();
        Http::assertNothingSent();
        $this->assertDatabaseHas('invoice_deliveries', ['id' => $id, 'status' => 'review_required']);
    }
}
