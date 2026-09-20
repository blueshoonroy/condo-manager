<?php

namespace App\Http\Controllers;

use App\Jobs\SendInvoice;
use App\Models\Invoice;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceEmailController extends Controller
{
    public function preview(Request $request): View
    {
        $data = $request->validate(['invoice_ids' => 'required|array|min:1|max:200', 'invoice_ids.*' => 'required|integer|distinct|exists:invoices,id']);
        $invoices = $this->selection($data['invoice_ids']);
        $key = (string) Str::uuid();
        $request->session()->put('invoice_email_draft', ['ids' => $invoices->modelKeys(), 'key' => $key, 'fingerprint' => $this->fingerprint($invoices), 'expires' => now()->addMinutes(30)->timestamp]);

        return view('admin.invoice-email-preview', ['invoices' => $invoices, 'requestKey' => $key, 'emailPreview' => view('mail.invoice', ['invoice' => $invoices->first(), 'resident' => $invoices->first()->household->residents->first()])->render()]);
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate(['request_key' => 'required|uuid']);
        $draft = $request->session()->get('invoice_email_draft');
        if (! $draft || $draft['key'] !== $request->input('request_key') || $draft['expires'] < now()->timestamp) {
            throw ValidationException::withMessages(['invoices' => 'Select and preview the invoices again before sending.']);
        }
        $queued = DB::transaction(function () use ($draft) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $invoices = $this->selection($draft['ids']);
            if ($this->fingerprint($invoices) !== $draft['fingerprint']) {
                throw ValidationException::withMessages(['invoices' => 'An invoice or recipient changed. Select the invoices and review them again.']);
            }
            $count = 0;
            foreach ($invoices as $invoice) {
                foreach ($invoice->household->residents as $resident) {
                    $existing = DB::table('invoice_deliveries')->where('invoice_id', $invoice->id)->where('user_id', $resident->id);
                    if ((clone $existing)->whereIn('status', ['pending', 'review_required'])->exists() || (clone $existing)->where('send_key', $draft['key'])->exists()) {
                        continue;
                    }
                    $id = DB::table('invoice_deliveries')->insertGetId(['invoice_id' => $invoice->id, 'user_id' => $resident->id, 'send_key' => $draft['key'], 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    SendInvoice::dispatch($id)->afterCommit();
                    $count++;
                }
            }
            Audit::record('invoices.email_queued', 'batch:'.$draft['key'], ['invoice_ids' => $draft['ids'], 'deliveries' => $count]);

            return $count;
        });
        $request->session()->forget('invoice_email_draft');

        return redirect()->route('invoices')->with('status', $queued.' invoice emails queued. Existing pending or review-required deliveries were skipped. Track delivery in Administration > Dues & assessments.');
    }

    private function selection(array $ids): Collection
    {
        if (! config('association.invoice_emails_enabled')) {
            throw ValidationException::withMessages(['invoices' => 'Invoice emailing is disabled. Enable it before sending.']);
        }
        $invoices = Invoice::with(['household.residents' => fn ($query) => $query->where('active', true)->orderBy('id')])->whereIn('id', $ids)->orderBy('id')->get();
        if ($invoices->count() !== count($ids)) {
            throw ValidationException::withMessages(['invoices' => 'One or more selected invoices no longer exist.']);
        }
        foreach ($invoices as $invoice) {
            if ($invoice->void_reason || ! $invoice->household?->active || $invoice->household->residents->isEmpty()) {
                throw ValidationException::withMessages(['invoices' => 'Invoice #'.$invoice->number.' is void or has no active household contacts. Deselect it before sending.']);
            }
        }

        return $invoices;
    }

    private function fingerprint(Collection $invoices): string
    {
        return hash('sha256', json_encode($invoices->map(fn ($invoice) => [$invoice->toArray(), $invoice->balanceCents()])->all(), JSON_THROW_ON_ERROR));
    }
}
