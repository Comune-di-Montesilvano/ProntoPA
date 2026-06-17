<?php

namespace Tests\Feature\AI;

use App\Jobs\CalcolaEmbeddingSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\OllamaService;
use App\Support\CosineSimilarity;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DedupSemanticoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        Impostazione::set('ai_enabled', true);
        Impostazione::set('adesioni_enabled', true);
    }

    private function utente(): User
    {
        $user = User::factory()->create();
        $user->assignRole('segnalatore');
        return $user;
    }

    public function test_cosine_similarity_identici(): void
    {
        $v = [1.0, 0.0, 0.0];
        $this->assertEqualsWithDelta(1.0, CosineSimilarity::compute($v, $v), 0.0001);
    }

    public function test_cosine_similarity_ortogonali(): void
    {
        $this->assertEqualsWithDelta(0.0, CosineSimilarity::compute([1.0, 0.0], [0.0, 1.0]), 0.0001);
    }

    public function test_job_salva_embedding(): void
    {
        $segnalazione = Segnalazione::factory()->create([
            'testo_segnalazione' => 'Perdita rubinetto bagno',
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('embed')->willReturn([0.1, 0.2, 0.3]);

        (new CalcolaEmbeddingSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $this->assertSame([0.1, 0.2, 0.3], $segnalazione->fresh()->embedding);
    }

    public function test_creazione_dispatcha_job_embedding(): void
    {
        Queue::fake();

        $user = $this->utente();

        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Testo con embedding',
            'id_provenienza'            => 1,
        ]);

        Queue::assertPushed(CalcolaEmbeddingSegnalazione::class);
    }

    public function test_endpoint_simili_accetta_testo_per_dedup_semantico(): void
    {
        $aperta = Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 5,
            'embedding'                 => [1.0, 0.0, 0.0],
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('isEnabled')->willReturn(true);
        $mockOllama->method('embed')->willReturn([0.99, 0.1, 0.0]);
        $this->app->instance(OllamaService::class, $mockOllama);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', [
                'id_tipologia_segnalazione' => 1,
                'id_plesso'                 => 5,
                'testo'                     => 'Rubinetto che perde acqua',
            ])
        );

        $response->assertOk();
        $response->assertJsonFragment(['id' => $aperta->id_segnalazione]);
    }
}
