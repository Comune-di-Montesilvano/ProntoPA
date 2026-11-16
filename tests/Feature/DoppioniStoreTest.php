<?php

namespace Tests\Feature;

use App\Models\Istituto;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

/** Con una segnalazione già aperta per stessa sede e tipologia, la nuova si apre solo dichiarandolo. */
class DoppioniStoreTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $urp;

    private int $sede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        $ente = $this->istitutoConPlessi();
        $this->sede = $this->idPlessi($ente)[0];
        $this->urp = User::factory()->create(['auth_source' => 'ldap']);
        $this->urp->assignRole('segnalatore');
    }

    private function aperta(array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create($attr + [
            'id_utente_segnalazione' => $this->urp->id, 'id_plesso' => $this->sede, 'id_tipologia_segnalazione' => 1,
            'id_stato_segnalazione' => 2, 'data_chiusura' => null, 'titolo' => 'Telefono non funzionante',
        ]);
    }

    private function invia(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->urp)->post(route('segnalazioni.store'), $extra + [
            'id_plesso' => $this->sede, 'id_tipologia_segnalazione' => 1,
            'titolo' => 'Ancora il telefono', 'testo_segnalazione' => 'Non funziona.',
        ]);
    }

    private function nuova(): ?Segnalazione
    {
        return Segnalazione::where('titolo', 'Ancora il telefono')->first();
    }

    public function test_store_rifiutato_con_aperta_stessa_sede_tipologia(): void
    {
        $vecchia = $this->aperta();

        $this->invia()->assertSessionHasErrors(['esistente' => "C'è già la segnalazione #{$vecchia->id_segnalazione} aperta per questa sede e tipologia."]);
        $this->assertNull($this->nuova());
    }

    public function test_store_con_diversa_da_crea_e_collega(): void
    {
        $vecchia = $this->aperta();

        $this->invia(['diversa_da' => $vecchia->id_segnalazione])->assertSessionHasNoErrors();

        $this->assertSame($vecchia->id_segnalazione, (int) $this->nuova()?->id_segnalazione_correlata);
    }

    public function test_store_con_esistente_con_preventivo_crea_e_collega_senza_chiedere(): void
    {
        $vecchia = $this->aperta(['id_stato_segnalazione' => 5]);
        StoricoStatoSegnalazione::create([
            'id_segnalazione' => $vecchia->id_segnalazione, 'id_stato_segnalazione' => 5,
            'id_utente' => $this->urp->id, 'id_utente_collegato' => 0, 'id_appalto' => 0,
        ]);

        $this->invia()->assertSessionHasNoErrors();

        $this->assertSame($vecchia->id_segnalazione, (int) $this->nuova()?->id_segnalazione_correlata);
    }

    public function test_store_con_diversa_da_di_una_nel_frattempo_chiusa_passa(): void
    {
        $vecchia = $this->aperta(['id_stato_segnalazione' => 7, 'data_chiusura' => now()]);

        $this->invia(['diversa_da' => $vecchia->id_segnalazione])->assertSessionHasNoErrors();

        $this->assertNotNull($this->nuova());
    }

    public function test_store_con_diversa_da_vecchia_e_nuova_aperta_comparsa_rifiuta_nominando_la_nuova(): void
    {
        $chiusa = $this->aperta(['id_stato_segnalazione' => 7, 'data_chiusura' => now()]);
        $comparsa = $this->aperta();

        $this->invia(['diversa_da' => $chiusa->id_segnalazione])
            ->assertSessionHasErrors(['esistente' => "C'è già la segnalazione #{$comparsa->id_segnalazione} aperta per questa sede e tipologia."]);
    }

    public function test_store_altra_tipologia_passa_senza_collegamento(): void
    {
        $this->aperta(['id_tipologia_segnalazione' => 2]);

        $this->invia()->assertSessionHasNoErrors();

        $this->assertNull($this->nuova()?->id_segnalazione_correlata);
    }

    public function test_esistente_per_l_autore_dice_tua_e_ultima_risposta(): void
    {
        $vecchia = $this->aperta(['data_segnalazione' => now()->subDays(6)]);
        $gestore = User::factory()->create();
        $gestore->assignRole('gestore');
        NotaSegnalazione::create([
            'id_segnalazione' => $vecchia->id_segnalazione, 'testo' => 'Guasto fibra ancora in corso',
            'id_utente' => $gestore->id, 'visibile_web' => true, 'visibile_impresa' => false,
        ]);

        $this->actingAs($this->urp)
            ->getJson(route('segnalazioni.esistente', ['id_plesso' => $this->sede, 'id_tipologia_segnalazione' => 1]))
            ->assertOk()
            ->assertJsonPath('esistente.id', $vecchia->id_segnalazione)
            ->assertJsonPath('esistente.titolo', 'Telefono non funzionante')
            ->assertJsonPath('esistente.tua', true)
            ->assertJsonPath('esistente.aperta_da', '6 giorni fa')
            ->assertJsonPath('esistente.ultima_risposta.testo', 'Guasto fibra ancora in corso');
    }

    public function test_esistente_null_se_non_ce(): void
    {
        $this->actingAs($this->urp)
            ->getJson(route('segnalazioni.esistente', ['id_plesso' => $this->sede, 'id_tipologia_segnalazione' => 1]))
            ->assertOk()
            ->assertJsonPath('esistente', null);
    }
}
