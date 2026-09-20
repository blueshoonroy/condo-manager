@php
    $currentTab = request()->routeIs('admin.services*') ? 'services' : (request()->routeIs('admin.bank*') ? 'bank' : (request()->routeIs('admin.reconciliation*', 'admin.ai.*') ? 'ai' : ($tab ?? request()->query('tab', 'payments'))));
    $settingsTabs = ['payments' => 'Payments', 'billing' => 'Dues & assessments', 'residents' => 'Residents', 'services' => 'Building services', 'bank' => 'Bank connection', 'ai' => 'AI reconciliation', 'imports' => 'CSV imports', 'activity' => 'Activity'];
@endphp
<nav class="settings-tabs" aria-label="Administration sections">
@foreach($settingsTabs as $key => $label)
    @php($destination = match ($key) { 'services' => route('admin.services'), 'bank' => route('admin.bank'), 'ai' => route('admin.reconciliation'), default => route('admin', ['tab' => $key]) })
    <a href="{{ $destination }}" @if($currentTab === $key) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>
