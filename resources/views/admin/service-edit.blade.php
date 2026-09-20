@extends('layouts.app')
@section('title', $service ? 'Edit building service' : 'Add building service')
@section('content')
<div class="page-heading"><div><h1>{{ $service ? 'Edit building service' : 'Add building service' }}</h1><p class="muted">One less hunt through the group chat.</p></div></div>
@include('admin.tabs')
<section class="card">
<form method="post" action="{{ $service ? route('admin.services.update', $service['id']) : route('admin.services.create') }}" autocomplete="off">
@csrf
<div class="form-row"><label>Service / company name<input name="name" value="{{ old('name', $service['name'] ?? '') }}" required maxlength="200"></label><label>Category<input name="category" list="service-categories" value="{{ old('category', $service['category'] ?? 'Utilities') }}" required maxlength="100"><datalist id="service-categories"><option value="Utilities"><option value="Maintenance"><option value="Insurance"><option value="Other vendors"></datalist></label></div>
<div class="form-row"><label>Contact person<input name="contact" value="{{ old('contact', $service['contact'] ?? '') }}" maxlength="255"></label><label>Phone / extension<input name="phone" value="{{ old('phone', $service['phone'] ?? '') }}" maxlength="100"></label></div>
<div class="form-row"><label>Contact email<input type="email" name="email" value="{{ old('email', $service['email'] ?? '') }}" maxlength="254"></label><label>Website / login URL<input type="url" name="website" value="{{ old('website', $service['website'] ?? '') }}" placeholder="https://" maxlength="2000"></label></div>
<div class="form-row"><label>Account number<input name="account" value="{{ $service['account'] ?? '' }}" maxlength="500"></label><label>Login / username<input name="username" value="{{ $service['username'] ?? '' }}" maxlength="500" autocomplete="off"></label></div>
<label>{{ $service && $service['password'] ? 'Replace password (leave blank to keep it)' : 'Password' }}<input type="password" name="service_password" autocomplete="new-password" maxlength="2000"></label>
@if($service && $service['password'])<label><span><input type="checkbox" name="clear_password" value="1"> Remove the saved password</span></label>@endif
<label>Notes / service details<textarea name="notes" rows="5" maxlength="10000">{{ $service['notes'] ?? '' }}</textarea></label>
<p class="small muted">Account details are shared with active residents. Passwords are encrypted in storage. After a validation error, re-enter changes to account details and notes before saving.</p>
<button class="button">Save service</button> <a href="{{ route('admin.services') }}">Cancel</a>
</form>
</section>
@if($service)<form method="post" action="{{ route('admin.services.delete', $service['id']) }}" data-confirm="Remove this service and its shared login details?">@csrf @method('DELETE')<button class="button danger">Delete service</button></form>@endif
@endsection
