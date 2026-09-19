<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class SendInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(): void
    {
        if (! config('association.invoice_emails_enabled')) {
            return;
        }
        // Persist the exact payload before contacting the provider, so retries are identical.
        DB::transaction(function () {
            $delivery = DB::table('invoice_deliveries')->where('id', $this->deliveryId)->lockForUpdate()->first();
            if (! $delivery || $delivery->status !== 'pending' || $delivery->payload) {
                return;
            }
            $invoice = Invoice::find($delivery->invoice_id);
            $user = User::find($delivery->user_id);
            if (! $this->eligible($invoice, $user)) {
                DB::table('invoice_deliveries')->where('id', $this->deliveryId)->update(['status' => 'cancelled']);

                return;
            }
            $payload = [
                'from' => config('mail.from.name').' <'.config('mail.from.address').'>',
                'to' => [$user->email], 'subject' => 'Association invoice '.$invoice->number,
                'html' => view('mail.invoice', ['invoice' => $invoice, 'resident' => $user])->render(),
            ];
            DB::table('invoice_deliveries')->where('id', $this->deliveryId)->update(['payload' => json_encode($payload), 'first_attempt_at' => now(), 'updated_at' => now()]);
        });
        DB::transaction(function () {
            $delivery = DB::table('invoice_deliveries')->where('id', $this->deliveryId)->lockForUpdate()->first();
            if (! $delivery || $delivery->status !== 'pending' || $delivery->sent_at || ! $delivery->payload) {
                return;
            }
            $invoice = Invoice::find($delivery->invoice_id);
            $user = User::find($delivery->user_id);
            $payload = json_decode($delivery->payload, true, flags: JSON_THROW_ON_ERROR);
            if (! $this->eligible($invoice, $user) || $payload['to'][0] !== $user->email) {
                DB::table('invoice_deliveries')->where('id', $this->deliveryId)->update(['status' => 'cancelled']);

                return;
            }
            // Resend retains keys for 24 hours. Stop ambiguous retries before that window expires.
            if (now()->subHours(23)->gte($delivery->first_attempt_at)) {
                DB::table('invoice_deliveries')->where('id', $this->deliveryId)->update(['status' => 'review_required']);

                return;
            }
            if (config('mail.default') === 'resend') {
                Http::withToken(config('services.resend.key'))->timeout(20)
                    ->withHeaders(['Idempotency-Key' => 'invoice-delivery/'.hash('sha256', config('app.url')).'/'.$delivery->id])
                    ->post('https://api.resend.com/emails', $payload)->throw();
            } else {
                Mail::html($payload['html'], fn ($message) => $message->to($payload['to'])->subject($payload['subject']));
            }
            DB::table('invoice_deliveries')->where('id', $this->deliveryId)->update(['sent_at' => now(), 'status' => 'sent', 'updated_at' => now()]);
        });
    }

    private function eligible(?Invoice $invoice, ?User $user): bool
    {
        return $user && $user->active && $user->household?->active && $invoice && ! $invoice->void_reason && $user->household_id === $invoice->household_id;
    }
}
