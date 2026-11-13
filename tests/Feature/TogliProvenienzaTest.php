<?php

namespace Tests\Feature;

use App\Models\AdesioneSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\SegnalazioneChiusaNotification;
use App\Services\Auth\SincronizzaUtentiAd;
use App\Services\Directory\Directory;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\AllineamentoSeeder;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** 1.0.0: niente provenienza, niente "per conto", niente dati del chiamante. */
class TogliProvenienzaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
    }

    public function test_schema_senza_provenienza_ne_chiamante(): void
    {
        $this->assertFalse(Schema::hasTable('provenienze_segnalazioni'));
        $this->assertFalse(Schema::hasColumn('users', 'id_provenienza'));
        foreach (['id_provenienza', 'segnalante', 'email', 'telefono'] as $c) {
            $this->assertFalse(Schema::hasColumn('segnalazioni', $c), $c);
        }
        foreach (['segnalante', 'email', 'telefono'] as $c) {
            $this->assertFalse(Schema::hasColumn('adesioni_segnalazioni', $c), $c);
        }
    }

    public function test_adesione_unica_per_utente(): void
    {
        $s = Segnalazione::factory()->create();
        $u = User::factory()->create();
        AdesioneSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_utente' => $u->id]);

        $this->expectException(QueryException::class);
        AdesioneSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_utente' => $u->id]);
    }

    public function test_permesso_per_conto_eliminato(): void
    {
        $this->seed(AllineamentoSeeder::class);
        $this->seed(AllineamentoSeeder::class);

        $this->assertTrue(Permission::where('name', 'segnalazioni.per-conto')->doesntExist());
    }

    public function test_sync_ad_senza_gruppo_urp_non_blocca(): void
    {
        $dir = new FakeDirectory();
        $this->app->instance(Directory::class, $dir);
        Notification::fake();
        $u = User::factory()->create(['username' => 'urp1', 'auth_source' => 'ldap', 'ldap_guid' => 'guid-urp1', 'attivo' => true]);
        $u->assignRole('segnalatore');
        $dir->gruppiMancanti = ['PRONTOPA_URP'];
        $dir->aggiungi('pw', FakeDirectory::identita('urp1', ['PRONTOPA_SEGNALATORI'], 'urp1@ente.local', guid: 'guid-urp1'));

        $this->assertSame('ok', app(SincronizzaUtentiAd::class)->esegui()->stato);
    }

    public function test_sync_ad_blocca_se_mancano_urp_e_segnalatori_con_utenti_attivi(): void
    {
        $dir = new FakeDirectory();
        $this->app->instance(Directory::class, $dir);
        Notification::fake();
        $u = User::factory()->create(['username' => 'seg1', 'auth_source' => 'ldap', 'ldap_guid' => 'guid-seg1', 'attivo' => true]);
        $u->assignRole('segnalatore');
        $dir->gruppiMancanti = ['PRONTOPA_URP', 'PRONTOPA_SEGNALATORI'];
        $dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI'], 'm.rossi@ente.local', guid: 'guid-m.rossi'));

        $this->assertSame('fallita', app(SincronizzaUtentiAd::class)->esegui()->stato);
    }

    public function test_urp_diventa_segnalatore_senza_permesso_speciale(): void
    {
        $dir = new FakeDirectory();
        $this->app->instance(Directory::class, $dir);
        Notification::fake();
        $dir->aggiungi('pw', FakeDirectory::identita('urp2', ['PRONTOPA_URP'], 'urp2@ente.local', guid: 'guid-urp2'));

        app(SincronizzaUtentiAd::class)->esegui();

        $u = User::where('username', 'urp2')->firstOrFail();
        $this->assertTrue($u->hasRole('segnalatore'));
        $this->assertTrue($u->getDirectPermissions()->isEmpty());
    }

    public function test_chiusura_avvisa_solo_account_attivo_e_aderenti(): void
    {
        Notification::fake();
        $gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $gestore->assignRole('gestore');
        $autore = User::factory()->create(['attivo' => true]);
        $aderente = User::factory()->create(['attivo' => true]);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_utente_segnalazione' => $autore->id, 'id_operatore_assegnato' => $gestore->id]);
        AdesioneSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_utente' => $aderente->id]);

        $chiudi = \App\Models\Azione::where('codice', 'chiudi')->firstOrFail();
        app(SegnalazioneWorkflowService::class)->eseguiAzione($s, $chiudi->id_azione, $gestore, ['nota' => 'fatto']);

        Notification::assertSentTo($autore, SegnalazioneChiusaNotification::class);
        Notification::assertSentTo($aderente, SegnalazioneChiusaNotification::class);
        Notification::assertSentOnDemandTimes(SegnalazioneChiusaNotification::class, 0);
    }

    public function test_ricerca_segnalante_per_nome_account(): void
    {
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        $gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $gestore->assignRole('gestore');
        $autore = User::factory()->create(['name' => 'Zebedeo Quintavalle']);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_utente_segnalazione' => $autore->id, 'titolo' => 'Rubinetto rotto']);
        Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'titolo' => 'Altra cosa']);

        $this->actingAs($gestore)->get(route('gestione.dashboard', ['q' => 'Quintavalle']))
            ->assertOk()->assertSee('Rubinetto rotto')->assertDontSee('Altra cosa');
    }

    public function test_scuola_riconosciuta_da_spid(): void
    {
        $scuola = User::factory()->create(['auth_source' => 'spid']);
        $scuola->assignRole('segnalatore');
        $urp = User::factory()->create(['auth_source' => 'ldap']);
        $urp->assignRole('segnalatore');

        $this->assertTrue($scuola->segnalaComeScuola());
        $this->assertFalse($urp->segnalaComeScuola());
    }
}
