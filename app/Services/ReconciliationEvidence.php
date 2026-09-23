<?php

namespace App\Services;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReconciliationEvidence
{
    public function enrich(array $snapshot): array
    {
        $invoices = Invoice::with('household.residents')->whereNull('void_reason')->get();
        $payments = DB::table('payments')->whereNull('reversed_at')->get();
        $allocations = DB::table('payment_allocations')->whereIn('payment_id', $payments->pluck('id'))->get();
        $snapshot['ledger_scope'] = 'Current complete household ledger, including paid invoices and receipts outside the selected bank date range. Allocations are part of receipts, not additional money. Historical imported payments have no bank link and may lack payment dates.';
        $snapshot['household_ledgers'] = [];
        $snapshot['recorded_receipts'] = [];
        foreach ($invoices->groupBy('household_id') as $householdId => $householdInvoices) {
            $household = $householdInvoices->first()->household;
            $householdPayments = $payments->where('household_id', $householdId);
            $allocated = (int) $allocations->whereIn('payment_id', $householdPayments->pluck('id'))->sum('amount_cents');
            $historical = (int) $householdInvoices->sum('historical_paid_cents');
            $snapshot['household_ledgers'][] = [
                'household_id' => (int) $householdId, 'household' => $household->client_name, 'unit' => $household->unit_id,
                'resident_names' => $household->residents->pluck('name')->all(),
                'invoice_total_cents' => (int) $householdInvoices->sum('total_cents'),
                'recorded_payment_total_cents' => $historical + (int) $householdPayments->sum('amount_cents'),
                'allocated_payment_cents' => $historical + $allocated,
                'unapplied_credit_cents' => (int) $householdPayments->sum('amount_cents') - $allocated,
                'outstanding_cents' => (int) $householdInvoices->sum(fn ($invoice) => $invoice->balanceCents()),
            ];
        }
        foreach ($payments->whereNull('bank_transaction_id') as $payment) {
            $snapshot['recorded_receipts'][] = [
                'source' => 'payment', 'id' => $payment->id, 'household_id' => $payment->household_id,
                'amount_cents' => (int) $payment->amount_cents, 'paid_on' => $payment->paid_on,
                'invoice_ids' => $allocations->where('payment_id', $payment->id)->pluck('invoice_id')->all(),
            ];
        }
        foreach ($invoices->where('historical_paid_cents', '>', 0) as $invoice) {
            $snapshot['recorded_receipts'][] = [
                'source' => 'historical_invoice', 'id' => $invoice->id, 'household_id' => $invoice->household_id,
                'amount_cents' => (int) $invoice->historical_paid_cents, 'paid_on' => $invoice->paid_on?->toDateString(),
                'issued_on' => $invoice->issued_on->toDateString(), 'invoice_ids' => [$invoice->id],
            ];
        }
        $relevantReceipts = [];
        foreach ($snapshot['transactions'] as &$bank) {
            $bank['possible_recorded_receipts'] = $this->possibleReceipts($snapshot, $bank);
            foreach ($bank['possible_recorded_receipts'] as $receipt) {
                $relevantReceipts[$receipt['source'].':'.$receipt['id']] = $receipt;
            }
            $bank['ranked_candidates'] = collect($snapshot['invoices'])
                ->filter(fn ($invoice) => $invoice['issued_on'] <= $bank['date'])
                ->map(function ($invoice) use ($snapshot, $bank) {
                    $evidence = $this->assess($snapshot, $bank, [$invoice['id'] => min($invoice['balance_cents'], $bank['amount_cents'])]);

                    return ['invoice_id' => $invoice['id'], ...$evidence];
                })->sortByDesc('score')->take(8)->values()->all();
        }
        unset($bank);
        $snapshot['recorded_receipts'] = array_values($relevantReceipts);

        return $snapshot;
    }

    public function possibleReceipts(array $snapshot, array $bank, ?int $householdId = null): array
    {
        return collect($snapshot['recorded_receipts'] ?? [])->filter(function ($receipt) use ($bank, $householdId) {
            if (($householdId !== null && $receipt['household_id'] != $householdId) || $receipt['amount_cents'] !== $bank['amount_cents']) {
                return false;
            }
            if ($receipt['paid_on']) {
                return abs($this->days($receipt['paid_on'], $bank['date'])) <= 7;
            }
            $days = $this->days($receipt['issued_on'], $bank['date']);

            return $days >= 0 && $days <= 45;
        })->values()->all();
    }

    /**
     * @param  array{id: int, date: string, description: string, amount_cents: int}  $bank
     * @param  array<int, int>  $allocations
     * @return array{exact_total: bool, days_after_issue: int, payer_name_match: bool, possible_recorded_receipts: array, confidence_cap: string, score: int}
     */
    public function assess(array $snapshot, array $bank, array $allocations): array
    {
        $invoices = collect($snapshot['invoices'])->whereIn('id', array_keys($allocations));
        $householdId = (int) $invoices->first()['household_id'];
        $days = (int) $invoices->max(fn ($invoice) => $this->days($invoice['issued_on'], $bank['date']));
        $exact = (int) $invoices->sum('balance_cents') === $bank['amount_cents'] && array_sum($allocations) === $bank['amount_cents'];
        $ledger = collect($snapshot['household_ledgers'] ?? [])->firstWhere('household_id', $householdId);
        $identity = false;
        foreach (array_merge($ledger['resident_names'] ?? [], [$ledger['household'] ?? '']) as $name) {
            if (mb_strlen($name) >= 4 && preg_match('/(?<!\pL)'.preg_quote($name, '/').'(?!\pL)/iu', $bank['description'])) {
                $identity = true;
            }
        }
        $receipts = $this->possibleReceipts($snapshot, $bank, $householdId);
        $score = max(0, ($exact ? 65 : 35) + ($identity ? 25 : 0) - min(60, $days));
        $confidence = $exact && $days <= 30 && $identity ? 'high' : ($days <= 60 ? 'medium' : 'low');
        if ($receipts || ($ledger['unapplied_credit_cents'] ?? 0) >= $bank['amount_cents']) {
            $confidence = 'low';
            $score = max(0, $score - 40);
        }

        return ['exact_total' => $exact, 'days_after_issue' => $days, 'payer_name_match' => $identity,
            'possible_recorded_receipts' => $receipts, 'confidence_cap' => $confidence, 'score' => $score];
    }

    private function days(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));
    }
}
