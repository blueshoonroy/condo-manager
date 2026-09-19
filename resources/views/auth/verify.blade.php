@extends('layouts.app')
@section('title', 'Check your email')
@section('content')
<section class="card verify-card"><span class="brand-mark">1262</span><h1>Check your inbox.</h1><p class="muted">Enter the six-digit code from your email. It expires after 10 minutes.</p><form method="post" action="{{ route('login.check') }}">@csrf<label>Sign-in code<input class="code-input" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus></label><button class="button full">Sign in →</button></form><p><a href="{{ route('login') }}">Request a new code</a></p></section>
@endsection
