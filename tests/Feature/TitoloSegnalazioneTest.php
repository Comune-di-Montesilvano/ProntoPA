<?php

namespace Tests\Feature;

use App\Jobs\GeneraTitoloSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\OllamaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** Titolo breve (max 100) scritto da chi segnala, obbligatorio nel form, facoltativo via API. */
class TitoloSegnalazioneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function utente(string $ruolo, array $attributi = []): User
    {
        $user = User::factory()->create(array_merge(['attivo' => true], $attributi));
        $user->assignRole($ruolo);

        return $user;
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione' => 'Con la presente si richiede la fornitura di 15 panchine con schienale per le sezioni del plesso.',
            'id_plesso'      => \Tests\Support\SedeDiProva::id(),
        ], $extra);
    }

    public function test_titolo_obbligatorio_nel_form(): void
    {
        $this->actingAs($this->utente('segnalatore'))
            ->post(route('segnalazioni.store'), $this->payload())
            ->assertSessionHasErrors('titolo');

        $this->assertSame(0, Segnalazione::count());
    }

    public function test_titolo_massimo_100_caratteri(): void
    {
        $this->actingAs($this->utente('segnalatore'))
            ->post(route('segnalazioni.store'), $this->payload(['titolo' => str_repeat('a', 101)]))
            ->assertSessionHasErrors('titolo');
    }

    public function test_titolo_salvato_e_mostrato_in_scheda(): void
    {
        $user = $this->utente('segnalatore');

        $this->actingAs($user)->post(route('segnalazioni.store'), $this->payload(['titolo' => 'Fornitura 15 panchine Colonnetta']))
            ->assertSessionHasNoErrors();

        $s = Segnalazione::sole();
        $this->assertSame('Fornitura 15 panchine Colonnetta', $s->titolo);
        $this->actingAs($user)->get(route('segnalazioni.show', $s))->assertSee('Fornitura 15 panchine Colonnetta');
    }

    public function test_api_titolo_facoltativo(): void
    {
        $this->actingAs($this->utente('segnalatore'));

        $this->postJson('/api/segnalazioni', $this->payload(['force' => true]))->assertCreated();
        $this->postJson('/api/segnalazioni', $this->payload(['force' => true, 'titolo' => 'Panchine']))->assertCreated();
        $this->postJson('/api/segnalazioni', $this->payload(['force' => true, 'titolo' => str_repeat('a', 101)]))->assertUnprocessable();

        $this->assertSame([null, 'Panchine'], Segnalazione::orderBy('id_segnalazione')->pluck('titolo')->all());
    }

    public function test_senza_titolo_si_legge_il_testo_troncato(): void
    {
        $s = Segnalazione::factory()->create(['titolo' => null, 'testo_segnalazione' => str_repeat('parola ', 40)]);

        $this->assertSame(\Illuminate\Support\Str::limit(str_repeat('parola ', 40), 90), $s->titoloLeggibile());
        $s->titolo = 'Breve';
        $this->assertSame('Breve', $s->titoloLeggibile());
    }

    public function test_gestore_modifica_titolo_ditta_e_segnalante_no(): void
    {
        $s = Segnalazione::factory()->create(['titolo' => 'Vecchio']);
        $gestore = $this->utente('gestore', ['gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true]);

        $this->actingAs($gestore)->patch(route('segnalazioni.titolo', $s), ['titolo' => 'Nuovo titolo'])
            ->assertRedirect(route('segnalazioni.show', $s->id_segnalazione));
        $this->assertSame('Nuovo titolo', $s->fresh()->titolo);

        $this->actingAs($this->utente('segnalatore'))->patch(route('segnalazioni.titolo', $s), ['titolo' => 'X'])->assertForbidden();
        $this->actingAs($this->utente('impresa'))->patch(route('segnalazioni.titolo', $s), ['titolo' => 'X'])->assertForbidden();
        $this->assertSame('Nuovo titolo', $s->fresh()->titolo);
    }

    public function test_ai_scrive_il_titolo_solo_se_vuoto(): void
    {
        Impostazione::set('ai_enabled', true);
        $ollama = $this->createMock(OllamaService::class);
        $ollama->method('isEnabled')->willReturn(true);
        $ollama->method('generate')->willReturn('Titolo AI');

        $vuoto = Segnalazione::factory()->create(['titolo' => null]);
        $scritto = Segnalazione::factory()->create(['titolo' => 'Scritto da chi segnala']);

        (new GeneraTitoloSegnalazione($vuoto->id_segnalazione))->handle($ollama);
        (new GeneraTitoloSegnalazione($scritto->id_segnalazione))->handle($ollama);

        $this->assertSame('Titolo AI', $vuoto->fresh()->titolo);
        $this->assertSame('Scritto da chi segnala', $scritto->fresh()->titolo);
    }
}
