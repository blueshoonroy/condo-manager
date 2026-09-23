<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class BudgetReport
{
    public const MONTHS = ['B' => 'Jan', 'C' => 'Feb', 'D' => 'Mar', 'E' => 'Apr', 'F' => 'May', 'G' => 'Jun', 'H' => 'Jul', 'I' => 'Aug', 'J' => 'Sep', 'K' => 'Oct', 'L' => 'Nov', 'M' => 'Dec'];

    public function build(array $sheet): array
    {
        $rows = $sheet['rows'];
        $incomeHeaders = [];
        $actualHeader = null;
        foreach ($rows as $index => $row) {
            $label = trim($row['cells']['A']['value'] ?? '');
            if (in_array($label, ['Income', 'Est Income'], true)) {
                $incomeHeaders[] = $index;
            }
            if (preg_match('/^Actual 20\d{2}$/', $label)) {
                $actualHeader = $index;
            }
        }
        if (count($incomeHeaders) !== 2 || $actualHeader === null || ! ($incomeHeaders[0] < $actualHeader && $actualHeader < $incomeHeaders[1])) {
            throw ValidationException::withMessages(['workbook' => 'Tab '.$sheet['name'].' needs budget expenses, estimated income, actual expenses and income sections.']);
        }
        $incomeEnd = null;
        foreach ($rows as $index => $row) {
            if ($index > $incomeHeaders[1] && trim($row['cells']['A']['value'] ?? '') === 'Total') {
                $incomeEnd = $index;
                break;
            }
        }
        if ($incomeEnd === null) {
            throw ValidationException::withMessages(['workbook' => 'Tab '.$sheet['name'].' is missing the income total.']);
        }
        $sections = [];
        foreach ([
            ['Budgeted expenses', 0, $incomeHeaders[0]],
            ['Budgeted income', $incomeHeaders[0], $actualHeader],
            ['Actual expenses & cash flow', $actualHeader, $incomeHeaders[1]],
            ['Income by unit', $incomeHeaders[1], $incomeEnd + 1],
        ] as [$title, $start, $end]) {
            $sections[] = ['title' => $title, 'header' => $rows[$start], 'rows' => array_slice($rows, $start + 1, $end - $start - 1)];
        }
        $expense = $this->totalRow($sections[0]['rows']);
        $income = $this->totalRow($sections[1]['rows']);
        $actualExpense = $this->totalRow($sections[2]['rows']);
        $actualIncome = $rows[$incomeEnd];
        $assessment = collect($sections[2]['rows'])->first(fn ($row) => str_starts_with($row['cells']['A']['value'] ?? '', 'Special Assessments'));
        $received = $this->total($actualIncome) + ($assessment ? $this->monthlyTotal($assessment) : 0);
        $summary = ['budget_expenses' => $this->total($expense), 'budget_income' => $this->total($income),
            'actual_expenses' => $this->total($actualExpense), 'actual_income' => $received];
        $summary['budget_net'] = $summary['budget_income'] - $summary['budget_expenses'];
        $summary['actual_net'] = $received - $summary['actual_expenses'];
        $warnings = [];
        $sourceLabel = $rows[$actualHeader]['cells']['A']['value'];
        if ($sourceLabel !== 'Actual '.$sheet['year']) {
            $warnings[] = $sheet['year'] === 2025 && $sourceLabel === 'Actual 2024'
                ? 'The source says “Actual 2024”; these figures are shown as 2025 actuals, as confirmed by the association.'
                : 'The actuals heading in the source is “'.$sourceLabel.'”. Confirm the year before relying on comparisons.';
        }
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                if (($row['cells']['N']['numeric'] ?? false) && abs($this->number($row, 'N') - $this->monthlyTotal($row)) > 0.011) {
                    $warnings[] = 'Row '.$row['number'].' ('.($row['cells']['A']['value'] ?? 'Total').'): the reported annual total differs from the sum of the months. Source figures are preserved below.';
                }
            }
        }
        $sourceNet = collect($sections[2]['rows'])->first(fn ($row) => ($row['cells']['A']['value'] ?? '') === 'Surplus/Deficit');
        if ($sourceNet && abs($this->total($sourceNet) - $summary['actual_net']) > 0.011) {
            $warnings[] = 'The source surplus/deficit uses a different income basis. The overview uses recorded income by unit plus special-assessment income, less reported expenses; the original cash-flow rows remain below.';
        }
        $monthly = [];
        foreach (self::MONTHS as $column => $month) {
            $monthly[] = ['month' => $month, 'income' => $this->number($actualIncome, $column) + ($assessment ? $this->number($assessment, $column) : 0), 'expenses' => $this->number($actualExpense, $column)];
        }

        return compact('sections', 'summary', 'warnings', 'monthly') + ['notes' => array_slice($rows, $incomeEnd + 1), 'source_label' => $sourceLabel];
    }

    public function money(float $amount): string
    {
        return ($amount < 0 ? '−' : '').'$'.number_format(abs($amount), 2);
    }

    private function totalRow(array $rows): array
    {
        $row = collect($rows)->first(fn ($row) => in_array(trim($row['cells']['A']['value'] ?? ''), ['Total', 'Total Expenses', ''], true) && isset($row['cells']['N']));
        if (! $row) {
            throw ValidationException::withMessages(['workbook' => 'A budget section is missing its annual total.']);
        }

        return $row;
    }

    private function total(array $row): float
    {
        return ($row['cells']['N']['numeric'] ?? false) ? $this->number($row, 'N') : $this->monthlyTotal($row);
    }

    private function monthlyTotal(array $row): float
    {
        return array_sum(array_map(fn ($column) => $this->number($row, $column), array_keys(self::MONTHS)));
    }

    private function number(array $row, string $column): float
    {
        return ($row['cells'][$column]['numeric'] ?? false) ? (float) $row['cells'][$column]['value'] : 0.0;
    }
}
