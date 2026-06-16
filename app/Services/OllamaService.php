<?php

namespace App\Services;

use App\Models\Impostazione;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaService
{
    public function isEnabled(): bool
    {
        return (bool) Impostazione::get('ai_enabled', false);
    }

    /**
     * Genera testo con il modello di completamento.
     * Restituisce null se AI disabilitata o Ollama non raggiungibile.
     */
    public function generate(string $prompt): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = Http::timeout(30)->post($this->baseUrl() . '/api/generate', [
                'model'  => Impostazione::get('ai_modello', 'qwen2.5:3b'),
                'prompt' => $prompt,
                'stream' => false,
            ]);

            if ($response->successful()) {
                return trim((string) $response->json('response', '')) ?: null;
            }
        } catch (\Throwable $e) {
            Log::warning('OllamaService::generate failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Genera un vettore embedding per il testo fornito.
     * Restituisce null se AI disabilitata o Ollama non raggiungibile.
     *
     * @return float[]|null
     */
    public function embed(string $text): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = Http::timeout(30)->post($this->baseUrl() . '/api/embeddings', [
                'model'  => Impostazione::get('ai_embedding_modello', 'nomic-embed-text'),
                'prompt' => $text,
            ]);

            if ($response->successful()) {
                $embedding = $response->json('embedding');
                if (is_array($embedding) && count($embedding) > 0) {
                    return $embedding;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('OllamaService::embed failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) Impostazione::get('ai_ollama_url', 'http://ollama:11434'), '/');
    }
}
