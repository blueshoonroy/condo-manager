<?php

use App\Jobs\SendInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('association:bill')->dailyAt('08:00')->timezone('America/Chicago')->withoutOverlapping()->when(fn () => config('association.billing_enabled'));
Schedule::call(function () {
    DB::table('invoice_deliveries')->where('status', 'pending')->whereNull('sent_at')->orderBy('id')->each(fn ($delivery) => SendInvoice::dispatch($delivery->id));
})->name('invoice-deliveries')->everyTenMinutes()->withoutOverlapping()->when(fn () => config('association.invoice_emails_enabled'));
Schedule::call(fn () => DB::table('login_challenges')->where('expires_at', '<', now()->subDay())->delete())->name('prune-login-codes')->daily();
