<?php

namespace App\Jobs;

use App\Services\PlaidService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncPlaid implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public function __construct(public bool $forceBalance = false) {}

    public function handle(PlaidService $plaid): void
    {
        $lock = Cache::lock('plaid-sync:'.config('services.plaid.environment'), 170);
        if (! $lock->get()) {
            return;
        }
        try {
            $plaid->sync($this->forceBalance);
        } catch (\Throwable $error) {
            $code = get_class($error) === \RuntimeException::class && preg_match('/^[A-Z_]{1,80}$/D', $error->getMessage()) ? $error->getMessage() : 'SYNC_FAILED';
            DB::table('plaid_connections')->where('environment', config('services.plaid.environment'))->whereNotNull('access_token')
                ->update(['error_code' => $code, 'status' => $code === 'ITEM_LOGIN_REQUIRED' ? 'needs_login' : 'error', 'updated_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('plaid_connections')->where('environment', config('services.plaid.environment'))->whereNotNull('access_token')->update(['error_code' => 'SYNC_TIMEOUT', 'status' => 'error', 'updated_at' => now()]);
    }
}
