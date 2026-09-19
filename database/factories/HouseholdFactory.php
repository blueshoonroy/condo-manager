<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class HouseholdFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => fn () => DB::table('units')->insertGetId(['number' => fake()->unique()->numberBetween(1, 200), 'created_at' => now(), 'updated_at' => now()]),
            'client_name' => fake()->unique()->name(), 'active' => true,
        ];
    }
}
