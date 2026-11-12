<?php

namespace Tests\Feature\Admin;

use App\Jobs\RicalcolaScadenzeSla;
use App\Models\Impostazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RegoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, RolesAndPermissionsSeeder::class, ImpostazioniSeeder::class]);
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
    }

    private function utente(string $ruolo, array $attributi = []): User
    {
        $u = User::factory()->create($attributi);
        $u->assignRole($ruolo);

        return $u;
    }

    public function test_scadenze_mostra_regole_sla_e_orario(): void
    {
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 48, 'ore_warning' => 4, 'descrizione' => 'Regola di prova xyz']);

        $this->actingAs($this->utente('admin'))->get(route('admin.regole.scadenze'))
            ->assertOk()
            ->assertSee('Regola di prova xyz')
            ->assertSee("Orario d'ufficio", false)
            ->assertSee('25/12/'.now()->year);
    }

    public function test_cambio_orario_ricalcola(): void
    {
        Queue::fake();
        $admin = $this->utente('admin');

        $this->actingAs($admin)->patch(route('admin.regole.orario'), ['impostazioni' => ['orario_lun' => '09:00-13:00']])
            ->assertSessionHasNoErrors();
        Queue::assertPushed(RicalcolaScadenzeSla::class);
        $this->assertSame('09:00-13:00', Impostazione::get('orario_lun'));

        Queue::fake();
        $this->actingAs($admin)->patch(route('admin.regole.orario'), ['impostazioni' => ['orario_lun' => '09:00-13:00']])
            ->assertSessionHasNoErrors();
        Queue::assertNotPushed(RicalcolaScadenzeSla::class);
    }

    public function test_pubblicazione_stato_per_nome(): void
    {
        $admin = $this->utente('admin');

        $this->actingAs($admin)->get(route('admin.regole.segnalazioni'))
            ->assertOk()
            ->assertSeeInOrder(['<select', 'In carico', '</select>'], false);

        $this->actingAs($admin)->patch(route('admin.regole.segnalazioni.update'), [
            'impostazioni' => ['publication_auto_state_id' => '2'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('2', (string) Impostazione::get('publication_auto_state_id'));
    }

    public function test_avvisi_digest_spento(): void
    {
        Impostazione::set('digest_enabled', '1');

        $this->actingAs($this->utente('admin'))->patch(route('admin.regole.avvisi.update'), [
            'impostazioni' => ['digest_ora' => '07:30'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('0', Impostazione::get('digest_enabled'));
        $this->assertSame('07:30', Impostazione::get('digest_ora'));
    }

    public function test_squadre_interruttore_solo_admin(): void
    {
        $supervisore = $this->utente('gestore', ['supervisore_segnalazioni' => true]);
        $this->actingAs($supervisore)->patch(route('organizzazione.squadre.attiva'), [
            'impostazioni' => ['squadre_enabled' => '1'],
        ])->assertForbidden();

        $this->actingAs($this->utente('admin'))->patch(route('organizzazione.squadre.attiva'), [
            'impostazioni' => ['squadre_enabled' => '1'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('1', Impostazione::get('squadre_enabled'));
    }

    public function test_gestore_non_entra_nelle_regole(): void
    {
        $gestore = $this->utente('gestore');

        foreach (['admin.regole.scadenze', 'admin.regole.segnalazioni', 'admin.regole.avvisi'] as $rotta) {
            $this->actingAs($gestore)->get(route($rotta))->assertForbidden();
        }
    }

    public function test_vecchio_indirizzo_sla_reindirizza(): void
    {
        $this->actingAs($this->utente('admin'))->get('/admin/sla')
            ->assertStatus(301)
            ->assertRedirect(route('admin.regole.scadenze'));
    }
}
