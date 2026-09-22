<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Invoice;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function dashboard(Request $request, FinanceService $finance): View
    {
        $invoices = Invoice::visibleTo($request->user())->orderByDesc('issued_on')->get();
        $outstanding = $invoices->sum(fn ($invoice) => $invoice->balanceCents());
        $unitId = $request->user()->household->unit_id;
        $dues = DB::table('dues_rates')->where('unit_id', $unitId)->where('effective_on', '<=', now('America/Chicago')->toDateString())->orderByDesc('effective_on')->value('amount_cents');

        return view('dashboard', ['invoices' => $invoices->take(5), 'outstanding' => $outstanding, 'dues' => $dues, 'finance' => $finance->snapshot(), 'transactions' => DB::table('bank_transactions')->whereNull('removed_at')->orderByDesc('posted_on')->orderByDesc('id')->limit(5)->get()]);
    }

    public function invoices(Request $request): View
    {
        $request->validate(['status' => 'nullable|in:paid,outstanding,overdue,void', 'q' => 'nullable|string|max:80']);
        $invoices = Invoice::visibleTo($request->user())->with('household')->when($request->filled('q'), fn ($q) => $q->where('number', 'like', '%'.$request->string('q').'%'))->orderByDesc('issued_on')->get();
        if ($request->filled('status')) {
            $invoices = $invoices->filter(fn ($i) => $request->status === 'outstanding' ? $i->balanceCents() > 0 : $i->status() === $request->status);
        }

        return view('invoices', compact('invoices'));
    }

    public function invoice(Request $request, int $invoice): View
    {
        $invoice = Invoice::visibleTo($request->user())->with('household')->findOrFail($invoice);
        $payments = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')->where('invoice_id', $invoice->id)->whereNull('reversed_at')->select('payments.paid_on', 'payments.payment_method', 'payment_allocations.amount_cents')->get();

        return view('invoice', compact('invoice', 'payments'));
    }

    public function finances(Request $request, FinanceService $finance): View
    {
        $request->validate(['q' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'type' => 'nullable|in:credit,debit']);
        $transactions = DB::table('bank_transactions')
            ->when($request->filled('q'), fn ($q) => $q->where('description', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('from'), fn ($q) => $q->where('posted_on', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('posted_on', '<=', $request->to))
            ->when($request->type === 'credit', fn ($q) => $q->where('amount_cents', '>', 0))
            ->when($request->type === 'debit', fn ($q) => $q->where('amount_cents', '<', 0))
            ->orderByDesc('posted_on')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('finances', ['transactions' => $transactions, 'finance' => $finance->snapshot()]);
    }

    public function directory(): View
    {
        return view('directory', ['households' => Household::where('active', true)->with(['residents' => fn ($q) => $q->where('active', true)])->orderBy('unit_id')->get()]);
    }
}
