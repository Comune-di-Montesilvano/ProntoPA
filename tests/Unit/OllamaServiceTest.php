<?php

namespace Tests\Unit;

use App\Models\Impostazione;
use App\Services\OllamaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_restituisce_null_se_ai_disabilitata(): void
    {
        Impostazione::set('ai_enabled', false);

        $service = new OllamaService();
        $result = $service->generate('test');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_generate_chiama_ollama_e_restituisce_risposta(): void
    {
        Impostazione::set('ai_enabled', true);
        Impostazione::set('ai_ollama_url', 'http://ollama-test:11434');
        Impostazione::set('ai_modello', 'qwen2.5:3b');

        Http::fake([
            'http://ollama-test:11434/api/generate' => Http::response([
                'response' => 'Lampada fulminata corridoio piano primo',
            ]),
        ]);

        $service = new OllamaService();
        $result = $service->generate('Lampada non funziona nel corridoio');

        $this->assertSame('Lampada fulminata corridoio piano primo', $result);
    }

    public function test_generate_restituisce_null_su_errore_http(): void
    {
        Impostazione::set('ai_enabled', true);
        Impostazione::set('ai_ollama_url', 'http://ollama-test:11434');

        Http::fake([
            'http://ollama-test:11434/api/generate' => Http::response([], 500),
        ]);

        $service = new OllamaService();
        $result = $service->generate('test');

        $this->assertNull($result);
    }

    public function test_embed_restituisce_vettore(): void
    {
        Impostazione::set('ai_enabled', true);
        Impostazione::set('ai_ollama_url', 'http://ollama-test:11434');

        Http::fake([
            'http://ollama-test:11434/api/embeddings' => Http::response([
                'embedding' => [0.1, 0.2, 0.3],
            ]),
        ]);

        $service = new OllamaService();
        $result = $service->embed('testo di test');

        $this->assertSame([0.1, 0.2, 0.3], $result);
    }

    public function test_embed_restituisce_null_se_ai_disabilitata(): void
    {
        Impostazione::set('ai_enabled', false);

        $service = new OllamaService();
        $result = $service->embed('testo');

        $this->assertNull($result);
    }
}
