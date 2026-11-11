<?php

namespace Tests\Feature\Admin;

use App\Jobs\SincronizzaUtentiAdJob;
use App\Models\Impostazione;
use App\Models\User;
use App\Services\Auth\EsitoSyncAd;
use App\Services\Directory\Directory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** Admin → Utenti: "Sincronizza ora", esito dell'ultimo giro, attivo gestito da AD. */
class SyncAdAdminTest extends TestCase
{
    use RefreshDatabase;

    private FakeDirectory $dir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dir = new FakeDirectory();
        $this->app->instance(Directory::class, $this->dir);
        $this->admin = User::factory()->create(['attivo' => true, 'amministratore' => true, 'auth_source' => 'ldap', 'ldap_guid' => 'g-admin']);
        $this->admin->assignRole('admin');
    }

    private function esito(EsitoSyncAd $esito): void
    {
        Impostazione::set('ldap_sync_esito', json_encode($esito->toArray()));
    }

    public function test_admin_avvia_la_sync_in_coda(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)->post(route('admin.utenti.sincronizza'))
            ->assertRedirect()->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.utenti.sincronizza'), ['forza' => '1']);

        Queue::assertPushed(SincronizzaUtentiAdJob::class, fn ($j) => $j->forza === false);
        Queue::assertPushed(SincronizzaUtentiAdJob::class, fn ($j) => $j->forza === true);
    }

    public function test_non_admin_non_puo_sincronizzare(): void
    {
        Queue::fake();
        $gestore = User::factory()->create(['attivo' => true]);
        $gestore->assignRole('gestore');

        $this->actingAs($gestore)->post(route('admin.utenti.sincronizza'))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_index_mostra_pulsante_ed_esito(): void
    {
        $this->esito(new EsitoSyncAd(EsitoSyncAd::OK, now(), creati: 3, disattivati: 2));

        $this->actingAs($this->admin)->get(route('admin.utenti.index'))
            ->assertOk()
            ->assertSee('Sincronizza ora')
            ->assertSee('3 creati')
            ->assertSee('2 disattivati')
            ->assertDontSee('Sincronizza comunque');
    }

    public function test_index_senza_sync_configurata_niente_pulsante(): void
    {
        $this->dir->sync = false;

        $this->actingAs($this->admin)->get(route('admin.utenti.index'))
            ->assertOk()
            ->assertDontSee('Sincronizza ora');
    }

    public function test_esito_bloccato_offre_sincronizza_comunque(): void
    {
        $this->esito(new EsitoSyncAd(EsitoSyncAd::BLOCCATA, now(), daDisattivare: 9, motivo: 'Il giro disattiverebbe 9 utenti'));

        $this->actingAs($this->admin)->get(route('admin.utenti.index'))
            ->assertSee('Il giro disattiverebbe 9 utenti')
            ->assertSee('Sincronizza comunque');
    }

    public function test_utente_ldap_non_si_disattiva_a_mano(): void
    {
        $ldap = User::factory()->create(['attivo' => true, 'auth_source' => 'ldap', 'username' => 'm.rossi']);
        $ldap->assignRole('gestore');

        $this->actingAs($this->admin)->patch(route('admin.utenti.toggle-attivo', $ldap))->assertForbidden();
        $this->assertTrue((bool) $ldap->fresh()->attivo);

        $this->actingAs($this->admin)->get(route('admin.utenti.index'))
            ->assertSee('Gestito da AD');
    }

    public function test_utente_spid_si_disattiva_ancora(): void
    {
        $spid = User::factory()->create(['attivo' => true, 'auth_source' => 'spid']);

        $this->actingAs($this->admin)->patch(route('admin.utenti.toggle-attivo', $spid))->assertRedirect();

        $this->assertFalse((bool) $spid->fresh()->attivo);
    }

    public function test_modifica_utente_ldap_non_cambia_attivo(): void
    {
        $ldap = User::factory()->create(['attivo' => true, 'auth_source' => 'ldap', 'username' => 'm.rossi', 'email' => 'm.rossi@ente.local']);
        $ldap->assignRole('gestore');

        $this->actingAs($this->admin)->put(route('admin.utenti.update', $ldap), [
            'name' => 'Mario Rossi', 'username' => 'm.rossi', 'email' => 'm.rossi@ente.local', 'ruolo' => 'gestore',
        ])->assertRedirect(route('admin.utenti.index'));

        $this->assertTrue((bool) $ldap->fresh()->attivo);
        $this->actingAs($this->admin)->get(route('admin.utenti.edit', $ldap))
            ->assertDontSee('Account attivo')
            ->assertSee('Gestito da Active Directory');
    }

    public function test_tipologie_gestori_testo_con_sync(): void
    {
        $this->actingAs($this->admin)->get(route('organizzazione.tipologie-gestori.index'))
            ->assertOk()
            ->assertDontSee('dopo il loro primo accesso');

        $this->dir->sync = false;
        $this->actingAs($this->admin)->get(route('organizzazione.tipologie-gestori.index'))
            ->assertSee('dopo il loro primo accesso');
    }
}
