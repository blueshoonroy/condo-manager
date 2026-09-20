@extends('layouts.app')
@section('title', 'Review assessment')
@section('content')
<div class="page-heading"><div><a href="{{ route('admin') }}">Back to administration</a><h1>One assessment. Five shares.</h1><p class="muted">Review the split before creating invoices.</p></div></div>
<section class="card"><h2>{{ $data['title'] }}</h2><p>Total: <strong>{{ \App\Support\Money::format($total) }}</strong> · Due {{ $data['due_on'] }}</p>
<div class="table-wrap"><table><thead><tr><th>Unit</th><th>Household</th><th>Share</th><th class="numeric">Invoice amount</th></tr></thead><tbody>
@foreach($amounts as $unit => $amount)<tr><td>{{ $unit }}</td><td>{{ $households[$unit]->client_name }}</td><td>{{ config('association.assessment_percentages')[$unit] }}%</td><td class="numeric">{{ \App\Support\Money::format($amount) }}</td></tr>@endforeach
</tbody></table></div><p class="small muted">Any rounding pennies go to the largest fractional shares, with unit number breaking ties. The five invoices always equal the total.</p>
<p>{{ config('association.invoice_emails_enabled') ? 'An invoice email will be queued for each active resident.' : 'Invoice emails are currently disabled.' }}</p>
<form method="post" action="{{ route('admin.assessment.confirm') }}" data-confirm="Create all five assessment invoices?">@csrf<input type="hidden" name="request_key" value="{{ $requestKey }}"><button class="button">Create five invoices</button></form></section>
@endsection
