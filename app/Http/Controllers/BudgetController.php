<?php

namespace App\Http\Controllers;

use App\Services\BudgetReport;
use App\Services\BudgetWorkbookImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BudgetController extends Controller
{
    public function index(Request $request, BudgetReport $formatter): View
    {
        $request->validate(['year' => 'nullable|integer|between:2000,2099', 'workbook' => 'nullable|integer']);
        $workbooks = DB::table('budget_workbooks')->select('id', 'filename', 'created_at')->orderByDesc('id')->get();
        $workbook = $request->filled('workbook') ? DB::table('budget_workbooks')->find($request->integer('workbook')) : DB::table('budget_workbooks')->latest('id')->first();
        abort_if($request->filled('workbook') && ! $workbook, 404);
        $sheets = $workbook ? collect(json_decode($workbook->sheets, true, flags: JSON_THROW_ON_ERROR)) : collect();
        $years = $sheets->where('kind', 'budget')->pluck('year')->sortDesc()->values();
        $year = $request->integer('year', $years->first() ?? now()->year);
        $sheet = $sheets->where('kind', 'budget')->firstWhere('year', $year);
        abort_if($workbook && ! $sheet, 404);
        $report = $sheet ? $formatter->build($sheet) : null;
        $transactions = $sheets->where('kind', 'transactions')->firstWhere('year', $year);

        return view('budget', compact('workbook', 'workbooks', 'years', 'year', 'sheet', 'report', 'transactions', 'formatter'));
    }

    public function import(Request $request, BudgetWorkbookImporter $importer): RedirectResponse
    {
        $request->validate(['workbook' => 'required|file|mimes:xlsx|max:5120']);
        $file = $request->file('workbook');
        $id = $importer->import($file->getRealPath(), $file->getClientOriginalName(), $request->user()->id);

        return redirect()->route('budget', ['workbook' => $id])->with('status', 'Budget imported. The spreadsheet has officially moved in.');
    }

    public function download(int $workbook): Response
    {
        $record = DB::table('budget_workbooks')->find($workbook);
        abort_unless($record, 404);

        return response(base64_decode($record->original_base64), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="association-budget-'.$record->id.'.xlsx"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
