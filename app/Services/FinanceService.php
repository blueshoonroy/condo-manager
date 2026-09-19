<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FinanceService
{
    public function snapshot(): array
    {
        $checkpoint = DB::table('balance_checkpoints')->orderByDesc('as_of')->first();
        $latest = DB::table('bank_transactions')->max('posted_on');
        $balance = $checkpoint ? (int) $checkpoint->amount_cents + (int) DB::table('bank_transactions')->where('posted_on', '>', $checkpoint->as_of)->sum('amount_cents') : null;
        $month = now('America/Chicago')->startOfMonth()->toDateString();

        return [
            'balance' => $balance, 'checkpoint' => $checkpoint, 'as_of' => $checkpoint ? max($checkpoint->as_of, $latest ?? $checkpoint->as_of) : $latest,
            'income' => (int) DB::table('bank_transactions')->where('posted_on', '>=', $month)->where('amount_cents', '>', 0)->sum('amount_cents'),
            'expenses' => -(int) DB::table('bank_transactions')->where('posted_on', '>=', $month)->where('amount_cents', '<', 0)->sum('amount_cents'),
            'last_import' => DB::table('import_batches')->where('kind', 'bank')->where('status', 'applied')->max('updated_at'),
        ];
    }
}
