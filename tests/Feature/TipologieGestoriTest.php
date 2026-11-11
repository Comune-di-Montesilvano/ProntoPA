<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class TipologieGestoriTest extends TestCase
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

    private function supervisore(): User
    {
        return $this->utente('gestore', ['gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true]);
    }

    private function admin(): User
    {
        return $this->utente('admin', ['amministratore' => true]);
    }

    public function test_supervisore_e_admin_accedono_gestore_no(): void
    {
        $url = route('organizzazione.tipologie-gestori.index');

        $this->actingAs($this->supervisore())->get($url)->assertOk();
        $this->actingAs($this->admin())->get($url)->assertOk();
        $this->actingAs($this->utente('gestore', ['gestore_segnalazioni' => true]))->get($url)->assertForbidden();
        $this->actingAs($this->utente('operaio'))->get($url)->assertForbidden();
    }

    public function test_supervisore_salva_tipologie_di_un_gestore(): void
    {
        $gestore = $this->utente('gestore', ['gestore_segnalazioni' => true, 'gestore_tutte_tipologie' => true]);

        $this->actingAs($this->supervisore())
            ->put(route('organizzazione.tipologie-gestori.update', $gestore), ['tutte' => '0', 'tipologie' => [1, 3]])
            ->assertRedirect(route('organizzazione.tipologie-gestori.index'));

        $gestore->refresh();
        $this->assertFalse($gestore->gestore_tutte_tipologie);
        $this->assertSame([1, 3], $gestore->tipologieGestite()->orderBy('tipologie_segnalazioni.id_tipologia_segnalazione')
            ->pluck('tipologie_segnalazioni.id_tipologia_segnalazione')->map(fn ($id) => (int) $id)->all());
    }

    public function test_admin_in_elenco_con_sole_tipologie_esplicite(): void
    {
        $admin = $this->utente('admin', ['amministratore' => true, 'name' => 'Admin Telefonia']);

        $this->actingAs($this->supervisore())->get(route('organizzazione.tipologie-gestori.index'))
            ->assertSee('Admin Telefonia');
        $this->get(route('organizzazione.tipologie-gestori.edit', $admin))->assertOk()
            ->assertDontSee('Tutte le tipologie, anche quelle aggiunte in futuro');

        $this->put(route('organizzazione.tipologie-gestori.update', $admin), ['tutte' => 1, 'tipologie' => [2]])
            ->assertRedirect(route('organizzazione.tipologie-gestori.index'));

        $this->assertFalse((bool) $admin->fresh()->gestore_tutte_tipologie);
        $this->assertSame([2], $admin->tipologieGestite()->pluck('tipologie_segnalazioni.id_tipologia_segnalazione')->map(fn ($id) => (int) $id)->all());
    }

    public function test_non_si_modificano_supervisori(): void
    {
        $altro = $this->supervisore();

        $this->actingAs($this->supervisore())
            ->put(route('organizzazione.tipologie-gestori.update', $altro), ['tutte' => '1'])
            ->assertNotFound();
    }

    public function test_index_mostra_orfane(): void
    {
        $this->utente('gestore', ['gestore_segnalazioni' => true])->tipologieGestite()->sync([1]);

        $this->actingAs($this->supervisore())->get(route('organizzazione.tipologie-gestori.index'))
            ->assertOk()
            ->assertSeeInOrder(['Tipologie senza gestore', 'ARREDI']);
    }

    public function test_supervisore_gestisce_squadre(): void
    {
        $capo = $this->utente('operaio');

        $this->actingAs($this->supervisore())->post(route('organizzazione.squadre.store'), [
            'nome' => 'Squadra Impianti', 'id_caposquadra' => $capo->id, 'membri' => [$capo->id],
        ])->assertRedirect(route('organizzazione.squadre.index'));

        $this->assertTrue(Squadra::where('nome', 'Squadra Impianti')->exists());
    }

    public function test_banner_orfana_in_scheda(): void
    {
        $s = Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 9,
            'id_stato_segnalazione' => SegnalazioneStato::NUOVA->value,
        ]);

        $this->actingAs($this->supervisore())->get(route('segnalazioni.show', $s))
            ->assertOk()
            ->assertSee('Nessun gestore copre la tipologia');
    }

    public function test_avviso_orfane_in_dashboard_supervisore(): void
    {
        $this->actingAs($this->supervisore())->get(route('gestione.dashboard'))
            ->assertOk()
            ->assertSee('Tipologie senza gestore');

        $this->actingAs($this->utente('gestore', ['gestore_segnalazioni' => true]))->get(route('gestione.dashboard'))
            ->assertOk()
            ->assertDontSee('Tipologie senza gestore');
    }
}
