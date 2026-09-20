<?php

use App\Jobs\SendInvoice;
use App\Jobs\SyncPlaid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('association:bill')->dailyAt('08:00')->timezone('America/Chicago')->onOneServer()->withoutOverlapping()->when(fn () => config('association.billing_enabled'));
Schedule::call(function () {
    DB::table('invoice_deliveries')->where('status', 'pending')->whereNull('sent_at')->orderBy('id')->each(fn ($delivery) => SendInvoice::dispatch($delivery->id));
})->name('invoice-deliveries')->everyTenMinutes()->onOneServer()->withoutOverlapping()->when(fn () => config('association.invoice_emails_enabled'));
Schedule::call(fn () => DB::table('login_challenges')->where('expires_at', '<', now()->subDay())->delete())->name('prune-login-codes')->daily();
Schedule::job(new SyncPlaid)->hourly()->onOneServer()->withoutOverlapping()->when(fn () => (bool) config('services.plaid.secret'));
