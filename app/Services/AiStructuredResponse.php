<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiStructuredResponse
{
    public function request(string $provider, string $model, string $instruction, array $input, array $schema, string $name): array
    {
        $key = app(AiSettings::class)->key($provider);
        $http = Http::acceptJson()->connectTimeout(10)->timeout(90);
        if ($provider === 'openai') {
            $payload = ['model' => $model, 'store' => false, 'instructions' => $instruction,
                'input' => json_encode($input, JSON_THROW_ON_ERROR), 'max_output_tokens' => 12000,
                'text' => ['format' => ['type' => 'json_schema', 'name' => $name, 'strict' => true, 'schema' => $schema]]];
            if ($model === 'gpt-6-sol') {
                $payload['reasoning'] = ['effort' => 'medium'];
            }
            $response = $http->withToken($key)->post('https://api.openai.com/v1/responses', $payload);
        } else {
            $response = $http->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model, 'max_tokens' => 6000, 'system' => $instruction,
                'messages' => [['role' => 'user', 'content' => json_encode($input, JSON_THROW_ON_ERROR)]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            ]);
        }
        if (! $response->successful()) {
            throw new \RuntimeException('The AI provider returned HTTP '.$response->status().'. Check the API key, model access and credit balance, then try again.');
        }
        $body = $response->json();
        if ($provider === 'openai') {
            if (($body['status'] ?? null) !== 'completed') {
                throw new \RuntimeException('The AI response was incomplete. Try a smaller date range.');
            }
            $text = collect($body['output'] ?? [])->flatMap(fn ($item) => $item['content'] ?? [])->where('type', 'output_text')->pluck('text')->implode('');
        } else {
            if (($body['stop_reason'] ?? null) !== 'end_turn') {
                throw new \RuntimeException('The AI response was incomplete. Try a smaller date range.');
            }
            $text = collect($body['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        }

        return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    }
}
