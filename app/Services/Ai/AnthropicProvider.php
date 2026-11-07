<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Anthropic Messages API. */
class AnthropicProvider implements WordingProvider
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl,
        private string $version,
    ) {
    }

    public function complete(string $system, string $user, int $maxTokens, int $timeoutSeconds): ?array
    {
        if (! $this->apiKey) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->version,
            ])
                ->connectTimeout(min(2, $timeoutSeconds))
                ->timeout($timeoutSeconds)
                ->acceptJson()
                ->post($this->baseUrl.'/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                ]);

            if (! $response->successful()) {
                return null;
            }

            $text = '';
            foreach ((array) $response->json('content', []) as $block) {
                if (($block['type'] ?? null) === 'text') {
                    $text .= $block['text'] ?? '';
                }
            }

            $text = trim($text);

            return $text === '' ? null : ['text' => $text, 'truncated' => $response->json('stop_reason') === 'max_tokens'];
        } catch (Throwable) {
            return null; // timeout, connection error, bad JSON: the caller falls back to the scripted question
        }
    }
}
