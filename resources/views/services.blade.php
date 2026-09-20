@extends('layouts.app')
@section('title', 'Building services')
@section('content')
<div class="page-heading"><div><span class="eyebrow">THE PEOPLE WHO KEEP THINGS WORKING</span><h1>Building services</h1><p class="muted">Utilities, vendors, and the passwords nobody remembers.</p></div>@if(auth()->user()->is_admin)<a class="button secondary" href="{{ route('admin.services') }}">Edit services</a>@endif</div>
<p class="small muted">Shared association accounts, available to signed-in residents. Keep these details within the building.</p>
@forelse($services->groupBy('category') as $category => $entries)
<h2>{{ $category }}</h2>
<div class="directory-grid service-grid">
@foreach($entries as $service)
<section class="card service-card">
    <h3>{{ $service['name'] }}</h3>
    @if($service['website'])<p><a href="{{ $service['website'] }}" target="_blank" rel="noopener noreferrer">Open website &rarr;</a></p>@endif
    <dl class="service-details">
    @foreach(['contact' => 'Contact', 'phone' => 'Phone', 'email' => 'Email', 'account' => 'Account number', 'username' => 'Login / username'] as $field => $label)
        @if($service[$field])<dt>{{ $label }}</dt><dd>{{ $service[$field] }}</dd>@endif
    @endforeach
    </dl>
    @if($service['password'])<details class="service-password no-print"><summary>Show password</summary><code>{{ $service['password'] }}</code></details>@endif
    @if($service['notes'])<p class="service-notes">{{ $service['notes'] }}</p>@endif
</section>
@endforeach
</div>
@empty
<section class="card empty">The building's little black book is waiting for its first entry. Roy can add utilities and vendors in Administration &rarr; Building services.</section>
@endforelse
@endsection
