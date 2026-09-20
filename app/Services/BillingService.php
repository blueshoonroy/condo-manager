<?php

namespace App\Services;

use App\Jobs\SendInvoice;
use App\Models\Household;
use App\Models\Invoice;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function generate(string $month): int
    {
        if (! preg_match('/^\d{4}-\d{2}$/D', $month) || ! config('association.billing_enabled') || ! config('association.billing_start_month')) {
            throw ValidationException::withMessages(['billing' => 'Configure and enable billing before generating invoices.']);
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'America/Chicago');
        if ($start->format('Y-m') !== $month || $month < config('association.billing_start_month') || $month > now('America/Chicago')->format('Y-m')) {
            throw ValidationException::withMessages(['billing' => 'The billing month is outside the configured billing period.']);
        }
        $day = config('association.billing_issue_day');
        $dueDays = config('association.billing_due_days');
        if ($day < 1 || $day > 28 || $dueDays < 0 || $dueDays > 90) {
            throw ValidationException::withMessages(['billing' => 'Issue day must be 1–28 and due days 0–90.']);
        }
        $issued = $start->day($day);
        if ($issued->isFuture()) {
            return 0;
        }

        return DB::transaction(function () use ($start, $issued, $dueDays, $month) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $count = 0;
            foreach (Household::where('active', true)->get() as $household) {
                if ($household->move_in && $household->move_in->format('Y-m') > $month) {
                    continue;
                }
                $key = 'dues:'.$household->unit_id.':'.$month;
                if (Invoice::where('billing_key', $key)->exists()) {
                    continue;
                }
                $historical = Invoice::where('unit_id', $household->unit_id)->where('source', 'freshbooks')
                    ->whereBetween('issued_on', [$start->toDateString(), $start->endOfMonth()->toDateString()])->get();
                foreach ($historical as $invoice) {
                    foreach ($invoice->items as $item) {
                        if (str_contains(strtolower($item['name']), 'monthly dues')) {
                            throw ValidationException::withMessages(['billing' => 'FreshBooks dues already exist for '.$month.'. Resolve the cutover before generating.']);
                        }
                    }
                }
                $rate = DB::table('dues_rates')->where('unit_id', $household->unit_id)->where('effective_on', '<=', $issued->toDateString())->orderByDesc('effective_on')->first();
                if (! $rate) {
                    throw ValidationException::withMessages(['billing' => 'A dues rate is missing for an active unit.']);
                }
                $invoice = Invoice::create([
                    'household_id' => $household->id, 'unit_id' => $household->unit_id,
                    'number' => 'BM-'.$start->format('Ym').'-U'.$household->unit_id,
                    'billing_key' => $key, 'issued_on' => $issued->toDateString(), 'due_on' => $issued->addDays($dueDays)->toDateString(),
                    'total_cents' => $rate->amount_cents, 'items' => [['name' => 'Monthly association dues', 'description' => $start->format('F Y'), 'amount_cents' => $rate->amount_cents]],
                ]);
                $this->prepareDeliveries($invoice);
                Audit::record('invoice.generated', 'invoice:'.$invoice->id);
                $count++;
            }

            return $count;
        });
    }

    public function assessment(Household $household, string $title, int $amount, string $dueOn, string $requestKey): Invoice
    {
        return DB::transaction(function () use ($household, $title, $amount, $dueOn, $requestKey) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            $invoice = Invoice::firstOrCreate(['billing_key' => 'assessment:'.$requestKey], [
                'household_id' => $household->id, 'unit_id' => $household->unit_id,
                'number' => 'BM-SA-'.strtoupper(Str::random(10)), 'issued_on' => now('America/Chicago')->toDateString(), 'due_on' => $dueOn,
                'total_cents' => $amount, 'items' => [['name' => $title, 'description' => 'Special assessment', 'amount_cents' => $amount]],
            ]);
            if ($invoice->wasRecentlyCreated) {
                $this->prepareDeliveries($invoice);
                Audit::record('invoice.assessment', 'invoice:'.$invoice->id);
            }

            return $invoice;
        });
    }

    public function assessmentSplit(int $total): array
    {
        if ($total < 100 || $total > 999999999) {
            throw ValidationException::withMessages(['amount' => 'Enter a total from $1.00 to $9,999,999.99.']);
        }
        $shares = config('association.assessment_percentages');
        $amounts = $remainders = [];
        foreach ($shares as $unit => $percent) {
            $amounts[$unit] = intdiv($total * $percent, 100);
            $remainders[$unit] = ($total * $percent) % 100;
        }
        // Largest remainder, with unit number breaking ties, preserves every cent.
        arsort($remainders, SORT_NUMERIC);
        $remaining = $total - array_sum($amounts);
        foreach (array_keys($remainders) as $unit) {
            if ($remaining-- > 0) {
                $amounts[$unit]++;
            }
        }

        return $amounts;
    }

    public function buildingAssessment(string $title, int $total, string $dueOn, string $requestKey, array $expectedHouseholds): int
    {
        return DB::transaction(function () use ($title, $total, $dueOn, $requestKey, $expectedHouseholds) {
            $units = DB::table('units')->orderBy('number')->lockForUpdate()->get()->keyBy('number');
            $amounts = $this->assessmentSplit($total);
            foreach ($amounts as $number => $amount) {
                $unit = $units->get($number);
                $households = Household::where('unit_id', $unit?->id)->where('active', true)->get();
                if (! $unit || $households->count() !== 1 || ($expectedHouseholds[$number] ?? null) != $households->first()->id) {
                    throw ValidationException::withMessages(['assessment' => 'A unit owner changed or is missing. Preview the assessment again.']);
                }
                $household = $households->first();
                $invoice = Invoice::firstOrCreate(['billing_key' => 'assessment:'.$requestKey.':unit:'.$number], [
                    'household_id' => $household->id, 'unit_id' => $unit->id,
                    'number' => 'BM-SA-'.strtoupper(Str::random(10)), 'issued_on' => now('America/Chicago')->toDateString(), 'due_on' => $dueOn,
                    'total_cents' => $amount, 'items' => [['name' => $title, 'description' => config('association.assessment_percentages')[$number].'% of '.Money::format($total).' building assessment', 'amount_cents' => $amount]],
                ]);
                if ($invoice->wasRecentlyCreated) {
                    $this->prepareDeliveries($invoice);
                    Audit::record('invoice.assessment', 'invoice:'.$invoice->id, ['assessment_total_cents' => $total, 'percentage' => config('association.assessment_percentages')[$number]]);
                }
            }

            return count($amounts);
        });
    }

    private function prepareDeliveries(Invoice $invoice): void
    {
        foreach ($invoice->household->residents()->where('active', true)->get() as $resident) {
            DB::table('invoice_deliveries')->insertOrIgnore(['invoice_id' => $invoice->id, 'user_id' => $resident->id, 'created_at' => now(), 'updated_at' => now()]);
            if (config('association.invoice_emails_enabled')) {
                $id = DB::table('invoice_deliveries')->where('invoice_id', $invoice->id)->where('user_id', $resident->id)->where('send_key', 'automatic')->value('id');
                SendInvoice::dispatch($id)->afterCommit();
            }
        }
    }
}
