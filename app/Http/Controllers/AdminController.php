<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CsvImporter;
use App\Services\PaymentService;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->query('tab', 'payments');
        abort_unless(in_array($tab, ['payments', 'billing', 'residents', 'imports', 'activity'], true), 404);

        return view('admin.index', [
            'tab' => $tab,
            'households' => Household::where('active', true)->with('residents')->get(),
            'imports' => DB::table('import_batches')->orderByDesc('id')->limit(15)->get(),
            'events' => DB::table('audit_events')->orderByDesc('id')->limit(20)->get(),
            'payments' => DB::table('payments')->join('households', 'households.id', '=', 'payments.household_id')->select('payments.*', 'households.client_name')->orderByDesc('payments.id')->limit(30)->get(),
            'rates' => DB::table('dues_rates')->orderBy('unit_id')->orderByDesc('effective_on')->get(),
            'deliveries' => DB::table('invoice_deliveries')->join('invoices', 'invoices.id', '=', 'invoice_deliveries.invoice_id')->select('invoice_deliveries.*', 'invoices.number')->orderByDesc('invoice_deliveries.id')->limit(25)->get(),
        ]);
    }

    public function preview(Request $request, CsvImporter $importer): RedirectResponse
    {
        $request->validate(['kind' => 'required|in:bank,invoices', 'file' => 'required|file|max:5120|mimes:csv,txt']);
        $path = $request->file('file')->getRealPath();
        $result = $importer->inspect($path, $request->kind);
        $contents = file_get_contents($path);
        $id = DB::table('import_batches')->insertGetId([
            'kind' => $request->kind, 'filename' => basename($request->file('file')->getClientOriginalName()),
            'checksum' => hash('sha256', $contents), 'source_base64' => base64_encode($contents),
            'summary' => json_encode($result['summary']), 'user_id' => $request->user()->id, 'status' => 'preview', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('admin.import', $id);
    }

    public function import(int $batch, CsvImporter $importer): View
    {
        $batch = DB::table('import_batches')->where('id', $batch)->first();
        abort_unless($batch, 404);

        return view('admin.import', ['batch' => $batch, 'result' => $this->withImportFile($batch, fn ($path) => $importer->inspect($path, $batch->kind))]);
    }

    public function commit(int $batch, CsvImporter $importer): RedirectResponse
    {
        $record = DB::table('import_batches')->where('id', $batch)->first();
        abort_unless($record, 404);
        $this->withImportFile($record, fn ($path) => $importer->commit($batch, $path));

        return redirect()->route('admin', ['tab' => 'imports'])->with('status', 'Import applied successfully.');
    }

    private function withImportFile(object $batch, \Closure $callback): mixed
    {
        if ($batch->source_base64 === null) {
            abort_unless($batch->path && Storage::disk('local')->exists($batch->path), 404);

            return $callback(Storage::disk('local')->path($batch->path));
        }

        $contents = base64_decode($batch->source_base64, true);
        abort_if($contents === false || ! hash_equals($batch->checksum, hash('sha256', $contents)), 500, 'Import source failed integrity verification.');
        $handle = tmpfile();
        if ($handle === false) {
            throw new \RuntimeException('Unable to create temporary import file.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Unable to write temporary import file.');
            }

            return $callback(stream_get_meta_data($handle)['uri']);
        } finally {
            fclose($handle);
        }
    }

    public function checkpoint(Request $request): RedirectResponse
    {
        $data = $request->validate(['as_of' => 'required|date_format:Y-m-d|before_or_equal:today', 'amount' => ['required', 'regex:/^-?\d{1,8}(\.\d{1,2})?$/'], 'note' => 'required|string|max:1000']);
        DB::transaction(function () use ($data, $request) {
            DB::table('balance_checkpoints')->updateOrInsert(['as_of' => $data['as_of']], ['amount_cents' => Money::cents($data['amount']), 'note' => $data['note'], 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('balance.verified', $data['as_of'], ['amount_cents' => Money::cents($data['amount']), 'note' => $data['note']]);
        });

        return back()->with('status', 'Verified end-of-day balance saved.');
    }

    public function paymentForm(int $household): View
    {
        $household = Household::findOrFail($household);
        $invoices = Invoice::where('household_id', $household->id)->orderBy('due_on')->get()->filter(fn ($i) => $i->balanceCents() > 0);
        $credits = DB::table('bank_transactions')->whereNull('removed_at')->where('review_required', false)->where('amount_cents', '>', 0)->whereNotIn('id', DB::table('payments')->whereNotNull('bank_transaction_id')->select('bank_transaction_id'))->orderByDesc('posted_on')->get();

        return view('admin.payment', compact('household', 'invoices', 'credits'));
    }

    public function payment(Request $request, PaymentService $service): RedirectResponse
    {
        $data = $request->validate([
            'household_id' => 'required|exists:households,id', 'request_key' => 'required|uuid', 'bank_transaction_id' => 'nullable|exists:bank_transactions,id',
            'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'], 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'note' => 'required|string|max:1000',
            'allocations' => 'nullable|array', 'allocations.*' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
        ]);
        $data['amount_cents'] = Money::cents($data['amount']);
        $data['allocations'] = array_map(fn ($amount) => $amount ? Money::cents($amount) : 0, $data['allocations'] ?? []);
        $service->record($data, $request->user()->id);

        return redirect()->route('admin')->with('status', 'Payment recorded. Any unallocated amount remains household credit.');
    }

    public function markPaid(Request $request, int $invoice, PaymentService $service): RedirectResponse
    {
        $data = $request->validate([
            'request_key' => 'required|uuid', 'expected_balance' => 'required|integer|min:1',
            'payment_method' => ['required', Rule::in(array_keys(PaymentService::METHODS))],
            'paid_on' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Chicago')->toDateString(),
            'note' => 'required|string|min:3|max:1000',
        ]);
        $service->markInvoicePaid($invoice, (int) $data['expected_balance'], $data['paid_on'], $data['payment_method'], $data['note'], $data['request_key'], $request->user()->id);

        return redirect()->route('invoice', $invoice)->with('status', 'Invoice marked paid. A manual payment was recorded for its remaining balance.');
    }

    public function allocationForm(int $payment): View
    {
        $payment = DB::table('payments')->where('id', $payment)->whereNull('reversed_at')->first();
        abort_unless($payment, 404);
        $invoices = Invoice::where('household_id', $payment->household_id)->whereNull('void_reason')->orderBy('due_on')->get();
        $allocations = DB::table('payment_allocations')->where('payment_id', $payment->id)->pluck('amount_cents', 'invoice_id');

        return view('admin.allocations', compact('payment', 'invoices', 'allocations'));
    }

    public function allocate(Request $request, int $payment, PaymentService $service): RedirectResponse
    {
        $data = $request->validate(['allocations' => 'required|array', 'allocations.*' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/']]);
        $service->allocate($payment, array_map(fn ($amount) => $amount ? Money::cents($amount) : 0, $data['allocations']));

        return redirect()->route('admin')->with('status', 'Payment allocations updated.');
    }

    public function reverse(Request $request, int $payment, PaymentService $service): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $service->reverse($payment, $request->reason);

        return back()->with('status', 'Payment reversed; invoice balances restored.');
    }

    public function assessmentPreview(Request $request, BillingService $billing): View
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'amount' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'], 'due_on' => 'required|date_format:Y-m-d|after_or_equal:today']);
        $total = Money::cents($data['amount']);
        $amounts = $billing->assessmentSplit($total);
        $households = Household::where('active', true)->join('units', 'units.id', '=', 'households.unit_id')->select('households.*', 'units.number as unit_number')->get()->keyBy('unit_number');
        if ($households->count() !== 5 || array_diff(array_keys($amounts), $households->keys()->all())) {
            throw ValidationException::withMessages(['assessment' => 'All five units need an active household before creating an assessment.']);
        }
        $requestKey = (string) Str::uuid();
        $request->session()->put('assessment_draft', $data + ['total' => $total, 'households' => $households->pluck('id', 'unit_number')->all(), 'request_key' => $requestKey]);

        return view('admin.assessment', compact('data', 'total', 'amounts', 'households', 'requestKey'));
    }

    public function assessmentConfirm(Request $request, BillingService $billing): RedirectResponse
    {
        $request->validate(['request_key' => 'required|uuid']);
        $draft = $request->session()->get('assessment_draft');
        if (! $draft || $draft['request_key'] !== $request->request_key) {
            throw ValidationException::withMessages(['assessment' => 'Preview this assessment before creating invoices.']);
        }
        Validator::make($draft, ['due_on' => 'required|date_format:Y-m-d|after_or_equal:today'])->validate();
        $billing->buildingAssessment($draft['title'], $draft['total'], $draft['due_on'], $draft['request_key'], $draft['households']);
        $request->session()->forget('assessment_draft');

        return redirect()->route('admin', ['tab' => 'billing'])->with('status', 'Five assessment invoices created using the unit percentages.');
    }

    public function assessment(Request $request, BillingService $billing): RedirectResponse
    {
        $data = $request->validate(['household_id' => 'required|exists:households,id', 'title' => 'required|string|max:200', 'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'], 'due_on' => 'required|date_format:Y-m-d|after_or_equal:today', 'request_key' => 'required|uuid']);
        $amount = Money::cents($data['amount']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount.']);
        }
        $household = Household::where('active', true)->findOrFail($data['household_id']);
        $invoice = $billing->assessment($household, $data['title'], $amount, $data['due_on'], $data['request_key']);

        return redirect()->route('invoice', $invoice)->with('status', 'Special assessment created.');
    }

    public function void(Request $request, int $invoice): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($request, $invoice) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $record = Invoice::whereKey($invoice)->lockForUpdate()->firstOrFail();
            if ($record->historical_paid_cents > 0 || $record->allocatedCents() > 0) {
                throw ValidationException::withMessages(['invoice' => 'Reverse payments before voiding. Imported paid history must be corrected through its source.']);
            }
            $record->update(['void_reason' => $request->reason]);
            Audit::record('invoice.voided', 'invoice:'.$invoice, ['reason' => $request->reason]);
        });

        return back()->with('status', 'Invoice voided.');
    }

    public function resident(Request $request, User $user): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:254|unique:users,email,'.$user->id, 'phone' => 'nullable|string|max:40', 'active' => 'required|boolean']);
        if ($user->id === $request->user()->id && ! $data['active']) {
            throw ValidationException::withMessages(['active' => 'You cannot disable your own account.']);
        }
        DB::transaction(function () use ($user, $data) {
            User::orderBy('id')->lockForUpdate()->get();
            if (! $data['active'] && ! User::whereKeyNot($user->id)->where('active', true)->where('is_admin', true)->whereHas('household', fn ($query) => $query->where('active', true))->exists()) {
                throw ValidationException::withMessages(['active' => 'At least one active administrator must remain.']);
            }
            if ($data['active']) {
                $data['is_admin'] = true;
            }
            $data['email'] = strtolower($data['email']);
            if ($user->email !== $data['email']) {
                $user->forceFill(['google_id' => null]);
            }
            $user->update($data);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('login_challenges')->where('user_id', $user->id)->update(['used_at' => now()]);
            Audit::record('resident.updated', 'user:'.$user->id);
        });

        return back()->with('status', 'Resident updated and existing sessions revoked.');
    }

    public function dues(Request $request): RedirectResponse
    {
        $data = $request->validate(['unit_id' => 'required|exists:units,id', 'effective_on' => 'required|date_format:Y-m-d|after_or_equal:today', 'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/']]);
        $amount = Money::cents($data['amount']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Dues must be positive.']);
        }
        DB::transaction(function () use ($data, $amount) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            DB::table('dues_rates')->updateOrInsert(['unit_id' => $data['unit_id'], 'effective_on' => $data['effective_on']], ['amount_cents' => $amount, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('dues.updated', 'unit:'.$data['unit_id'], ['effective_on' => $data['effective_on'], 'amount_cents' => $amount]);
        });

        return back()->with('status', 'Dues rate saved. Existing invoices retain their original amounts.');
    }
}
