<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class LiveBudgetReport
{
    public function build(int $year): array
    {
        $entries = app(BudgetCategorizer::class)->entries($year);
        $targets = DB::table('budget_targets')->where('year', $year)->pluck('annual_cents', 'category');
        $rows = [];
        foreach (BudgetCategorizer::CATEGORIES + ['unknown_income' => ['Uncategorized money in', 'income'], 'unknown_expense' => ['Uncategorized money out', 'expense']] as $key => [$label, $type]) {
            if ($type !== 'transfer') {
                $rows[$key] = ['label' => $label, 'type' => $type, 'months' => array_fill(1, 12, 0), 'total' => 0, 'target' => $targets->has($key) ? (int) $targets[$key] : null];
            }
        }
        $transfers = ['in' => 0, 'out' => 0];
        $gross = ['in' => 0, 'out' => 0];
        foreach ($entries as $entry) {
            $bank = $entry['bank'];
            $amount = (int) $bank->amount_cents;
            $direction = $amount >= 0 ? 'in' : 'out';
            $gross[$direction] += abs($amount);
            if ($entry['category'] === 'transfer') {
                $transfers[$direction] += abs($amount);

                continue;
            }
            $category = $entry['category'] ?? ($amount >= 0 ? 'unknown_income' : 'unknown_expense');
            $month = (int) substr($bank->posted_on, 5, 2);
            $value = $rows[$category]['type'] === 'income' ? $amount : -$amount;
            $rows[$category]['months'][$month] += $value;
            $rows[$category]['total'] += $value;
        }
        $income = collect($rows)->where('type', 'income')->sum('total');
        $expenses = collect($rows)->where('type', 'expense')->sum('total');
        $invoices = Invoice::whereNull('void_reason')->whereBetween('issued_on', [$year.'-01-01', $year.'-12-31'])->get();
        $invoiceMonths = array_fill(1, 12, 0);
        $units = [];
        foreach ($invoices as $invoice) {
            $invoiceMonths[(int) $invoice->issued_on->format('n')] += $invoice->total_cents;
            $unit = $invoice->unit_id;
            $units[$unit] ??= ['billed' => 0, 'outstanding' => 0];
            $units[$unit]['billed'] += $invoice->total_cents;
            $units[$unit]['outstanding'] += $invoice->balanceCents();
        }
        ksort($units);
        $monthly = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthly[$month] = ['billed' => $invoiceMonths[$month], 'income' => 0, 'expenses' => 0];
            foreach ($rows as $row) {
                $monthly[$month][$row['type'] === 'income' ? 'income' : 'expenses'] += $row['months'][$month];
            }
        }
        $unlinkedPayments = (int) DB::table('payments')->whereNull('reversed_at')->whereNull('bank_transaction_id')->whereBetween('paid_on', [$year.'-01-01', $year.'-12-31'])->sum('amount_cents');

        return compact('rows', 'income', 'expenses', 'gross', 'transfers', 'monthly', 'units', 'unlinkedPayments') + [
            'billed' => (int) $invoices->sum('total_cents'), 'outstanding' => (int) collect($units)->sum('outstanding'),
            'net' => $income - $expenses, 'needs_review' => $entries->whereNull('category')->count(),
            'transaction_count' => $entries->count(), 'first_date' => $entries->min('bank.posted_on'), 'last_date' => $entries->max('bank.posted_on'),
            'planned_expenses' => (int) $targets->sum(), 'has_plan' => $targets->isNotEmpty(),
        ];
    }

    public function draftTargets(int $year): array
    {
        $targets = DB::table('budget_targets')->where('year', $year)->pluck('annual_cents', 'category')->all();
        if ($targets) {
            return ['targets' => $targets, 'source' => 'Saved '.$year.' expense plan'];
        }
        $workbook = DB::table('budget_workbooks')->latest('id')->first();
        $sheet = $workbook ? collect(json_decode($workbook->sheets, true))->where('kind', 'budget')->where('year', '<', $year)->sortByDesc('year')->first() : null;
        if ($sheet) {
            $report = app(BudgetReport::class)->build($sheet);
            foreach ($report['sections'][0]['rows'] as $row) {
                $label = strtolower(trim($row['cells']['A']['value'] ?? ''));
                $category = match (true) {
                    $label === 'comed' => 'electricity', str_contains($label, 'water') => 'water',
                    str_contains($label, 'cleaning') => 'cleaning', str_contains($label, 'lawn') => 'landscaping',
                    str_contains($label, 'gas') => 'gas', str_contains($label, 'insurance') => 'insurance',
                    str_contains($label, 'garbage') => 'waste', str_contains($label, 'supplies') => 'supplies',
                    str_contains($label, 'legal') => 'legal', str_starts_with($label, 'misc') => 'other_expense', default => null,
                };
                if ($category && ($row['cells']['N']['numeric'] ?? false)) {
                    $targets[$category] = ($targets[$category] ?? 0) + (int) round((float) $row['cells']['N']['value'] * 100);
                }
            }
        }

        return ['targets' => $targets, 'source' => $sheet ? 'Draft copied from '.$sheet['year'].' workbook; review and save to adopt it for '.$year : 'No expense plan saved yet'];
    }
}
