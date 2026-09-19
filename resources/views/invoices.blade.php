@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
<div class="page-heading"><div><span class="eyebrow">DUES & ASSESSMENTS</span><h1>{{ auth()->user()->is_admin ? 'Association invoices' : 'Your invoices' }}</h1><p class="muted">A clear record of charges, payments, and what’s still due.</p></div></div>
<section class="card"><form class="filters" method="get"><label>Invoice number<input name="q" value="{{ request('q') }}" placeholder="Search invoice number"></label><label>Status<select name="status"><option value="">All statuses</option>@foreach(['outstanding','overdue','paid','void'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label><button class="button">Filter</button><a href="{{ route('invoices') }}">Reset</a></form>@include('partials.invoices')</section>
@endsection
