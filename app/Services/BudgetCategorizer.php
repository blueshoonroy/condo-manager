<?php

namespace App\Services;

use App\Jobs\SuggestBudgetCategories;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BudgetCategorizer
{
    public const CATEGORIES = [
        'dues' => ['Monthly dues', 'income'], 'assessments' => ['Special assessments', 'income'],
        'resident_payments' => ['Resident payments / mixed or unapplied', 'income'], 'interest' => ['Interest', 'income'], 'other_income' => ['Other income', 'income'],
        'electricity' => ['Electricity / ComEd', 'expense'], 'water' => ['Chicago water', 'expense'], 'cleaning' => ['Hallway cleaning', 'expense'],
        'landscaping' => ['Lawn / snow', 'expense'], 'gas' => ['Peoples Gas', 'expense'], 'insurance' => ['Insurance', 'expense'], 'waste' => ['Garbage', 'expense'],
        'repairs' => ['Repairs & maintenance', 'expense'], 'supplies' => ['Supplies', 'expense'], 'legal' => ['Legal / accounting', 'expense'], 'other_expense' => ['Other expenses', 'expense'],
        'transfer' => ['Transfer between association accounts', 'transfer'],
    ];

    public function entries(int $year): Collection
    {
        $banks = DB::table('bank_transactions')->whereNull('removed_at')->whereBetween('posted_on', [$year.'-01-01', $year.'-12-31'])->orderByDesc('posted_on')->orderByDesc('id')->get();
        $saved = DB::table('budget_classifications')->whereIn('bank_transaction_id', $banks->pluck('id'))->get()->keyBy('bank_transaction_id');
        $rules = DB::table('budget_category_rules')->get()->keyBy('description_hash');
        $payments = DB::table('payments')->whereNull('reversed_at')->whereIn('bank_transaction_id', $banks->pluck('id'))->get()->keyBy('bank_transaction_id');
        $allocations = DB::table('payment_allocations')->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')->whereIn('payment_id', $payments->pluck('id'))
            ->select('payment_id', 'items', 'billing_key', 'payment_allocations.amount_cents')->get()->groupBy('payment_id');

        return $banks->map(function ($bank) use ($saved, $rules, $payments, $allocations) {
            $fingerprint = $this->fingerprint($bank);
            $record = $saved->get($bank->id);
            $category = null;
            $source = 'Needs clarification';
            $stale = $record && $record->fingerprint !== $fingerprint;
            if (! $bank->review_required && ! $stale) {
                if ($record?->reviewed_at) {
                    $category = $record->category;
                    $source = $category ? 'Confirmed by a resident' : 'Left open for clarification';
                } elseif ($payment = $payments->get($bank->id)) {
                    $category = 'resident_payments';
                    $applied = $allocations->get($payment->id, collect());
                    $types = $applied->map(fn ($allocation) => $this->invoiceCategory($allocation->billing_key, json_decode($allocation->items, true)))->unique();
                    if ($types->count() === 1 && (int) $applied->sum('amount_cents') === (int) $bank->amount_cents) {
                        $category = $types->first();
                    }
                    $source = 'Linked recorded payment';
                } elseif ($bank->amount_cents < 0) {
                    $rule = $rules->get(hash('sha256', $this->normalize($bank->description)));
                    $category = $rule?->category ?? $this->merchantCategory($bank->description);
                    if ($category) {
                        $source = $rule ? 'Saved vendor rule' : 'Recognized utility vendor';
                    }
                }
            }

            return ['bank' => $bank, 'category' => $category, 'source' => $source, 'fingerprint' => $fingerprint,
                'suggestion' => ! $stale && ! $bank->review_required ? $record : null, 'stale' => $stale,
                'review_state' => hash('sha256', json_encode($record))];
        });
    }

    public function invoiceCategory(?string $key, array $items): string
    {
        if (str_starts_with($key ?? '', 'assessment:')) {
            return 'assessments';
        }
        if (str_starts_with($key ?? '', 'dues:')) {
            return 'dues';
        }
        $names = strtolower(implode(' ', array_map(fn ($item) => ($item['name'] ?? '').' '.($item['description'] ?? ''), $items)));

        return str_contains($names, 'assessment') ? 'assessments' : (str_contains($names, 'dues') ? 'dues' : 'resident_payments');
    }

    public function fingerprint(object $bank): string
    {
        return hash('sha256', json_encode([$bank->posted_on, (int) $bank->amount_cents, $bank->description, (bool) $bank->review_required, $bank->removed_at]));
    }

    public function review(int $id, string $fingerprint, ?string $category, string $note, bool $remember, int $actor): void
    {
        if ($category !== null && ! isset(self::CATEGORIES[$category])) {
            throw ValidationException::withMessages(['category' => 'Choose a valid budget category.']);
        }
        DB::transaction(function () use ($id, $fingerprint, $category, $note, $remember, $actor) {
            $bank = DB::table('bank_transactions')->where('id', $id)->lockForUpdate()->first();
            if (! $bank || $bank->removed_at || $bank->review_required || $this->fingerprint($bank) !== $fingerprint) {
                throw ValidationException::withMessages(['transaction' => 'This bank record changed or its linked payment needs review. Refresh the page and resolve the payment before categorizing it.']);
            }
            if ($remember && (! $category || self::CATEGORIES[$category][1] !== 'expense' || $bank->amount_cents >= 0 || ! $this->canRemember($bank->description))) {
                throw ValidationException::withMessages(['remember' => 'Save rules only for identifiable expense vendors, not checks, deposits, Zelle payments or transfers.']);
            }
            DB::table('budget_classifications')->updateOrInsert(['bank_transaction_id' => $id], [
                'fingerprint' => $fingerprint, 'category' => $category, 'suggested_category' => null, 'confidence' => null, 'reason' => null,
                'note' => $note, 'reviewed_by' => $actor, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($remember) {
                $description = $this->normalize($bank->description);
                DB::table('budget_category_rules')->updateOrInsert(['description_hash' => hash('sha256', $description)], ['description' => $description, 'category' => $category, 'user_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record('budget.transaction_categorized', 'bank:'.$id, ['category' => $category, 'rule_saved' => $remember], $actor);
        });
    }

    public function startAi(int $year, int $actor): int
    {
        $id = DB::transaction(function () use ($year, $actor) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            DB::table('budget_ai_runs')->whereIn('status', ['queued', 'processing'])->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed', 'error' => 'This run timed out. Try again.', 'updated_at' => now()]);
            if (DB::table('budget_ai_runs')->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['ai' => 'An AI budget review is already running. Refresh shortly.']);
            }
            $provider = app(AiSettings::class)->selectedProvider();
            $entries = $this->entries($year)->filter(fn ($entry) => ! $entry['category'] && ! $entry['bank']->review_required && ! $entry['suggestion']?->suggested_category)->take(20);
            if ($entries->isEmpty()) {
                throw ValidationException::withMessages(['ai' => 'No new transactions need AI suggestions. Review the existing suggestions below.']);
            }
            $snapshot = $entries->map(fn ($entry) => ['id' => $entry['bank']->id, 'date' => $entry['bank']->posted_on, 'amount_cents' => (int) $entry['bank']->amount_cents, 'description' => mb_substr($entry['bank']->description, 0, 500), 'fingerprint' => $entry['fingerprint'], 'review_state' => $entry['review_state']])->values()->all();

            return DB::table('budget_ai_runs')->insertGetId(['year' => $year, 'provider' => $provider, 'model' => AiSettings::MODELS[$provider], 'status' => 'queued', 'snapshot' => json_encode($snapshot), 'user_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        });
        SuggestBudgetCategories::dispatch($id);

        return $id;
    }

    public function saveSuggestions(int $runId, array $result): void
    {
        Validator::make($result, ['suggestions' => 'present|array|max:20', 'suggestions.*.id' => 'required|integer|distinct',
            'suggestions.*.category' => 'required|in:unknown,'.implode(',', array_keys(self::CATEGORIES)),
            'suggestions.*.confidence' => 'required|in:high,medium,low', 'suggestions.*.reason' => 'required|string|max:600'])->validate();
        DB::transaction(function () use ($runId, $result) {
            $run = DB::table('budget_ai_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $run || $run->status !== 'processing') {
                return;
            }
            $snapshot = collect(json_decode($run->snapshot, true))->keyBy('id');
            foreach ($result['suggestions'] as $suggestion) {
                $original = $snapshot->get($suggestion['id']);
                $bank = DB::table('bank_transactions')->where('id', $suggestion['id'])->lockForUpdate()->first();
                $saved = DB::table('budget_classifications')->where('bank_transaction_id', $suggestion['id'])->first();
                if (! $original || ! $bank || $bank->removed_at || $bank->review_required || $this->fingerprint($bank) !== $original['fingerprint'] || hash('sha256', json_encode($saved)) !== $original['review_state']) {
                    continue;
                }
                DB::table('budget_classifications')->updateOrInsert(['bank_transaction_id' => $bank->id], [
                    'category' => null, 'reviewed_by' => null, 'reviewed_at' => null,
                    'fingerprint' => $original['fingerprint'], 'suggested_category' => $suggestion['category'], 'confidence' => $suggestion['confidence'],
                    'reason' => $suggestion['reason'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('budget_ai_runs')->where('id', $runId)->update(['status' => 'completed', 'updated_at' => now()]);
        });
    }

    public function canRemember(string $description): bool
    {
        return mb_strlen(trim($description)) >= 8 && ! preg_match('/\b(CHECK|CHEQUE|ZELLE|TRANSFER|DEPOSIT)\b/i', $description);
    }

    private function normalize(string $description): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $description)));
    }

    private function merchantCategory(string $description): ?string
    {
        foreach (['/\bCOMED\b/i' => 'electricity', '/CITY OF CHICAGO.*WATER/i' => 'water', '/PEOPLES GAS/i' => 'gas', '/\bAMTRUST\b/i' => 'insurance', '/SBC WASTE|REPUBLIC SERVICES/i' => 'waste'] as $pattern => $category) {
            if (preg_match($pattern, $description)) {
                return $category;
            }
        }

        return null;
    }
}
