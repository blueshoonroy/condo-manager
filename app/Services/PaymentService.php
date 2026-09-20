<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function record(array $data, int $actor): int
    {
        return DB::transaction(function () use ($data, $actor) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $existing = DB::table('payments')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                return $existing->id;
            }
            if ($data['amount_cents'] <= 0) {
                $this->fail('Payment amount must be positive.');
            }
            if (! empty($data['bank_transaction_id'])) {
                $bank = DB::table('bank_transactions')->where('id', $data['bank_transaction_id'])->lockForUpdate()->first();
                if (! $bank || $bank->removed_at || $bank->review_required || $bank->amount_cents != $data['amount_cents'] || $bank->posted_on !== $data['paid_on'] || DB::table('payments')->where('bank_transaction_id', $bank->id)->exists()) {
                    $this->fail('Use an unrecorded bank credit with the same amount and posted date.');
                }
            }
            $allocations = [];
            foreach ($data['allocations'] ?? [] as $invoiceId => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->first();
                if (! $invoice || $invoice->household_id != $data['household_id'] || $invoice->void_reason || $amount > $invoice->balanceCents()) {
                    $this->fail('An allocation exceeds the invoice balance or belongs to another household.');
                }
                $allocations[$invoiceId] = $amount;
            }
            if (array_sum($allocations) > $data['amount_cents']) {
                $this->fail('Allocated amounts exceed the payment.');
            }
            $id = DB::table('payments')->insertGetId([
                'household_id' => $data['household_id'], 'bank_transaction_id' => $data['bank_transaction_id'] ?? null,
                'request_key' => $data['request_key'], 'amount_cents' => $data['amount_cents'], 'paid_on' => $data['paid_on'], 'note' => $data['note'],
                'user_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($allocations as $invoiceId => $amount) {
                DB::table('payment_allocations')->insert(['payment_id' => $id, 'invoice_id' => $invoiceId, 'amount_cents' => $amount]);
            }
            Audit::record('payment.recorded', 'payment:'.$id, ['amount_cents' => $data['amount_cents']], $actor);

            return $id;
        });
    }

    public function allocate(int $id, array $allocations): void
    {
        DB::transaction(function () use ($id, $allocations) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $payment = DB::table('payments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payment, 404);
            if ($payment->reversed_at || array_sum($allocations) > $payment->amount_cents) {
                $this->fail('This payment is reversed or the allocations exceed its amount.');
            }
            $before = DB::table('payment_allocations')->where('payment_id', $id)->pluck('amount_cents', 'invoice_id')->all();
            foreach ($allocations as $invoiceId => $amount) {
                $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->first();
                if (! $invoice || $invoice->household_id != $payment->household_id || $amount < 0 || ($invoice->void_reason && $amount > 0)
                    || $amount > $invoice->balanceCents() + ($before[$invoiceId] ?? 0)) {
                    $this->fail('An allocation exceeds its invoice balance or belongs to another household.');
                }
            }
            foreach (array_unique(array_merge(array_keys($before), array_keys($allocations))) as $invoiceId) {
                DB::table('payment_allocations')->updateOrInsert(['payment_id' => $id, 'invoice_id' => $invoiceId], ['amount_cents' => $allocations[$invoiceId] ?? 0]);
            }
            Audit::record('payment.allocated', 'payment:'.$id, ['before' => $before, 'after' => $allocations]);
        });
    }

    public function reverse(int $id, string $reason): void
    {
        DB::transaction(function () use ($id, $reason) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $payment = DB::table('payments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payment, 404);
            if (! $payment->reversed_at) {
                DB::table('payments')->where('id', $id)->update(['reversed_at' => now(), 'reversal_reason' => $reason, 'bank_transaction_id' => null, 'updated_at' => now()]);
                if ($payment->bank_transaction_id) {
                    DB::table('bank_transactions')->where('id', $payment->bank_transaction_id)->update(['review_required' => false]);
                }
                Audit::record('payment.reversed', 'payment:'.$id, ['reason' => $reason, 'bank_transaction_id' => $payment->bank_transaction_id]);
            }
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
