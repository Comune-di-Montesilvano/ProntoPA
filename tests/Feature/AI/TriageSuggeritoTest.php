<?php

namespace Tests\Feature\AI;

use App\Jobs\SuggerisciTriageSegnalazione;
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

class TriageSuggeritoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function gestore(): User
    {
        $user = User::factory()->create(['supervisore_segnalazioni' => true]);
        $user->assignRole('gestore');
        return $user;
    }

    public function test_creazione_dispatcha_job_triage(): void
    {
        Queue::fake();
        Impostazione::set('ai_enabled', true);

        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Infiltrazione d\'acqua dal tetto aula 3',
            'id_provenienza'            => 1,
        ]);

        Queue::assertPushed(SuggerisciTriageSegnalazione::class);
    }

    public function test_job_salva_triage_suggerito(): void
    {
        $segnalazione = Segnalazione::factory()->create([
            'testo_segnalazione' => 'Infiltrazione d\'acqua dal tetto',
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('generate')->willReturn(
            '{"id_tipologia_segnalazione":2,"id_specializzazione":1,"livello_priorita":3}'
        );

        (new SuggerisciTriageSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $freshed = $segnalazione->fresh();
        $this->assertNotNull($freshed->triage_suggerito);
        $this->assertSame(2, $freshed->triage_suggerito['id_tipologia_segnalazione']);
        $this->assertSame(3, $freshed->triage_suggerito['livello_priorita']);
    }

    public function test_gestore_applica_triage_suggerito(): void
    {
        $segnalazione = Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'livello_priorita'          => 2,
            'triage_suggerito'          => [
                'id_tipologia_segnalazione' => 3,
                'livello_priorita'          => 4,
                'id_specializzazione'       => null,
            ],
        ]);

        $response = $this->actingAs($this->gestore())->post(
            route('segnalazioni.applica-triage', $segnalazione)
        );

        $response->assertRedirect();

        $freshed = $segnalazione->fresh();
        $this->assertSame(3, $freshed->id_tipologia_segnalazione);
        $this->assertSame(4, $freshed->livello_priorita);
        $this->assertNull($freshed->triage_suggerito);
    }

    public function test_segnalatore_non_puo_applicare_triage(): void
    {
        $segnalazione = Segnalazione::factory()->create([
            'triage_suggerito' => ['id_tipologia_segnalazione' => 2, 'livello_priorita' => 3, 'id_specializzazione' => null],
        ]);

        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        $response = $this->actingAs($user)->post(
            route('segnalazioni.applica-triage', $segnalazione)
        );

        $response->assertForbidden();
    }

    public function test_job_ignora_risposta_non_json(): void
    {
        $segnalazione = Segnalazione::factory()->create();

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('generate')->willReturn('risposta non json valida');

        (new SuggerisciTriageSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $this->assertNull($segnalazione->fresh()->triage_suggerito);
    }
}
