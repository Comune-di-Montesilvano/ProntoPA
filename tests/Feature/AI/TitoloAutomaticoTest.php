<?php

namespace Tests\Feature\AI;

use App\Jobs\GeneraTitoloSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\OllamaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TitoloAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_creazione_dispatcha_job_se_ai_abilitata(): void
    {
        Queue::fake();
        Impostazione::set('ai_enabled', true);

        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Perdita acqua dal rubinetto bagno docce piano terra',
            'id_provenienza'            => 1,
        ]);

        Queue::assertPushed(GeneraTitoloSegnalazione::class);
    }

    public function test_creazione_non_dispatcha_job_se_ai_disabilitata(): void
    {
        Queue::fake();
        Impostazione::set('ai_enabled', false);

        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Test disabilitato',
            'id_provenienza'            => 1,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_job_salva_titolo_generato(): void
    {
        Impostazione::set('ai_enabled', true);

        $segnalazione = Segnalazione::factory()->create([
            'testo_segnalazione' => 'Perdita acqua dal rubinetto bagno docce piano terra',
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('isEnabled')->willReturn(true);
        $mockOllama->method('generate')->willReturn('Perdita rubinetto bagno piano terra');

        (new GeneraTitoloSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $this->assertSame('Perdita rubinetto bagno piano terra', $segnalazione->fresh()->titolo_generato);
    }

    public function test_job_non_sovrascrive_titolo_se_risposta_vuota(): void
    {
        Impostazione::set('ai_enabled', true);

        $segnalazione = Segnalazione::factory()->create([
            'testo_segnalazione' => 'Test testo',
            'titolo_generato'    => null,
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('isEnabled')->willReturn(true);
        $mockOllama->method('generate')->willReturn(null);

        (new GeneraTitoloSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $this->assertNull($segnalazione->fresh()->titolo_generato);
    }
}
