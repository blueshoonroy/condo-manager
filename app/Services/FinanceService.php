<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    public function __construct(private PlaidService $plaid) {}

    public function snapshot(): array
    {
        $checkpoint = DB::table('balance_checkpoints')->orderByDesc('as_of')->first();
        $latest = DB::table('bank_transactions')->whereNull('removed_at')->max('posted_on');
        $balance = $checkpoint ? (int) $checkpoint->amount_cents + (int) DB::table('bank_transactions')->whereNull('removed_at')->where('posted_on', '>', $checkpoint->as_of)->sum('amount_cents') : null;
        $plaid = DB::table('plaid_connections')->where('environment', 'production')->whereNotNull('account_id')->whereNotNull('balance_fetched_at')->first();
        $hasBankBalance = $plaid && $plaid->balance_cents !== null;
        // A stale Plaid reading must not hide newer CSV activity, so it only wins while the feed is healthy and recent
        // and Plaid is still receiving data from the bank.
        $plaidFresh = $hasBankBalance && $plaid->status === 'connected' && now()->subHours(7)->lt($plaid->balance_fetched_at) && ! $this->plaid->bankDataIsStale($plaid);
        $hasBankBalance = $plaidFresh || ($hasBankBalance && $balance === null);
        if ($hasBankBalance) {
            $balance = (int) $plaid->balance_cents;
        }
        // When the live check failed, the balance is Plaid's cached copy, as current as Plaid's last bank refresh.
        $cachedBalance = $hasBankBalance && $plaid->live_balance_error && $plaid->data_updated_at;
        $month = now('America/Chicago')->startOfMonth()->toDateString();

        return [
            'balance' => $balance, 'checkpoint' => $checkpoint, 'as_of' => $checkpoint ? max($checkpoint->as_of, $latest ?? $checkpoint->as_of) : $latest,
            'balance_caption' => $hasBankBalance ? ($cachedBalance ? 'Bank balance as of Plaid\'s last bank update, '.$this->chicagoTime($plaid->data_updated_at) : 'Bank balance fetched '.$this->chicagoTime($plaid->balance_fetched_at)) : ($balance !== null ? 'Calculated through '.max($checkpoint->as_of, $latest ?? $checkpoint->as_of) : 'A verified bank balance or Plaid connection is needed'),
            'bank_notice' => $hasBankBalance ? (! $plaidFresh ? 'Bank updates need attention. This is the last retrieved balance, not a current live reading.' : 'Plaid updates transactions hourly and retrieves a bank balance every six hours. The bank may take longer to post new activity.') : ($plaid ? 'Plaid bank updates need attention. This balance is calculated from the verified checkpoint plus synced and uploaded activity.' : 'Balances depend on complete CSV uploads and a verified bank checkpoint.'),
            'income' => (int) DB::table('bank_transactions')->whereNull('removed_at')->where('posted_on', '>=', $month)->where('amount_cents', '>', 0)->sum('amount_cents'),
            'expenses' => -(int) DB::table('bank_transactions')->whereNull('removed_at')->where('posted_on', '>=', $month)->where('amount_cents', '<', 0)->sum('amount_cents'),
            'last_import' => DB::table('import_batches')->where('kind', 'bank')->where('status', 'applied')->max('updated_at'),
        ];
    }

    private function chicagoTime(string $timestamp): string
    {
        return Carbon::parse($timestamp)->timezone('America/Chicago')->format('M j, g:i a T');
    }
}
