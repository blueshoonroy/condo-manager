<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiSettings
{
    public const MODELS = ['openai' => 'gpt-6-sol', 'anthropic' => 'claude-haiku-4-5-20251001'];

    public function publicSettings(): array
    {
        return DB::table('ai_settings')->get()->mapWithKeys(fn ($row) => [$row->provider => ['has_key' => (bool) $row->api_key, 'selected' => (bool) $row->selected]])->all();
    }

    public function save(string $provider, ?string $key, bool $remove, int $actor): void
    {
        DB::transaction(function () use ($provider, $key, $remove, $actor) {
            DB::table('units')->orderBy('id')->lockForUpdate()->get();
            DB::table('ai_settings')->update(['selected' => false]);
            $values = ['selected' => ! $remove, 'updated_at' => now()];
            if ($remove || $key) {
                $values['api_key'] = $remove ? null : Crypt::encryptString($key);
            }
            DB::table('ai_settings')->updateOrInsert(['provider' => $provider], $values);
            Audit::record('ai.settings_updated', $provider, ['key_removed' => $remove], $actor);
        });
    }

    public function selectedProvider(): string
    {
        $row = DB::table('ai_settings')->where('selected', true)->whereNotNull('api_key')->first();
        if (! $row) {
            throw ValidationException::withMessages(['ai' => 'Save an API key in AI settings first.']);
        }

        return $row->provider;
    }

    public function key(string $provider): string
    {
        $value = DB::table('ai_settings')->where('provider', $provider)->value('api_key');
        if (! $value) {
            throw new \RuntimeException('The selected provider has no API key. Save one in AI settings.');
        }

        return Crypt::decryptString($value);
    }
}
