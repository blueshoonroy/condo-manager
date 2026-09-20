@extends('layouts.app')
@section('title', 'Resident directory')
@section('content')
<div class="page-heading"><div><span class="eyebrow">MEET YOUR NEIGHBORS</span><h1>The usual residents.</h1><p class="muted">Names, units, and a better option than yelling down the stairs.</p></div></div><div class="directory-grid">@foreach($households as $household)<section class="card resident-card"><span class="unit-number">{{ str_pad($household->unit_id, 2, '0', STR_PAD_LEFT) }}</span><span class="eyebrow">UNIT {{ $household->unit_id }}</span>@foreach($household->residents as $resident)<div class="resident"><h2>{{ $resident->name }}</h2><a href="mailto:{{ $resident->email }}">{{ $resident->email }}</a>@if($resident->phone)<p>{{ $resident->phone }}</p>@endif @if($resident->is_admin)<small class="positive">Association administrator</small>@endif</div>@endforeach</section>@endforeach</div>
@endsection
