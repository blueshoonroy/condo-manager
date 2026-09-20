@extends('layouts.app')
@section('title', 'Building service settings')
@section('content')
<div class="page-heading"><div><h1>Administration</h1><p class="muted">Building services and shared account details.</p></div></div>
@include('admin.tabs')
<div class="section-heading"><h2>Building services</h2><a class="button" href="{{ route('admin.services.new') }}">Add a service</a></div>
<p class="muted">All active residents can view these entries. Only you can edit them. <a href="{{ route('services') }}">View resident page &rarr;</a></p>
@forelse($services as $service)
<section class="card"><div class="section-heading"><div><span class="eyebrow">{{ $service['category'] }}</span><h3>{{ $service['name'] }}</h3></div><a class="button secondary" href="{{ route('admin.services.edit', $service['id']) }}">Edit</a></div><p class="small muted">{{ $service['contact'] ?: 'No contact listed' }} &middot; {{ $service['password'] ? 'Password saved' : 'No password saved' }}</p></section>
@empty
<section class="card empty">No services added yet. Add a utility or vendor to get started.</section>
@endforelse
@endsection
