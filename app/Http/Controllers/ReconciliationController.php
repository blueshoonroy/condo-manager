<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\AiSettings;
use App\Services\ReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReconciliationController extends Controller
{
    public function index(AiSettings $settings): View
    {
        return view('admin.reconciliation', ['settings' => $settings->publicSettings(), 'models' => AiSettings::MODELS,
            'runs' => DB::table('reconciliation_runs')->orderByDesc('id')->limit(20)->get()]);
    }

    public function settings(Request $request, AiSettings $settings): RedirectResponse
    {
        $data = $request->validate(['provider' => 'required|in:openai,anthropic', 'api_key' => 'nullable|string|min:10|max:500|regex:/^\S+$/', 'remove_key' => 'nullable|boolean']);
        $settings->save($data['provider'], $data['api_key'] ?? null, $request->boolean('remove_key'), $request->user()->id);

        return back()->with('status', 'AI settings saved. Keys are encrypted and never shown back here.');
    }

    public function start(Request $request, ReconciliationService $service): RedirectResponse
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d|before_or_equal:today', 'to' => 'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:today']);
        $id = $service->start($data['from'], $data['to'], $request->user()->id);

        return redirect()->route('admin.reconciliation.run', $id);
    }

    public function show(int $run): View
    {
        $run = DB::table('reconciliation_runs')->find($run);
        abort_unless($run, 404);
        $snapshot = json_decode($run->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $suggestions = DB::table('reconciliation_suggestions')->where('run_id', $run->id)->get();

        return view('admin.reconciliation-run', [
            'run' => $run, 'suggestions' => $suggestions, 'snapshot' => $snapshot,
            'banks' => collect($snapshot['transactions'])->keyBy('id'), 'invoices' => collect($snapshot['invoices'])->keyBy('id'),
            'currentInvoices' => Invoice::whereIn('id', collect($snapshot['invoices'])->pluck('id'))->get()->keyBy('id'),
        ]);
    }

    public function review(Request $request, int $suggestion, ReconciliationService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject']);
        $service->review($suggestion, $data['decision'] === 'approve', $request->user()->id);

        return back()->with('status', $data['decision'] === 'approve' ? 'Match approved and payment recorded.' : 'Suggestion rejected. No payment recorded.');
    }
}
