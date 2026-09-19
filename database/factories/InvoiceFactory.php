<?php

namespace Database\Factories;

use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(), 'unit_id' => fn (array $attributes) => Household::findOrFail($attributes['household_id'])->unit_id,
            'number' => 'TEST-'.fake()->unique()->numerify('######'), 'issued_on' => now()->toDateString(), 'due_on' => now()->addDays(30)->toDateString(),
            'total_cents' => 43000, 'items' => [['name' => 'Monthly dues', 'description' => 'Test period', 'amount_cents' => 43000]],
        ];
    }
}
