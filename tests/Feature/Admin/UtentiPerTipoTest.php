<?php

namespace Tests\Feature\Admin;

use App\Models\CategoriaEnte;
use App\Models\Delega;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\Istituto;
use App\Models\User;
use App\Services\Auth\EsitoSyncAd;
use App\Services\Directory\Directory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** Admin → Persone: tre elenchi separati per tipo di account. */
class UtentiPerTipoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->instance(Directory::class, new FakeDirectory());
        $this->admin = User::factory()->create(['attivo' => true, 'auth_source' => 'ldap', 'ldap_guid' => 'g-admin', 'name' => 'Capo Admin']);
        $this->admin->assignRole('admin');
    }

    private function persona(string $source, string $ruolo, array $attr = []): User
    {
        $u = User::factory()->create(array_merge(['auth_source' => $source, 'attivo' => true], $attr));
        $u->assignRole($ruolo);

        return $u;
    }

    public function test_index_reindirizza_a_dominio(): void
    {
        $this->actingAs($this->admin)->get(route('admin.utenti.index', ['mostra_disattivati' => 1]))
            ->assertStatus(301)
            ->assertRedirect(route('admin.utenti.dominio', ['mostra_disattivati' => 1]));
    }

    public function test_liste_separate(): void
    {
        $this->persona('ldap', 'gestore', ['name' => 'Dina Dominio']);
        $this->persona('spid', 'segnalatore', ['name' => 'Sara Scuola', 'codice_fiscale' => 'SCLSRA80A41G482X']);
        $impresa = Impresa::factory()->create();
        $this->persona('spid', 'impresa', ['name' => 'Dario Ditta', 'codice_fiscale' => 'DTTDRA80A01G482X', 'id_impresa' => $impresa->id_impresa]);
        $this->persona('locale', 'segnalatore', ['name' => 'Lia Locale']);

        $this->actingAs($this->admin)->get(route('admin.utenti.dominio'))->assertOk()
            ->assertSee('Dina Dominio')->assertDontSee('Sara Scuola')->assertDontSee('Dario Ditta')->assertDontSee('Lia Locale');
        $this->actingAs($this->admin)->get(route('admin.utenti.scuole'))->assertOk()
            ->assertSee('Sara Scuola')->assertDontSee('Dina Dominio')->assertDontSee('Dario Ditta')->assertDontSee('Lia Locale');
        $this->actingAs($this->admin)->get(route('admin.utenti.ditte'))->assertOk()
            ->assertSee('Dario Ditta')->assertDontSee('Dina Dominio')->assertDontSee('Sara Scuola')->assertDontSee('Lia Locale');
    }

    public function test_ricerca_per_codice_fiscale(): void
    {
        $this->persona('spid', 'segnalatore', ['name' => 'Sara Scuola', 'codice_fiscale' => 'RSSMRA80A01G482X']);
        $this->persona('spid', 'segnalatore', ['name' => 'Altra Persona', 'codice_fiscale' => 'VRDLGI80A01G482X']);

        $this->actingAs($this->admin)->get(route('admin.utenti.scuole', ['q' => 'rssmra']))
            ->assertSee('Sara Scuola')->assertDontSee('Altra Persona');
    }

    public function test_ricerca_tratta_percento_e_underscore_come_letterali(): void
    {
        $this->persona('spid', 'segnalatore', ['name' => 'Sara Scuola']);

        $this->actingAs($this->admin)->get(route('admin.utenti.scuole', ['q' => '%']))
            ->assertDontSee('Sara Scuola');
        $this->actingAs($this->admin)->get(route('admin.utenti.scuole', ['q' => 'S_ra']))
            ->assertDontSee('Sara Scuola');
    }

    public function test_dominio_mostra_esito_sync(): void
    {
        Impostazione::set('ldap_sync_esito', json_encode((new EsitoSyncAd(EsitoSyncAd::OK, now(), creati: 3))->toArray()));

        $this->actingAs($this->admin)->get(route('admin.utenti.dominio'))
            ->assertSee('Sincronizza ora')->assertSee('3 creati');
    }

    public function test_ditte_senza_azioni(): void
    {
        $impresa = Impresa::factory()->create();
        $ditta = $this->persona('spid', 'impresa', ['name' => 'Dario Ditta', 'id_impresa' => $impresa->id_impresa]);

        $this->actingAs($this->admin)->get(route('admin.utenti.ditte'))
            ->assertSee('Dario Ditta')
            ->assertDontSee(route('admin.utenti.toggle-attivo', $ditta));
    }

    public function test_scuola_mostra_istituto_delegato(): void
    {
        $istituto = Istituto::create(['id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'codice_meccanografico' => 'TEIC00000X', 'descrizione' => 'IC Prova Delegata', 'attivo' => true]);
        $u = $this->persona('spid', 'segnalatore', ['codice_fiscale' => 'RSSMRA80A01G482X']);
        Delega::create(['user_id' => $u->id, 'codice_fiscale' => $u->codice_fiscale, 'id_istituto' => $istituto->id_istituto, 'stato' => Delega::ATTIVA, 'gruppo_richiesta' => (string) Str::uuid()]);

        $this->actingAs($this->admin)->get(route('admin.utenti.scuole'))
            ->assertSee('IC Prova Delegata');
    }

    public function test_disattivati_nascosti_salvo_richiesta(): void
    {
        $this->persona('spid', 'segnalatore', ['name' => 'Spenta Persona', 'attivo' => false]);

        $this->actingAs($this->admin)->get(route('admin.utenti.scuole'))->assertDontSee('Spenta Persona');
        $this->actingAs($this->admin)->get(route('admin.utenti.scuole', ['mostra_disattivati' => 1]))->assertSee('Spenta Persona');
    }

    public function test_non_admin_non_vede_le_liste(): void
    {
        $gestore = $this->persona('ldap', 'gestore');

        foreach (['dominio', 'scuole', 'ditte'] as $lista) {
            $this->actingAs($gestore)->get(route("admin.utenti.{$lista}"))->assertForbidden();
        }
    }

    public function test_numero_query_non_cresce_con_gli_utenti(): void
    {
        $istituto = Istituto::create(['id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'codice_meccanografico' => 'TEIC00001X', 'descrizione' => 'IC Uno', 'attivo' => true]);
        $impresa = Impresa::factory()->create();

        $crea = function (int $n) use ($istituto, $impresa) {
            for ($i = 0; $i < $n; $i++) {
                $this->persona('ldap', 'gestore');
                $s = $this->persona('spid', 'segnalatore', ['codice_fiscale' => fake()->unique()->bothify('??????##?##?###?')]);
                Delega::create(['user_id' => $s->id, 'codice_fiscale' => $s->codice_fiscale, 'id_istituto' => $istituto->id_istituto, 'stato' => Delega::ATTIVA, 'gruppo_richiesta' => (string) Str::uuid()]);
                $this->persona('spid', 'impresa', ['id_impresa' => $impresa->id_impresa]);
            }
        };
        $conta = function (string $lista): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->get(route("admin.utenti.{$lista}"))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $crea(1);
        // Prima richiesta a vuoto: riempie le cache (permessi, impostazioni) che falserebbero il conteggio.
        foreach (['dominio', 'scuole', 'ditte'] as $lista) {
            $conta($lista);
        }
        $uno = ['dominio' => $conta('dominio'), 'scuole' => $conta('scuole'), 'ditte' => $conta('ditte')];
        $crea(4);
        $cinque = ['dominio' => $conta('dominio'), 'scuole' => $conta('scuole'), 'ditte' => $conta('ditte')];

        $this->assertSame($uno, $cinque);
    }

    public function test_ditta_locale_legacy_non_compare_in_nessun_elenco(): void
    {
        $impresa = Impresa::factory()->create();
        $this->persona('locale', 'impresa', ['name' => 'Ugo Legacy', 'id_impresa' => $impresa->id_impresa]);
        $this->persona('locale', 'segnalatore', ['name' => 'Lia Legacy']);

        foreach (['dominio', 'scuole', 'ditte'] as $lista) {
            $this->actingAs($this->admin)->get(route("admin.utenti.$lista", ['mostra_disattivati' => 1]))
                ->assertOk()->assertDontSee('Ugo Legacy')->assertDontSee('Lia Legacy');
        }
    }

    public function test_modifica_torna_alla_lista_del_tipo(): void
    {
        $spid = $this->persona('spid', 'segnalatore', ['codice_fiscale' => 'RSSMRA80A01H501U']);
        $html = $this->actingAs($this->admin)->get(route('admin.utenti.edit', $spid))->assertOk()->getContent();
        $scuole = preg_quote(route('admin.utenti.scuole'), '#');
        $this->assertMatchesRegularExpression('#href="'.$scuole.'"[^>]*>\s*Indietro#', $html);
        $this->assertMatchesRegularExpression('#href="'.$scuole.'"[^>]*>\s*Annulla#', $html);

        $dom = $this->persona('ldap', 'gestore', ['ldap_guid' => 'g-dom']);
        $html = $this->actingAs($this->admin)->get(route('admin.utenti.edit', $dom))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.utenti.dominio'), '#').'"[^>]*>\s*Indietro#', $html);
    }
}
