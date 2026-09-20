@extends('layouts.app')
@section('title', 'Review invoice emails')
@section('content')
<div class="page-heading"><div><a href="{{ route('invoices') }}">Back to invoices</a><h1>Ready for their inboxes?</h1><p class="muted">{{ $invoices->count() }} invoices selected. Each active unit contact receives a separate email for each invoice.</p></div></div>
<section class="card"><h2>Review recipients</h2><div class="table-wrap"><table class="invoice-table"><thead><tr><th>Invoice</th><th>Unit</th><th>Recipients</th><th>Remaining</th></tr></thead><tbody>
@foreach($invoices as $invoice)<tr><td>#{{ $invoice->number }}<small>{{ ucfirst($invoice->status()) }}</small></td><td>{{ $invoice->unit_id }}</td><td>@foreach($invoice->household->residents as $resident)<div>{{ $resident->name }} <small>{{ $resident->email }}</small></div>@endforeach</td><td>{{ \App\Support\Money::format($invoice->balanceCents()) }}</td></tr>@endforeach
</tbody></table></div><p class="small muted">Previously sent invoices will be sent again. Pending deliveries and deliveries requiring review are skipped to avoid duplicates. Paid invoices clearly show that no payment is needed.</p>
<form method="post" action="{{ route('admin.invoice-emails.send') }}">@csrf<input type="hidden" name="request_key" value="{{ $requestKey }}"><button class="button">Send invoice emails</button> <a href="{{ route('invoices') }}">Cancel</a></form></section>
<section class="card"><h2>Email preview</h2><p class="small muted">Example for the first invoice and recipient. Amounts and names are personalized for every email.</p><iframe title="Invoice email preview" sandbox="" srcdoc="{{ $emailPreview }}" style="width:100%;height:850px;border:1px solid #e3e8e1;border-radius:10px"></iframe></section>
@endsection
