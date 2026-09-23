<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InitializeAssociation extends Command
{
    protected $signature = 'association:initialize {roster? : Path to private resident JSON} {--from-env : Read ASSOCIATION_INITIAL_ROSTER_BASE64 instead of a file}';

    protected $description = 'Initialize units and resident access from a private roster (once only)';

    public function handle(): int
    {
        if (DB::table('households')->exists()) {
            $this->error('Already initialized. Manage residents through Administration.');

            return self::FAILURE;
        }
        if ($this->option('from-env')) {
            $json = base64_decode((string) config('association.initial_roster_base64'), true);
        } elseif ($this->argument('roster') && is_readable($this->argument('roster'))) {
            $json = file_get_contents($this->argument('roster'));
        } else {
            $this->error('Provide a readable roster path or use --from-env.');

            return self::FAILURE;
        }
        if (! $json) {
            $this->error('The initial roster is missing or invalid.');

            return self::FAILURE;
        }
        $roster = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        Validator::make(['roster' => $roster], [
            'roster' => 'required|array|min:1', 'roster.*.unit' => 'required|integer|between:1,5',
            'roster.*.dues_cents' => 'required|integer|min:1', 'roster.*.client_name' => 'required|string|max:255|distinct',
            'roster.*.residents' => 'present|array', 'roster.*.residents.*.name' => 'required|string|max:255',
            'roster.*.residents.*.email' => 'required|email|max:254|distinct',
            'roster.*.move_in' => 'nullable|date_format:Y-m-d',
        ])->validate();
        DB::transaction(function () use ($roster) {
            foreach ($roster as $entry) {
                DB::table('units')->insertOrIgnore(['number' => $entry['unit'], 'created_at' => now(), 'updated_at' => now()]);
                $unitId = DB::table('units')->where('number', $entry['unit'])->value('id');
                $active = $entry['active'] ?? true;
                if ($active && Household::where('unit_id', $unitId)->where('active', true)->exists()) {
                    throw new \InvalidArgumentException('Only one active household per unit is allowed.');
                }
                $household = Household::create(['unit_id' => $unitId, 'client_name' => $entry['client_name'], 'active' => $active, 'move_in' => $entry['move_in'] ?? null]);
                if ($active) {
                    DB::table('dues_rates')->insert(['unit_id' => $unitId, 'effective_on' => now('America/Chicago')->startOfMonth()->toDateString(), 'amount_cents' => $entry['dues_cents'], 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ($entry['residents'] as $resident) {
                    User::create(['name' => $resident['name'], 'email' => strtolower($resident['email']), 'password' => Str::random(64), 'household_id' => $household->id, 'active' => $active, 'is_admin' => $active]);
                }
            }
        });
        $this->info('Association initialized. No emails sent.');

        return self::SUCCESS;
    }
}
