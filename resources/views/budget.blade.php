@extends('layouts.app')
@section('title', 'Budget')
@section('content')
<div class="budget-report">
<div class="page-heading"><div><span class="eyebrow">THE NUMBERS HAVE ENTERED THE CHAT</span><h1>Building budget</h1><p class="muted">What we planned. What we spent. Where the dollars went.</p></div>@if($workbook)<button class="button secondary no-print" data-print>Print report</button>@endif</div>
@if($workbook)
<nav class="settings-tabs no-print" aria-label="Budget year">@foreach($years as $option)<a href="{{ route('budget', ['year' => $option, 'workbook' => $workbook->id]) }}" @if($option === $year) aria-current="page" @endif>{{ $option }}</a>@endforeach</nav>
<div class="budget-intro"><div><h2>{{ $year }} annual report</h2><p class="small muted">Imported from {{ $workbook->filename }}. Historical workbook figures, including any incomplete months; this report does not update from the live bank feed. A dash means blank in the source, not a confirmed zero.</p></div><a class="button secondary no-print" href="{{ route('budget.download', $workbook->id) }}">Original workbook ↓</a></div>
<div class="budget-summary">
@foreach([['Planned income', 'budget_income'], ['Planned expenses', 'budget_expenses'], ['Planned surplus / deficit', 'budget_net'], ['Recorded income', 'actual_income'], ['Reported expenses', 'actual_expenses'], ['Income less expenses', 'actual_net']] as [$label, $key])
<section class="stat {{ str_ends_with($key, 'net') ? 'accent' : '' }}"><span>{{ $label }}</span><strong>{{ $formatter->money($report['summary'][$key]) }}</strong><small>{{ str_starts_with($key, 'budget') ? 'Annual budget' : 'Recorded figures; may be a partial year' }}</small></section>
@endforeach
</div>
@if($report['warnings'])<details class="card budget-checks" open><summary>Notes about the source figures ({{ count($report['warnings']) }})</summary><ul>@foreach($report['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></details>@endif
<section class="card"><div class="section-heading"><h2>Monthly income & expenses</h2><span class="small muted">Income includes special assessments</span></div>
@php($maximum = max(1, ...array_column($report['monthly'], 'income'), ...array_column($report['monthly'], 'expenses')))
<div class="budget-chart" role="img" aria-label="Monthly income and expenses; exact values are listed in the report tables below.">
@foreach($report['monthly'] as $month)<div class="budget-chart-month"><div class="budget-bars"><span class="budget-bar income" style="height:{{ max(0, $month['income']) / $maximum * 100 }}%" title="{{ $month['month'] }} income: {{ $formatter->money($month['income']) }}"></span><span class="budget-bar expenses" style="height:{{ max(0, $month['expenses']) / $maximum * 100 }}%" title="{{ $month['month'] }} expenses: {{ $formatter->money($month['expenses']) }}"></span></div><span>{{ $month['month'] }}</span></div>@endforeach
</div><p class="small budget-legend"><span>● Income</span><span>● Expenses</span></p></section>
@foreach($report['sections'] as $section)
@php($extraColumns = collect(['O', 'P'])->filter(fn ($column) => isset($section['header']['cells'][$column]) || collect($section['rows'])->contains(fn ($row) => isset($row['cells'][$column])))->all())
<section class="card budget-section"><div class="section-heading"><h2>{{ $section['title'] }}</h2><span class="small muted">{{ $year }} · USD</span></div>
@foreach(['O', 'P'] as $extra)@if(($section['header']['cells'][$extra]['numeric'] ?? false))<p class="small muted">Source heading detail ({{ $extra }}{{ $section['header']['number'] }}): {{ $formatter->money((float) $section['header']['cells'][$extra]['value']) }}</p>@endif @endforeach
<div class="table-wrap budget-table-wrap" tabindex="0" aria-label="{{ $section['title'] }} monthly table, scroll horizontally for all months"><table class="budget-table"><thead><tr><th scope="col">Category</th>@foreach(\App\Services\BudgetReport::MONTHS as $month)<th scope="col" class="numeric">{{ $month }}</th>@endforeach<th scope="col" class="numeric">Total</th>
@foreach($extraColumns as $extra)<th scope="col" class="numeric">{{ !($section['header']['cells'][$extra]['numeric'] ?? false) ? ($section['header']['cells'][$extra]['value'] ?? 'Source '.$extra) : 'Source '.$extra }}</th>@endforeach
</tr></thead><tbody>
@foreach($section['rows'] as $row)
@php($label = trim($row['cells']['A']['value'] ?? ''))
@php($total = str_starts_with($label, 'Total') || $label === 'Surplus/Deficit' || ($label === '' && isset($row['cells']['N'])))
<tr @class(['budget-total' => $total])><th scope="row">{{ $label ?: ($total ? 'Total' : 'Source row '.$row['number']) }}</th>
@foreach(array_merge(array_keys(\App\Services\BudgetReport::MONTHS), ['N'], $extraColumns) as $column)
@php($cell = $row['cells'][$column] ?? null)
<td class="numeric" @if($cell && $cell['formula']) title="Source {{ $column }}{{ $row['number'] }}: ={{ $cell['formula'] }}" @endif>{{ $cell ? ($cell['numeric'] ? $formatter->money((float) $cell['value']) : ($cell['value'] ?: '—')) : '—' }}</td>
@endforeach</tr>
@endforeach
</tbody></table></div></section>
@endforeach
<section class="card"><h2>Notes & historical balances</h2><p class="small muted">These are workbook notes and balances without a verified as-of date. See Association finances for the current bank balance. Original proportions and calculations below are historical notes, not the current assessment split.</p>
<div class="budget-notes">@foreach($report['notes'] as $row)<div class="budget-note">@foreach($row['cells'] as $column => $cell)<span title="Source {{ $column }}{{ $row['number'] }}">{{ $cell['value'] }}</span>@endforeach</div>@endforeach</div></section>
@if($transactions)<section class="card"><details><summary>Supporting bank records · {{ count($transactions['rows']) - 1 }} transactions</summary><p class="small muted">As supplied in “{{ $transactions['name'] }}”. These supporting records are separate from the live bank ledger and may cover only part of the year.</p><div class="table-wrap" tabindex="0"><table><thead><tr>@foreach($transactions['rows'][0]['cells'] as $cell)<th>{{ $cell['value'] }}</th>@endforeach</tr></thead><tbody>
@foreach(array_slice($transactions['rows'], 1) as $row)<tr>@foreach(array_keys($transactions['rows'][0]['cells']) as $column)@php($cell = $row['cells'][$column] ?? null)<td>{{ $cell ? ($column === 'C' && $cell['numeric'] ? $formatter->money((float) $cell['value']) : $cell['value']) : '—' }}</td>@endforeach</tr>@endforeach
</tbody></table></div></details></section>@endif
@else<section class="card"><h2>A home for the spreadsheet.</h2><p>Import the association’s budget workbook to see annual budgets, monthly actuals, income, and notes here.</p></section>@endif
@if(auth()->user()->is_admin && !session('impersonated_user_id'))<section class="card no-print"><details @if(!$workbook) open @endif><summary>Import a budget workbook</summary><p class="muted">Upload the association’s .xlsx workbook (up to 5 MB). Each import is saved separately, so earlier reports remain available. Importing the same file twice keeps one copy.</p><form method="post" action="{{ route('admin.budget.import') }}" enctype="multipart/form-data">@csrf<label>Excel workbook<input type="file" name="workbook" accept=".xlsx" required></label><button class="button">Import workbook</button></form></details></section>@endif
@if($workbooks->count() > 1)<section class="card no-print"><h2>Import history</h2>@foreach($workbooks as $previous)<p><a href="{{ route('budget', ['workbook' => $previous->id]) }}">{{ $previous->filename }}</a> <span class="small muted">{{ $previous->created_at }}</span></p>@endforeach</section>@endif
</div>
@endsection
