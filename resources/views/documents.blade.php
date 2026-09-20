@extends('layouts.app')
@section('title', 'Documents')
@section('content')
<div class="page-heading"><div><span class="eyebrow">YES, WE HAVE PAPERWORK</span><h1>Association documents</h1><p class="muted">The important stuff. Sadly, none of it is a takeout menu.</p></div></div><section class="card documents-card"><div class="document-icon" aria-hidden="true">▤</div><h2>The filing cabinet, minus the cabinet.</h2><p class="muted">Find the association’s important documents in our shared Google Drive folder.</p><a class="button" href="{{ config('association.documents_url') }}" target="_blank" rel="noopener noreferrer">Open association folder ↗</a><p class="small muted">Opens Google Drive in a new tab. You may need to sign into your Google account or request folder access.</p></section>
@endsection
