<?php

namespace App\Services;

use App\Jobs\ReconcileBankTransactions;
use App\Models\Invoice;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReconciliationService
{
    public function start(string $from, string $to, int $actor): int
    {
        $id = DB::transaction(function () use ($from, $to, $actor) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            DB::table('reconciliation_runs')->whereIn('status', ['queued', 'processing'])->where('updated_at', '<', now()->subMinutes(15))
                ->update(['status' => 'failed', 'error' => 'This run timed out. Check the queue worker and start a new run.', 'updated_at' => now()]);
            if (DB::table('reconciliation_runs')->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['ai' => 'A reconciliation is already running. Refresh its results shortly.']);
            }
            $provider = app(AiSettings::class)->selectedProvider();
            $banks = DB::table('bank_transactions')->whereNull('removed_at')->where('review_required', false)->where('amount_cents', '>', 0)->whereBetween('posted_on', [$from, $to])
                ->whereNotIn('id', DB::table('payments')->whereNotNull('bank_transaction_id')->select('bank_transaction_id'))
                ->whereNotIn('id', DB::table('reconciliation_suggestions')->where('status', 'pending')->select('bank_transaction_id'))
                ->orderBy('posted_on')->orderBy('id')->limit(81)->get();
            $invoices = Invoice::with('household.residents')->whereNull('void_reason')->where('issued_on', '<=', $to)->get()
                ->filter(fn ($invoice) => $invoice->balanceCents() > 0);
            if ($banks->count() > 80 || $invoices->count() > 100) {
                throw ValidationException::withMessages(['ai' => 'Each run supports 80 bank credits and 100 open invoices. Narrow the bank date range or settle older invoices first.']);
            }
            $snapshot = [
                'transactions' => $banks->map(fn ($bank) => ['id' => $bank->id, 'date' => $bank->posted_on, 'description' => mb_substr($bank->description, 0, 500), 'amount_cents' => (int) $bank->amount_cents])->values()->all(),
                'invoices' => $invoices->map(fn ($invoice) => [
                    'id' => $invoice->id, 'number' => $invoice->number, 'household_id' => $invoice->household_id,
                    'unit' => $invoice->unit_id, 'household' => $invoice->household->client_name,
                    'resident_names' => $invoice->household->residents->pluck('name')->all(),
                    'issued_on' => $invoice->issued_on->toDateString(), 'due_on' => $invoice->due_on->toDateString(),
                    'total_cents' => (int) $invoice->total_cents, 'balance_cents' => $invoice->balanceCents(),
                ])->values()->all(),
            ];
            $snapshot = app(ReconciliationEvidence::class)->enrich($snapshot);
            $id = DB::table('reconciliation_runs')->insertGetId([
                'user_id' => $actor, 'provider' => $provider, 'model' => AiSettings::MODELS[$provider], 'from_date' => $from, 'to_date' => $to,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('reconciliation.started', 'run:'.$id, ['provider' => $provider], $actor);

            return $id;
        });
        ReconcileBankTransactions::dispatch($id);

        return $id;
    }

    public function saveSuggestions(int $runId, array $result): void
    {
        Validator::make($result, [
            'matches' => 'present|array|max:80', 'matches.*.bank_transaction_id' => 'required|integer',
            'matches.*.allocations' => 'required|array|min:1|max:100', 'matches.*.allocations.*.invoice_id' => 'required|integer',
            'matches.*.allocations.*.amount_cents' => 'required|integer|min:1|max:999999999',
            'matches.*.confidence' => 'required|in:high,medium,low', 'matches.*.reason' => 'required|string|max:1000',
        ])->validate();
        DB::transaction(function () use ($runId, $result) {
            $run = DB::table('reconciliation_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $run || $run->status !== 'processing') {
                return;
            }
            $snapshot = json_decode($run->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $banks = collect($snapshot['transactions'])->keyBy('id');
            $invoices = collect($snapshot['invoices'])->keyBy('id');
            $usedBanks = $usedAmounts = [];
            $skipped = 0;
            foreach ($result['matches'] as $match) {
                $bank = $banks->get($match['bank_transaction_id']);
                $allocations = [];
                $household = null;
                $valid = $bank && ! isset($usedBanks[$bank['id']]);
                foreach ($match['allocations'] as $allocation) {
                    $invoice = $invoices->get($allocation['invoice_id']);
                    $amount = $allocation['amount_cents'];
                    if (! $invoice || isset($allocations[$invoice['id']]) || ($household !== null && $household !== $invoice['household_id'])
                        || $amount + ($usedAmounts[$invoice['id']] ?? 0) > $invoice['balance_cents'] || ($bank && $bank['date'] < $invoice['issued_on'])) {
                        $valid = false;
                        break;
                    }
                    $household = $invoice['household_id'];
                    $allocations[$invoice['id']] = (int) $amount;
                }
                if (! $valid || array_sum($allocations) > $bank['amount_cents']) {
                    $skipped++;

                    continue;
                }
                $usedBanks[$bank['id']] = true;
                foreach ($allocations as $invoiceId => $amount) {
                    $usedAmounts[$invoiceId] = ($usedAmounts[$invoiceId] ?? 0) + $amount;
                }
                $evidence = app(ReconciliationEvidence::class)->assess($snapshot, $bank, $allocations);
                $levels = ['low' => 0, 'medium' => 1, 'high' => 2];
                $confidence = $levels[$match['confidence']] <= $levels[$evidence['confidence_cap']] ? $match['confidence'] : $evidence['confidence_cap'];
                DB::table('reconciliation_suggestions')->insert([
                    'run_id' => $runId, 'bank_transaction_id' => $bank['id'], 'household_id' => $household,
                    'allocations' => json_encode($allocations), 'confidence' => $confidence, 'reason' => $match['reason'],
                    'status' => 'pending', 'request_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('reconciliation_runs')->where('id', $runId)->update(['status' => 'completed', 'skipped' => $skipped, 'updated_at' => now()]);
        });
    }

    public function review(int $id, bool $approve, int $actor, bool $acknowledgeExisting = false): void
    {
        DB::transaction(function () use ($id, $approve, $actor, $acknowledgeExisting) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $suggestion = DB::table('reconciliation_suggestions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($suggestion, 404);
            if ($suggestion->status !== 'pending') {
                throw ValidationException::withMessages(['match' => 'This suggestion has already been reviewed.']);
            }
            $paymentId = null;
            if ($approve) {
                $run = DB::table('reconciliation_runs')->find($suggestion->run_id);
                $snapshot = json_decode($run->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $originalBank = collect($snapshot['transactions'])->firstWhere('id', $suggestion->bank_transaction_id);
                $bank = DB::table('bank_transactions')->find($suggestion->bank_transaction_id);
                $allocations = json_decode($suggestion->allocations, true, flags: JSON_THROW_ON_ERROR);
                foreach ($allocations as $invoiceId => $amount) {
                    $original = collect($snapshot['invoices'])->firstWhere('id', $invoiceId);
                    $invoice = Invoice::find($invoiceId);
                    if (! $invoice || ! $original || $invoice->balanceCents() !== $original['balance_cents'] || $invoice->household_id != $suggestion->household_id) {
                        throw ValidationException::withMessages(['match' => 'An invoice changed since this preview. Reject the suggestion and run reconciliation again.']);
                    }
                }
                if (! $bank || ! $originalBank || (int) $bank->amount_cents !== $originalBank['amount_cents'] || $bank->posted_on !== $originalBank['date']) {
                    throw ValidationException::withMessages(['match' => 'The bank transaction changed. Run reconciliation again.']);
                }
                $evidence = app(ReconciliationEvidence::class);
                $liveSnapshot = $evidence->enrich(['transactions' => [$originalBank], 'invoices' => []]);
                $existing = $evidence->possibleReceipts($snapshot, $originalBank, (int) $suggestion->household_id);
                $current = $evidence->possibleReceipts($liveSnapshot, $originalBank, (int) $suggestion->household_id);
                if (array_diff(array_map(fn ($receipt) => $receipt['source'].':'.$receipt['id'], $current), array_map(fn ($receipt) => $receipt['source'].':'.$receipt['id'], $existing))) {
                    throw ValidationException::withMessages(['match' => 'A similar payment was recorded since this preview. Reject this suggestion and run reconciliation again to avoid counting it twice.']);
                }
                if ($current && ! $acknowledgeExisting) {
                    throw ValidationException::withMessages(['match' => 'Review the possible existing payments and confirm this is a separate receipt before approving.']);
                }
                $paymentId = app(PaymentService::class)->record([
                    'household_id' => $suggestion->household_id, 'bank_transaction_id' => $bank->id, 'request_key' => $suggestion->request_key,
                    'amount_cents' => (int) $bank->amount_cents, 'paid_on' => $bank->posted_on,
                    'note' => 'Administrator-approved AI reconciliation suggestion #'.$id, 'allocations' => $allocations,
                ], $actor);
            }
            DB::table('reconciliation_suggestions')->where('id', $id)->update([
                'status' => $approve ? 'approved' : 'rejected', 'payment_id' => $paymentId,
                'reviewed_by' => $actor, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record($approve ? 'reconciliation.approved' : 'reconciliation.rejected', 'suggestion:'.$id, ['payment_id' => $paymentId], $actor);
        });
    }
}
