<?php

namespace App\Http\Controllers;

use App\Jobs\SyncPlaid;
use App\Services\PlaidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlaidController extends Controller
{
    public function index(Request $request, PlaidService $plaid): View
    {
        $connection = $plaid->connection();
        $accounts = [];
        $error = null;
        if ($connection?->status === 'select_account') {
            try {
                $accounts = $plaid->accounts();
            } catch (\Throwable) {
                $error = 'Could not retrieve accounts. Refresh this page to try again.';
            }
        }

        return view('admin.bank', [
            'connection' => $connection, 'accounts' => $accounts, 'error' => $error,
            'latest' => DB::table('bank_transactions')->max('posted_on'),
            'sandboxTransactions' => $connection && $connection->environment === 'sandbox' ? DB::table('plaid_transactions')->where('connection_id', $connection->id)->where('removed', false)->orderByDesc('posted_on')->limit(15)->get() : collect(),
            'reviews' => DB::table('bank_transactions')->where('review_required', true)->get(),
            'resumeToken' => $request->has('oauth_state_id') && $request->session()->get('plaid_link_expires', 0) > now()->timestamp ? $request->session()->get('plaid_link_token') : null,
            'resumeUpdate' => $request->session()->get('plaid_link_update', false),
        ]);
    }

    public function linkToken(Request $request, PlaidService $plaid): JsonResponse
    {
        $request->validate(['update' => 'required|boolean']);
        try {
            $token = $plaid->linkToken($request->user()->id, $request->boolean('update'));
            $request->session()->put(['plaid_link_token' => $token, 'plaid_link_update' => $request->boolean('update'), 'plaid_link_expires' => now()->addMinutes(30)->timestamp]);

            return response()->json(['link_token' => $token]);
        } catch (\Throwable) {
            return response()->json(['message' => 'Plaid Link could not start. Check Plaid credentials, product access and the allowed redirect URI.'], 422);
        }
    }

    public function exchange(Request $request, PlaidService $plaid): JsonResponse
    {
        $request->validate(['public_token' => 'required|string|max:500']);
        $lock = Cache::lock('plaid-manage:'.config('services.plaid.environment'), 60);
        if (! $lock->get()) {
            return response()->json(['message' => 'A connection change is already in progress.'], 409);
        }
        try {
            $plaid->exchange($request->string('public_token')->toString(), $request->user()->id);
            $request->session()->forget(['plaid_link_token', 'plaid_link_expires', 'plaid_link_update']);

            return response()->json(['redirect' => route('admin.bank')]);
        } catch (\Throwable) {
            return response()->json(['message' => 'Could not save the bank connection. Refresh to check its status before trying again.'], 422);
        } finally {
            $lock->release();
        }
    }

    public function select(Request $request, PlaidService $plaid): RedirectResponse
    {
        $data = $request->validate(['account_id' => 'required|string|max:255', 'starts_on' => 'required|date_format:Y-m-d|before_or_equal:tomorrow']);
        try {
            $plaid->select($data['account_id'], $data['starts_on'], $request->user()->id);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return back()->withErrors(['bank' => 'Could not select the bank account. Refresh and try again.']);
        }
        SyncPlaid::dispatch(true);

        return redirect()->route('admin.bank')->with('status', 'Account selected. The first sync is queued.');
    }

    public function sync(Request $request): RedirectResponse|JsonResponse
    {
        SyncPlaid::dispatch(true);
        if ($request->expectsJson()) {
            $request->session()->forget(['plaid_link_token', 'plaid_link_expires', 'plaid_link_update']);

            return response()->json(['redirect' => route('admin.bank')]);
        }

        return back()->with('status', 'Bank refresh queued. Refresh this page shortly.');
    }

    public function disconnect(Request $request, PlaidService $plaid): RedirectResponse
    {
        try {
            $plaid->disconnect($request->user()->id);
        } catch (\Throwable) {
            return back()->withErrors(['bank' => 'Plaid could not disconnect the account. Please try again.']);
        }

        return back()->with('status', 'Bank access revoked. Imported history remains available.');
    }
}
