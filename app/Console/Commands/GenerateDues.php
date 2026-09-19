<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateDues extends Command
{
    protected $signature = 'association:bill {month? : YYYY-MM; defaults to current Chicago month}';

    protected $description = 'Generate monthly dues after billing is configured and enabled';

    public function handle(BillingService $billing): int
    {
        if ($this->argument('month')) {
            $count = $billing->generate($this->argument('month'));
        } else {
            $start = config('association.billing_start_month');
            if (! $start || ! preg_match('/^\d{4}-\d{2}$/D', $start)) {
                $this->error('Set BILLING_START_MONTH to YYYY-MM first.');

                return self::FAILURE;
            }
            $month = CarbonImmutable::createFromFormat('!Y-m', $start, 'America/Chicago');
            if ($month->format('Y-m') !== $start || $month->lt(now('America/Chicago')->subYears(10))) {
                $this->error('Invalid start month; automatic catch-up is limited to 10 years.');

                return self::FAILURE;
            }
            $count = 0;
            while ($month->format('Y-m') <= now('America/Chicago')->format('Y-m')) {
                $count += $billing->generate($month->format('Y-m'));
                $month = $month->addMonth();
            }
        }
        $this->info($count.' invoices generated.');

        return self::SUCCESS;
    }
}
