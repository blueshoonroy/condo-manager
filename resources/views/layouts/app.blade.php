<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>@yield('title', 'Welcome') · 1262 Bryn Mawr</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
@auth
<aside class="sidebar">
    <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">1262</span><span>Bryn Mawr<small>ASSOCIATION</small></span></a>
    <div class="nav-label">YOUR COMMUNITY</div>
    <nav aria-label="Main navigation">
        @foreach(['dashboard' => ['◫', 'Overview'], 'invoices' => ['▤', 'Invoices'], 'finances' => ['↗', 'Association finances'], 'directory' => ['◎', 'Resident directory'], 'documents' => ['▱', 'Documents']] as $route => [$icon, $label])
        <a href="{{ route($route) }}" class="{{ request()->routeIs($route, $route === 'invoices' ? 'invoice' : $route) ? 'selected' : '' }}"><span aria-hidden="true">{{ $icon }}</span>{{ $label }}</a>
        @endforeach
        @if(auth()->user()->is_admin)<div class="nav-label">MANAGEMENT</div><a class="{{ request()->routeIs('admin*') ? 'selected' : '' }}" href="{{ route('admin') }}"><span aria-hidden="true">⚙</span>Administration</a>@endif
    </nav>
    <div class="sidebar-bottom"><span class="eyebrow">A PLACE TO CALL HOME</span><p>1262 W. Bryn Mawr Ave<br>Chicago, IL 60660</p><span class="small">Five homes. One community.</span></div>
</aside>
<div class="shell">
    <header class="topbar"><span class="small">1262 Bryn Mawr Association <span class="muted">/ Resident portal</span></span><div class="account"><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span>{{ auth()->user()->name }}<small>Unit {{ auth()->user()->household?->unit_id }}{{ auth()->user()->is_admin ? ' · Administrator' : '' }}</small></span><form method="post" action="{{ route('logout') }}">@csrf<button class="text-button">Sign out</button></form></div></header>
@else
<div class="guest-shell">
@endauth
<main id="main">
    @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice error" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
@auth<footer>1262 Bryn Mawr Association <span>Chicago, Illinois</span></footer>@endauth
</div>
</body>
</html>
