<?php

namespace Tests\Unit;

use App\Models\Segnalazione;
use App\Services\WebhookService;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TabelleRiferimentoSeeder::class);
    }

    public function test_non_chiama_nulla_se_url_non_configurato_ne_in_impostazioni_ne_in_env(): void
    {
        Http::fake();
        Config::set('services.webhook_cittadini.url', null);

        (new WebhookService)->notificaCambioStato(Segnalazione::factory()->create());

        Http::assertNothingSent();
    }

    public function test_usa_config_env_come_fallback_se_impostazioni_vuote(): void
    {
        // config() e non env() nel service: altrimenti torna null con config:cache attivo.
        Config::set('services.webhook_cittadini.url', 'https://comune.example.it/webhook');
        Config::set('services.webhook_cittadini.secret', 'segreto-env');
        Http::fake();

        (new WebhookService)->notificaCambioStato(Segnalazione::factory()->create());

        Http::assertSent(fn ($request) => $request->url() === 'https://comune.example.it/webhook');
    }
}
