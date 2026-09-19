<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'issued_on' => 'date', 'due_on' => 'date', 'paid_on' => 'date'];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->is_admin ? $query : $query->where('household_id', $user->household_id ?? 0);
    }

    public function allocatedCents(): int
    {
        return (int) DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('invoice_id', $this->id)->whereNull('payments.reversed_at')->sum('payment_allocations.amount_cents');
    }

    public function balanceCents(): int
    {
        return $this->void_reason ? 0 : max(0, $this->total_cents - $this->historical_paid_cents - $this->allocatedCents());
    }

    public function status(): string
    {
        if ($this->void_reason) {
            return 'void';
        }
        if ($this->balanceCents() === 0) {
            return 'paid';
        }

        return $this->due_on->toDateString() < now('America/Chicago')->toDateString() ? 'overdue' : 'outstanding';
    }
}
