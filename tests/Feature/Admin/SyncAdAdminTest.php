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
use Illuminate\Support\Facades\Cache;
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
        Cache::forget(SincronizzaUtentiAdJob::CHIAVE_IN_CODA); // il job finto non gira
        $this->actingAs($this->admin)->post(route('admin.utenti.sincronizza'), ['forza' => '1', 'confermati' => '9']);

        Queue::assertPushed(SincronizzaUtentiAdJob::class, fn ($j) => $j->forza === false && $j->confermati === null);
        Queue::assertPushed(SincronizzaUtentiAdJob::class, fn ($j) => $j->forza === true && $j->confermati === 9);
    }

    public function test_forza_senza_numero_confermato_rifiutata(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)->post(route('admin.utenti.sincronizza'), ['forza' => '1'])
            ->assertSessionHasErrors('confermati');

        Queue::assertNothingPushed();
    }

    public function test_doppio_clic_non_accoda_due_giri(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)->post(route('admin.utenti.sincronizza'))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.utenti.sincronizza'))->assertSessionHas('warning');

        Queue::assertPushed(SincronizzaUtentiAdJob::class, 1);
        $this->actingAs($this->admin)->get(route('admin.utenti.index'))
            ->assertSee('Sincronizzazione in corso')
            ->assertDontSee('Sincronizza ora');
    }

    public function test_giro_notturno_in_corso_avvisa_chi_clicca(): void
    {
        Queue::fake();
        Cache::put('ldap-sync-attivo', true, 600);

        $this->actingAs($this->admin)->post(route('admin.utenti.sincronizza'))->assertSessionHas('warning');

        Queue::assertNothingPushed();
    }

    public function test_job_finito_libera_il_pulsante(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI']));

        $this->actingAs($this->admin)->post(route('admin.utenti.sincronizza'))->assertSessionHas('success');

        $this->assertFalse(Cache::has(SincronizzaUtentiAdJob::CHIAVE_IN_CODA));
        $this->assertTrue(User::where('username', 'm.rossi')->exists());
    }

    public function test_sincronizza_comunque_manda_il_numero_visto(): void
    {
        $this->esito(new EsitoSyncAd(EsitoSyncAd::BLOCCATA, now(), daDisattivare: 9, motivo: 'x'));

        $this->actingAs($this->admin)->get(route('admin.utenti.index'))
            ->assertSee('name="confermati" value="9"', false);
    }

    public function test_esito_mostra_errori(): void
    {
        $this->esito(new EsitoSyncAd(EsitoSyncAd::OK, now(), creati: 1, errori: 2));

        $this->actingAs($this->admin)->get(route('admin.utenti.index'))
            ->assertSee('2 non allineati per errore');
    }

    public function test_modifica_utente_ldap_cambia_solo_telefono_e_profilo(): void
    {
        $ldap = User::factory()->create(['attivo' => true, 'auth_source' => 'ldap', 'username' => 'm.rossi',
            'name' => 'Mario Rossi', 'email' => 'm.rossi@ente.local', 'supervisore_segnalazioni' => false]);
        $ldap->assignRole('gestore');

        $this->actingAs($this->admin)->put(route('admin.utenti.update', $ldap), [
            'name' => 'Altro', 'username' => 'altro', 'email' => 'altro@x.it', 'ruolo' => 'admin',
            'supervisore' => '1', 'id_provenienza' => 3, 'telefono' => '085 123',
        ])->assertRedirect(route('admin.utenti.index'));

        $f = $ldap->fresh();
        $this->assertSame('Mario Rossi', $f->name);
        $this->assertSame('m.rossi', $f->username);
        $this->assertSame('m.rossi@ente.local', $f->email);
        $this->assertTrue($f->hasRole('gestore'));
        $this->assertFalse($f->hasRole('admin'));
        $this->assertFalse((bool) $f->supervisore_segnalazioni);
        $this->assertSame('085 123', $f->telefono);

        $this->actingAs($this->admin)->get(route('admin.utenti.edit', $ldap))
            ->assertDontSee('name="ruolo"', false)
            ->assertDontSee('name="email"', false)
            ->assertSee('name="telefono"', false);
    }

    public function test_modifica_utente_spid_cambia_ancora_il_ruolo(): void
    {
        $spid = User::factory()->create(['attivo' => true, 'auth_source' => 'spid', 'username' => 'RSSMRA80A01G482X', 'email' => 's@x.it']);
        $spid->assignRole('segnalatore');

        $this->actingAs($this->admin)->put(route('admin.utenti.update', $spid), [
            'name' => 'Nuovo', 'username' => 'RSSMRA80A01G482X', 'email' => 's@x.it', 'ruolo' => 'gestore', 'attivo' => '1',
        ])->assertRedirect(route('admin.utenti.index'));

        $this->assertTrue($spid->fresh()->hasRole('gestore'));
        $this->assertSame('Nuovo', $spid->fresh()->name);
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

    /** Bug 0.9.14: l'esito (JSON, motivo lungo) compariva nel form e ne bloccava il salvataggio (max 500). */
    public function test_esito_sync_non_compare_ne_blocca_le_impostazioni(): void
    {
        $this->esito(new EsitoSyncAd(EsitoSyncAd::FALLITA, now(), motivo: str_repeat('Gruppi non trovati in Active Directory. ', 20)));
        $prima = Impostazione::get('ldap_sync_esito');

        $this->actingAs($this->admin)->get(route('admin.impostazioni.index'))
            ->assertOk()
            ->assertDontSee('ldap_sync_esito');

        $this->actingAs($this->admin)->patch(route('admin.impostazioni.update'), [
            'impostazioni' => ['ente_nome' => 'Comune di Prova', 'ldap_sync_esito' => 'manomesso'],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('Comune di Prova', Impostazione::get('ente_nome'));
        $this->assertSame($prima, Impostazione::get('ldap_sync_esito'));
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
