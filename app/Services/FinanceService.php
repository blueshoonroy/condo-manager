<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    public function snapshot(): array
    {
        $checkpoint = DB::table('balance_checkpoints')->orderByDesc('as_of')->first();
        $latest = DB::table('bank_transactions')->whereNull('removed_at')->max('posted_on');
        $balance = $checkpoint ? (int) $checkpoint->amount_cents + (int) DB::table('bank_transactions')->whereNull('removed_at')->where('posted_on', '>', $checkpoint->as_of)->sum('amount_cents') : null;
        $plaid = DB::table('plaid_connections')->where('environment', 'production')->whereNotNull('account_id')->whereNotNull('balance_fetched_at')->first();
        $hasBankBalance = $plaid && $plaid->balance_cents !== null;
        if ($hasBankBalance) {
            $balance = (int) $plaid->balance_cents;
        }
        $month = now('America/Chicago')->startOfMonth()->toDateString();

        return [
            'balance' => $balance, 'checkpoint' => $checkpoint, 'as_of' => $checkpoint ? max($checkpoint->as_of, $latest ?? $checkpoint->as_of) : $latest,
            'balance_caption' => $hasBankBalance ? 'Bank balance fetched '.Carbon::parse($plaid->balance_fetched_at)->timezone('America/Chicago')->format('M j, g:i a T') : ($balance !== null ? 'Calculated through '.max($checkpoint->as_of, $latest ?? $checkpoint->as_of) : 'A verified bank balance or Plaid connection is needed'),
            'bank_notice' => $hasBankBalance ? (($plaid->status !== 'connected' || now()->subHours(7)->gte($plaid->balance_fetched_at)) ? 'Bank updates need attention. This is the last retrieved balance, not a current live reading.' : 'Plaid updates transactions hourly and retrieves a bank balance every six hours. The bank may take longer to post new activity.') : 'Balances depend on complete CSV uploads and a verified bank checkpoint.',
            'income' => (int) DB::table('bank_transactions')->whereNull('removed_at')->where('posted_on', '>=', $month)->where('amount_cents', '>', 0)->sum('amount_cents'),
            'expenses' => -(int) DB::table('bank_transactions')->whereNull('removed_at')->where('posted_on', '>=', $month)->where('amount_cents', '<', 0)->sum('amount_cents'),
            'last_import' => DB::table('import_batches')->where('kind', 'bank')->where('status', 'applied')->max('updated_at'),
        ];
    }
}
