@extends('layouts.app')
@section('title', 'Bank connection')
@section('content')
<div class="page-heading"><div><a href="{{ route('admin') }}">Back to administration</a><h1>Less CSV. More automatic.</h1><p class="muted">Connect the association's BMO account with Plaid.</p></div></div>
@include('admin.tabs')
@if(config('services.plaid.environment') === 'sandbox')<div class="notice"><strong>Sandbox test mode.</strong> Test balances and transactions stay on this admin page. They never affect association finances or invoice reconciliation. Real BMO access requires Plaid Production credentials.</div>@endif
@if($error)<div class="notice error">{{ $error }}</div>@endif
<section class="card" id="plaid-connect" data-link-url="{{ route('admin.bank.link') }}" data-exchange-url="{{ route('admin.bank.exchange') }}" data-sync-url="{{ route('admin.bank.sync') }}" data-resume-token="{{ $resumeToken }}" data-resume-update="{{ $resumeUpdate ? '1' : '0' }}">
<h2>Association bank account</h2><p class="small muted">Only Roy can connect or disconnect the bank. The portal requests read access to balances and transactions, not payment transfers.</p>
<p id="plaid-message" role="status"></p>
@if(!$connection || !$connection->access_token)
<button class="button" type="button" data-plaid-open data-update="0">Connect bank with Plaid</button>
@else
<p><strong>{{ $connection->account_name ?? 'Choose an account below' }}</strong> {{ $connection->mask ? 'ending '.$connection->mask : '' }} · {{ str_replace('_', ' ', ucfirst($connection->status)) }}</p>
@if($connection->error_code)<div class="notice error">Bank sync needs attention: {{ $connection->error_code }}. Try refreshing, or reconnect if the bank needs you to sign in again.</div>@endif
@if($connection->account_id)<p class="small">Transaction handoff date: {{ $connection->starts_on }}. Last successful transaction sync: {{ $connection->synced_at ?? 'Not yet synced' }} UTC.</p><p class="small">Balance last retrieved: {{ $connection->balance_fetched_at ?? 'Not yet retrieved' }} UTC. Scheduled transaction sync: hourly. Fresh balance: every six hours.</p>
<form method="post" action="{{ route('admin.bank.sync') }}">@csrf<button class="button">Refresh transactions and balance</button></form>
<p><button class="button secondary" type="button" data-plaid-open data-update="1">Reconnect bank login</button></p>@endif
<form method="post" action="{{ route('admin.bank.disconnect') }}" data-confirm="Revoke Plaid bank access? Previously imported history will remain.">@csrf<button class="text-button">Disconnect bank</button></form>
@endif
</section>
@if($accounts)<section class="card"><h2>Choose the association account</h2><p>Choose the same USD account used for the existing BMO CSVs. Plaid owns transaction history from the handoff date onward; older CSV history stays in place.</p><p class="small muted">Existing bank history ends {{ $latest ?? 'before any imports' }}. Use the next calendar day to avoid overlap. Only one account is supported.</p>
<form method="post" action="{{ route('admin.bank.select') }}">@csrf<label>Account<select name="account_id">@foreach($accounts as $account)<option value="{{ $account['account_id'] }}">{{ $account['name'] }} · ending {{ $account['mask'] }} · {{ $account['subtype'] }}</option>@endforeach</select></label><label>First day managed by Plaid<input type="date" name="starts_on" value="{{ $latest ? \Carbon\Carbon::parse($latest)->addDay()->toDateString() : now('America/Chicago')->subDays(30)->toDateString() }}" required></label><button class="button">Use this account</button></form></section>@endif
@if($connection?->environment === 'sandbox' && $connection->balance_cents !== null)<section class="card"><h2>Sandbox balance: {{ \App\Support\Money::format($connection->balance_cents) }}</h2><p class="muted">Test data only. This is not the association's money.</p><div class="table-wrap"><table><thead><tr><th>Date</th><th>Description</th><th>Status</th><th>Amount</th></tr></thead><tbody>@foreach($sandboxTransactions as $transaction)<tr><td>{{ $transaction->posted_on }}</td><td>{{ $transaction->description }}</td><td>{{ $transaction->pending ? 'Pending' : 'Posted' }}</td><td>{{ \App\Support\Money::format($transaction->amount_cents) }}</td></tr>@endforeach</tbody></table></div></section>@endif
@if($reviews->count())<section class="card"><h2>Bank changes needing review</h2><p class="muted">The bank changed or removed a receipt already linked to a payment. Invoice allocations were preserved. Review the payment and reverse it in Administration if needed.</p>@foreach($reviews as $transaction)<p>{{ $transaction->posted_on }} · {{ $transaction->description }} · {{ \App\Support\Money::format($transaction->amount_cents) }} {{ $transaction->removed_at ? '(removed by bank)' : '' }}</p>@endforeach</section>@endif
@endsection
