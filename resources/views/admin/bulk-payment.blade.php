@extends('layouts.app')
@section('title', 'Mark invoices paid')
@section('content')
<div class="page-heading"><div><a href="{{ route('invoices') }}">Back to invoices</a><h1>Mark {{ $invoices->count() }} {{ Str::plural('invoice', $invoices->count()) }} as paid</h1><p class="muted">One payment is recorded per invoice for its remaining balance. The method, date and note below apply to every row unless a bank deposit sets the date for that row.</p></div></div>
@if($skipped->isNotEmpty())<div class="notice">Skipped because already paid or void: @foreach($skipped as $invoice)#{{ $invoice->number }}@if(!$loop->last), @endif @endforeach</div>@endif
@if($errors->any())<div class="notice error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($invoices->isEmpty())<section class="card"><p>None of the selected invoices has a remaining balance.</p><a class="button secondary" href="{{ route('invoices') }}">Back to invoices</a></section>@else
<form class="card" method="post" action="{{ route('admin.invoices.bulk-pay') }}" data-confirm="Record a payment for every invoice listed? Each invoice balance will be marked received.">@csrf
<div class="form-row"><label>Payment method<select name="payment_method" required><option value="">Choose a method</option>@foreach(\App\Services\PaymentService::METHODS as $value => $label)<option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>@endforeach</select></label><label>Payment received on<input type="date" name="paid_on" value="{{ old('paid_on', now('America/Chicago')->toDateString()) }}" max="{{ now('America/Chicago')->toDateString() }}" required></label></div>
<label>Payment reference / note (optional)<input name="note" value="{{ old('note') }}" placeholder="e.g. October Zelle batch" maxlength="1000"></label>
<h2>Invoices</h2><p class="small muted">Optionally tie each payment to an unrecorded bank deposit for the same amount. A deposit can only be used once, and its posted date becomes that payment's date.</p>
<div class="table-wrap"><table class="invoice-table"><thead><tr><th>Invoice</th><th>Household</th><th>Due</th><th class="numeric">Remaining</th><th>Bank deposit (optional)</th></tr></thead><tbody>
@foreach($invoices as $index => $invoice)@php($matching = $credits->where('amount_cents', $invoice->balanceCents()))
<tr><td><a class="strong" href="{{ route('invoice', $invoice) }}">#{{ $invoice->number }}</a><small>{{ $invoice->items[0]['name'] ?? 'Association invoice' }}</small><input type="hidden" name="payments[{{ $index }}][invoice_id]" value="{{ $invoice->id }}"><input type="hidden" name="payments[{{ $index }}][expected_balance]" value="{{ $invoice->balanceCents() }}"><input type="hidden" name="payments[{{ $index }}][request_key]" value="{{ old('payments.'.$index.'.request_key', (string) Str::uuid()) }}"></td><td>{{ $invoice->household->client_name }}<small>Unit {{ $invoice->unit_id }}</small></td><td>{{ $invoice->due_on->format('M j, Y') }}</td><td class="numeric strong">{{ \App\Support\Money::format($invoice->balanceCents()) }}</td><td>@if($matching->isEmpty())<span class="small muted">No matching deposit</span>@else<label class="sr-only" for="deposit-{{ $invoice->id }}">Bank deposit for invoice {{ $invoice->number }}</label><select id="deposit-{{ $invoice->id }}" name="payments[{{ $index }}][bank_transaction_id]" data-bulk-deposit><option value="">Manual record</option>@foreach($matching as $credit)<option value="{{ $credit->id }}" @selected(old('payments.'.$index.'.bank_transaction_id') == $credit->id)>{{ $credit->posted_on }} · {{ \App\Support\Money::format($credit->amount_cents) }} · {{ Str::limit($credit->description, 40) }}</option>@endforeach</select>@endif</td></tr>
@endforeach
</tbody></table></div>
<p><strong>Total to record: {{ \App\Support\Money::format($invoices->sum(fn ($invoice) => $invoice->balanceCents())) }}</strong></p>
<button class="button">Record {{ $invoices->count() }} {{ Str::plural('payment', $invoices->count()) }}</button> <a href="{{ route('invoices') }}">Cancel</a>
</form>
@endif
@endsection
