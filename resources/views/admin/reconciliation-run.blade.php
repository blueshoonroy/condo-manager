@extends('layouts.app')
@section('title', 'Reconciliation review')
@section('content')
<div class="page-heading"><div><a href="{{ route('admin.reconciliation') }}">Back to reconciliation</a><h1>Review suggested matches</h1><p class="muted">Run #{{ $run->id }} · {{ ucfirst($run->provider) }} · {{ $run->from_date }} to {{ $run->to_date }}</p></div></div>
@if(in_array($run->status, ['queued', 'processing']))<div class="notice">{{ $run->status === 'queued' ? 'Waiting for the queue worker.' : 'Looking for matches.' }} <a href="{{ route('admin.reconciliation.run', $run->id) }}">Refresh results</a>. If this takes over 15 minutes, start a new run.</div>
@elseif($run->status === 'failed')<div class="notice error">{{ $run->error }}</div>
@else<div class="notice">{{ $suggestions->count() }} suggestions from {{ count($snapshot['transactions']) }} bank credits and {{ count($snapshot['invoices']) }} open invoices. {{ $run->skipped }} invalid suggestions discarded. No payment is recorded until you approve it.</div>@endif
@foreach($suggestions as $suggestion)
@php($bank = $banks[$suggestion->bank_transaction_id])
@php($allocations = json_decode($suggestion->allocations, true))
@php($evidence = app(\App\Services\ReconciliationEvidence::class)->assess($snapshot, $bank, $allocations))
@php($ledger = collect($snapshot['household_ledgers'] ?? [])->firstWhere('household_id', $suggestion->household_id))
<section class="card"><div class="section-heading"><h2>{{ \App\Support\Money::format($bank['amount_cents']) }} · {{ $bank['date'] }}</h2><span class="badge neutral">{{ ucfirst($suggestion->status) }}</span></div><p>{{ $bank['description'] }}</p><p class="small muted">AI confidence: {{ $suggestion->confidence }} (not a guarantee). {{ $suggestion->reason }}</p>
<p><strong>{{ $evidence['exact_total'] ? 'Exact total match' : 'Partial payment or excess credit' }}</strong> · Payment arrived {{ $evidence['days_after_issue'] }} days after {{ count($allocations) > 1 ? 'the oldest invoice was' : 'the invoice was' }} issued.</p>
@if($ledger)<p class="small muted">Household ledger at preview: {{ \App\Support\Money::format($ledger['invoice_total_cents']) }} invoiced · {{ \App\Support\Money::format($ledger['recorded_payment_total_cents']) }} payments recorded · {{ \App\Support\Money::format($ledger['outstanding_cents']) }} outstanding · {{ \App\Support\Money::format($ledger['unapplied_credit_cents']) }} unused credit. These totals include history outside this bank date range.</p>@endif
@if($evidence['possible_recorded_receipts'])<div class="notice error"><strong>Possible payment already recorded.</strong> The following records have the same amount and nearby dates. Check before counting this receipt again.<ul>
@foreach($evidence['possible_recorded_receipts'] as $receipt)<li>{{ $receipt['source'] === 'payment' ? 'Payment' : 'Imported payment on invoice' }} #{{ $receipt['id'] }} · {{ \App\Support\Money::format($receipt['amount_cents']) }} · {{ $receipt['paid_on'] ?? 'Payment date unknown; invoice issued '.$receipt['issued_on'] }}</li>@endforeach
</ul></div>@endif
<div class="table-wrap"><table><thead><tr><th>Invoice / household</th><th>Issued / due</th><th class="numeric">Current balance</th><th class="numeric">Apply</th></tr></thead><tbody>
@foreach($allocations as $invoiceId => $amount)@php($invoice = $invoices[$invoiceId])<tr><td><a href="{{ route('invoice', $invoiceId) }}">#{{ $invoice['number'] }}</a><small>{{ $invoice['household'] }} · Unit {{ $invoice['unit'] }}</small></td><td>{{ $invoice['issued_on'] }} / {{ $invoice['due_on'] }}</td><td class="numeric">{{ \App\Support\Money::format($currentInvoices->get($invoiceId)?->balanceCents() ?? 0) }}</td><td class="numeric">{{ \App\Support\Money::format($amount) }}</td></tr>@endforeach
</tbody></table></div>
@if($bank['amount_cents'] > array_sum($allocations))<p><strong>{{ \App\Support\Money::format($bank['amount_cents'] - array_sum($allocations)) }} will remain unapplied household credit.</strong></p>@endif
@if($suggestion->status === 'pending')<form method="post" action="{{ route('admin.reconciliation.review', $suggestion->id) }}">@csrf
@if($evidence['possible_recorded_receipts'])<p><label><input type="checkbox" name="acknowledge_existing" value="1"> I checked the existing records; this is a separate payment.</label></p>@endif
<button class="button" name="decision" value="approve" data-confirm="Record this bank receipt and apply the listed amounts?">Approve and record payment</button> <button class="button secondary" name="decision" value="reject">Reject suggestion</button></form><p class="small muted">Need different allocations? Reject this suggestion and <a href="{{ route('admin.payment', $suggestion->household_id) }}">record the payment manually</a>.</p>
@elseif($suggestion->payment_id)<p class="small">Payment #{{ $suggestion->payment_id }} recorded. Use Administration to review allocations or reverse a mistake.</p>@endif
</section>@endforeach
@php($unmatchedWithHistory = collect($snapshot['transactions'])->whereNotIn('id', $suggestions->pluck('bank_transaction_id'))->filter(fn ($transaction) => !empty($transaction['possible_recorded_receipts'])))
@if($unmatchedWithHistory->isNotEmpty())
<section class="card"><h2>Receipts to check against existing payments</h2><p class="muted">These unmatched deposits resemble payments already in the ledger. Amounts and nearby dates are clues, not proof; check the payer before making a new payment.</p>
@foreach($unmatchedWithHistory as $transaction)
<details><summary>{{ \App\Support\Money::format($transaction['amount_cents']) }} · {{ $transaction['date'] }} · {{ $transaction['description'] }}</summary><ul>
@foreach($transaction['possible_recorded_receipts'] as $receipt)
@php($receiptHousehold = collect($snapshot['household_ledgers'])->firstWhere('household_id', $receipt['household_id']))
<li>{{ $receiptHousehold['household'] ?? 'Household #'.$receipt['household_id'] }} · {{ $receipt['source'] === 'payment' ? 'Payment' : 'Imported payment on invoice' }} #{{ $receipt['id'] }} · {{ $receipt['paid_on'] ?? 'Payment date unknown; invoice issued '.$receipt['issued_on'] }}</li>
@endforeach
</ul></details>
@endforeach
</section>
@endif
@if($run->status === 'completed' && !$suggestions->count())<section class="card"><h2>No convincing matches this time.</h2><p class="muted">Try a different date range or record a payment manually. No invoice balances changed.</p></section>@endif
@endsection
