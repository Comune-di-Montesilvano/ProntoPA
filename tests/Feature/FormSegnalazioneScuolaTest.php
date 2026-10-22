<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

class FormSegnalazioneScuolaTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $scuola;

    private int $plesso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $ist = $this->istitutoConPlessi();
        $this->plesso = $this->idPlessi($ist)[0];
        $this->scuola = $this->utenteSpid();
        $this->scuola->forceFill(['id_provenienza' => 2])->save();
        $this->delega($this->scuola, $ist, null);
    }

    private function dati(array $extra = []): array
    {
        return array_merge([
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione' => 'Perdita acqua bagno',
            'id_plesso' => $this->plesso,
            'id_specializzazione' => 1,
            'ubicazione_tipo' => 1,
        ], $extra);
    }

    public function test_form_scuola_senza_provenienza_mappa_ne_tipo_intervento(): void
    {
        $this->actingAs($this->scuola)->get(route('segnalazioni.create'))
            ->assertOk()
            ->assertDontSee('name="id_provenienza" class', false)
            ->assertSee('<input type="hidden" name="id_provenienza" value="2">', false)
            ->assertSee('Plesso / Sede *')
            ->assertDontSee('Tipo di intervento richiesto')
            ->assertSee('Dove si trova il problema *')
            ->assertDontSee('Non specificato')
            ->assertDontSee('Più dettagli');
    }

    public function test_scuola_provenienza_forzata_e_coordinate_ignorate(): void
    {
        $this->actingAs($this->scuola)->post(route('segnalazioni.store'), $this->dati([
            'id_provenienza' => 1, 'latitudine' => 42.5, 'longitudine' => 14.1,
        ]))->assertSessionHasNoErrors();

        $s = Segnalazione::latest('id_segnalazione')->first();
        $this->assertSame(2, (int) $s->id_provenienza);
        $this->assertSame(0.0, (float) $s->latitudine);
    }

    public function test_scuola_deve_indicare_plesso_e_ubicazione_ma_non_allegati(): void
    {
        $this->actingAs($this->scuola)->post(route('segnalazioni.store'), $this->dati([
            'id_plesso' => null, 'id_specializzazione' => null, 'ubicazione_tipo' => 0,
        ]))->assertSessionHasErrors(['id_plesso', 'ubicazione_tipo'])
            ->assertSessionDoesntHaveErrors(['allegati', 'id_specializzazione']);
    }

    public function test_segnalatore_interno_non_sceglie_provenienza_ma_dettagli_facoltativi(): void
    {
        $interno = User::factory()->create(['id_provenienza' => 1]);
        $interno->assignRole('segnalatore');

        $this->actingAs($interno)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1, 'testo_segnalazione' => 'Lampione spento', 'id_provenienza' => 3,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, (int) Segnalazione::latest('id_segnalazione')->first()->id_provenienza);
    }

    public function test_gestore_sceglie_ancora_la_provenienza(): void
    {
        $gestore = User::factory()->create(['id_provenienza' => 1]);
        $gestore->assignRole('gestore');

        $this->actingAs($gestore)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1, 'testo_segnalazione' => 'Buca in strada', 'id_provenienza' => 4,
        ])->assertSessionHasNoErrors();

        $this->assertSame(4, (int) Segnalazione::latest('id_segnalazione')->first()->id_provenienza);
    }
}
