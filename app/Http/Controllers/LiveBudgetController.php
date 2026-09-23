<?php

namespace App\Http\Controllers;

use App\Services\BudgetCategorizer;
use App\Services\LiveBudgetReport;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LiveBudgetController extends Controller
{
    public function index(Request $request, LiveBudgetReport $reports): View
    {
        $year = $this->year($request);

        return view('budget-live', ['year' => $year, 'report' => $reports->build($year), 'plan' => $reports->draftTargets($year), 'categories' => BudgetCategorizer::CATEGORIES]);
    }

    public function review(Request $request, BudgetCategorizer $categorizer): View
    {
        $year = $this->year($request);
        $request->validate(['filter' => 'nullable|in:pending,all', 'q' => 'nullable|string|max:100']);
        $entries = $categorizer->entries($year)
            ->filter(fn ($entry) => $request->input('filter') === 'all' || ! $entry['category'])
            ->filter(fn ($entry) => ! $request->filled('q') || str_contains(mb_strtolower($entry['bank']->description), mb_strtolower($request->string('q')->toString())))->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator($entries->forPage($page, 25), $entries->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('budget-review', ['year' => $year, 'entries' => $paginator, 'categories' => BudgetCategorizer::CATEGORIES,
            'runs' => DB::table('budget_ai_runs')->where('year', $year)->latest('id')->limit(5)->get(),
            'rules' => DB::table('budget_category_rules')->orderBy('description')->get(), 'categorizer' => $categorizer]);
    }

    public function categorize(Request $request, int $transaction, BudgetCategorizer $categorizer): RedirectResponse
    {
        $data = $request->validate(['fingerprint' => 'required|string|size:64', 'category' => 'nullable|string', 'note' => 'nullable|string|max:1000', 'remember' => 'nullable|boolean']);
        $categorizer->review($transaction, $data['fingerprint'], $data['category'] ?? null, $data['note'] ?? '', $request->boolean('remember'), $request->user()->id);

        return back()->with('status', 'Category saved. The live budget is updated.');
    }

    public function suggest(Request $request, BudgetCategorizer $categorizer): RedirectResponse
    {
        $year = $this->year($request);
        $categorizer->startAi($year, $request->user()->id);

        return redirect()->route('budget.review', ['year' => $year])->with('status', 'AI is reviewing up to 20 transactions. Refresh shortly for suggestions.');
    }

    public function savePlan(Request $request): RedirectResponse
    {
        $year = $this->year($request);
        $data = $request->validate(['targets' => 'required|array', 'targets.*' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/']]);
        DB::transaction(function () use ($data, $year, $request) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            foreach (BudgetCategorizer::CATEGORIES as $category => [$label, $type]) {
                if ($type !== 'expense' || ! array_key_exists($category, $data['targets'])) {
                    continue;
                }
                if ($data['targets'][$category] === null) {
                    DB::table('budget_targets')->where('year', $year)->where('category', $category)->delete();
                } else {
                    DB::table('budget_targets')->updateOrInsert(['year' => $year, 'category' => $category], ['annual_cents' => Money::cents($data['targets'][$category]), 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            Audit::record('budget.plan_saved', 'year:'.$year);
        });

        return redirect()->route('budget.live', ['year' => $year])->with('status', 'Expense plan saved.');
    }

    public function deleteRule(int $rule): RedirectResponse
    {
        DB::table('budget_category_rules')->where('id', $rule)->delete();
        Audit::record('budget.rule_deleted', 'rule:'.$rule);

        return back()->with('status', 'Vendor rule removed. Individually confirmed transactions keep their categories.');
    }

    private function year(Request $request): int
    {
        $request->validate(['year' => 'nullable|integer|between:2026,2099']);

        return $request->integer('year', now('America/Chicago')->year);
    }
}
