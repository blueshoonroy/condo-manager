<?php

namespace App\Jobs;

use App\Services\AiMatcher;
use App\Services\ReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ReconcileBankTransactions implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public int $runId) {}

    public function handle(AiMatcher $matcher, ReconciliationService $service): void
    {
        if (! DB::table('reconciliation_runs')->where('id', $this->runId)->where('status', 'queued')->update(['status' => 'processing', 'updated_at' => now()])) {
            return;
        }
        $run = DB::table('reconciliation_runs')->find($this->runId);
        try {
            $snapshot = json_decode($run->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $result = ! $snapshot['transactions'] || ! $snapshot['invoices'] ? ['matches' => []] : $matcher->match($run->provider, $run->model, $snapshot);
            $service->saveSuggestions($this->runId, $result);
        } catch (\Throwable $error) {
            // Never persist provider response bodies, request headers or credentials.
            $message = get_class($error) === \RuntimeException::class ? $error->getMessage() : 'The AI request failed or returned invalid matches. Check settings and try a smaller date range.';
            DB::table('reconciliation_runs')->where('id', $this->runId)->update(['status' => 'failed', 'error' => $message, 'updated_at' => now()]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('reconciliation_runs')->where('id', $this->runId)->update(['status' => 'failed', 'error' => 'The reconciliation job did not finish. Check the queue worker and try again.', 'updated_at' => now()]);
    }
}
