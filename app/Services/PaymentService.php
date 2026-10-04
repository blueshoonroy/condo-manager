<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public const METHODS = ['zelle' => 'Zelle', 'check' => 'Check', 'cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'];

    /**
     * Record the remaining balance of one invoice as paid, optionally tied to an incoming bank deposit.
     *
     * When a deposit is chosen its posted date becomes the payment date, and it must match the balance exactly.
     */
    public function markInvoicePaid(int $invoiceId, int $expectedBalance, string $paidOn, string $method, string $note, string $requestKey, int $actor, ?int $bankTransactionId = null): int
    {
        return DB::transaction(function () use ($invoiceId, $expectedBalance, $paidOn, $method, $note, $requestKey, $actor, $bankTransactionId) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('payments')->where('request_key', $requestKey)->first();
            if ($existing) {
                if ($existing->reversed_at || ! DB::table('payment_allocations')->where('payment_id', $existing->id)->where('invoice_id', $invoiceId)->exists()) {
                    $this->fail('This payment request has already been used. Reload the invoice before recording a payment.');
                }

                return $existing->id;
            }
            $balance = $invoice->balanceCents();
            if ($invoice->void_reason || $balance <= 0) {
                $this->fail('This invoice is already paid or void. No payment was recorded.');
            }
            if ($balance !== $expectedBalance) {
                $this->fail('The invoice balance changed. Reload it and review the remaining amount before marking it paid.');
            }
            if ($bankTransactionId) {
                $deposit = DB::table('bank_transactions')->where('id', $bankTransactionId)->first();
                if (! $deposit || (int) $deposit->amount_cents !== $balance) {
                    $this->fail('Choose a bank deposit that matches the remaining invoice balance, or record a partial payment instead.');
                }
                $paidOn = $deposit->posted_on;
            }

            return $this->record([
                'household_id' => $invoice->household_id, 'request_key' => $requestKey, 'bank_transaction_id' => $bankTransactionId,
                'amount_cents' => $balance, 'paid_on' => $paidOn, 'payment_method' => $method, 'note' => $note,
                'allocations' => [$invoice->id => $balance],
            ], $actor);
        });
    }

    /**
     * Mark several invoices paid in one step, each with its own payment for its remaining balance.
     *
     * Everything succeeds or nothing is recorded, so a bad row never leaves a half-applied batch.
     *
     * @param  list<array{invoice_id: int, expected_balance: int, request_key: string, bank_transaction_id: int|null}>  $items
     */
    public function markInvoicesPaid(array $items, string $paidOn, string $method, string $note, int $actor): int
    {
        return DB::transaction(function () use ($items, $paidOn, $method, $note, $actor) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $count = 0;
            foreach ($items as $item) {
                $invoice = Invoice::find($item['invoice_id']);
                try {
                    $this->markInvoicePaid($item['invoice_id'], $item['expected_balance'], $paidOn, $method, $note, $item['request_key'], $actor, $item['bank_transaction_id'] ?? null);
                } catch (ValidationException $exception) {
                    $this->fail('Invoice #'.($invoice?->number ?? $item['invoice_id']).': '.implode(' ', $exception->errors()['payment'] ?? ['The payment could not be recorded.']));
                }
                $count++;
            }
            Audit::record('payments.bulk_recorded', 'invoices:'.$count, ['invoice_ids' => array_column($items, 'invoice_id')], $actor);

            return $count;
        });
    }

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
                'payment_method' => $data['payment_method'] ?? null,
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
