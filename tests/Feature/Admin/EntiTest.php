<?php

namespace Tests\Feature\Admin;

use App\Models\CategoriaEnte;
use App\Models\Delega;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

class EntiTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['amministratore' => true, 'attivo' => true, 'approval_status' => 'approved']);
        $this->admin->syncRoles(['admin']);
    }

    private function ente(CategoriaEnte $cat, string $nome = 'Ente X', array $extra = []): Istituto
    {
        return Istituto::create(array_merge(['descrizione' => $nome, 'id_categoria' => $cat->id_categoria, 'attivo' => true], $extra));
    }

    public function test_elenco_raggruppa_per_categoria_e_mostra_sedi(): void
    {
        $a = CategoriaEnte::factory()->create(['nome' => 'Cat Alfa', 'ordine' => 1]);
        $b = CategoriaEnte::factory()->create(['nome' => 'Cat Beta', 'ordine' => 2]);
        $ente = $this->ente($a, 'Ente Uno');
        $this->ente($b, 'Ente Due');
        Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Sede Prima']);
        Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Sede Seconda']);

        $this->actingAs($this->admin)->get(route('admin.enti.index'))
            ->assertOk()
            ->assertSeeInOrder(['Cat Alfa', 'Ente Uno', 'Sede Prima', 'Sede Seconda', 'Cat Beta', 'Ente Due']);

        $this->actingAs($this->admin)->get(route('admin.enti.index', ['categoria' => $b->id_categoria]))
            ->assertSee('Ente Due')->assertDontSee('Ente Uno');
    }

    public function test_crea_ente_a_mano_poi_sede_nella_scheda(): void
    {
        $cat = CategoriaEnte::factory()->create();
        $this->actingAs($this->admin)->get(route('admin.enti.create'))->assertOk()->assertSee('Cerca su MIUR');

        $risposta = $this->actingAs($this->admin)->post(route('admin.enti.store'), [
            'descrizione' => 'Asilo Nido', 'id_categoria' => $cat->id_categoria,
        ]);
        $ente = Istituto::where('descrizione', 'Asilo Nido')->sole();
        $risposta->assertRedirect(route('admin.enti.show', $ente));
        $this->assertSame('manuale', $ente->fonte_dati);

        $this->actingAs($this->admin)->post(route('admin.enti.sedi.store', $ente), ['nome' => 'Sede Centrale'])
            ->assertRedirect(route('admin.enti.show', $ente));

        $sede = Plesso::where('nome', 'Sede Centrale')->sole();
        $this->assertSame($ente->id_istituto, $sede->id_istituto);
        $this->assertSame('manuale', $sede->fonte_dati);

        $this->actingAs($this->admin)->get(route('admin.enti.show', $ente))->assertOk()->assertSee('Sede Centrale');
    }

    public function test_campi_miur_bloccati_sulla_scheda(): void
    {
        $cat = CategoriaEnte::factory()->create();
        $ente = $this->ente($cat, 'IC SILONE', ['fonte_dati' => 'miur', 'codice_meccanografico' => 'PEIC828004']);

        $this->actingAs($this->admin)->patch(route('admin.enti.update', $ente), [
            'descrizione' => 'CAMBIATO', 'id_categoria' => $cat->id_categoria, 'codice_meccanografico' => 'XXXX', 'dirigente' => 'Rossi', 'attivo' => '1',
        ])->assertRedirect(route('admin.enti.show', $ente));

        $ente->refresh();
        $this->assertSame('IC SILONE', $ente->descrizione);
        $this->assertSame('PEIC828004', $ente->codice_meccanografico);
        $this->assertSame('Rossi', $ente->dirigente);
    }

    public function test_categoria_non_vuota_non_si_cancella(): void
    {
        $cat = CategoriaEnte::factory()->create();
        $this->ente($cat);

        $this->actingAs($this->admin)->delete(route('admin.categorie-enti.destroy', $cat))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'contiene 1 enti'));
        $this->assertDatabaseHas('categorie_enti', ['id_categoria' => $cat->id_categoria]);

        $vuota = CategoriaEnte::factory()->create();
        $this->actingAs($this->admin)->delete(route('admin.categorie-enti.destroy', $vuota))->assertSessionHas('success');
        $this->assertDatabaseMissing('categorie_enti', ['id_categoria' => $vuota->id_categoria]);
    }

    public function test_rinomina_categoria_non_tocca_deleghe(): void
    {
        $ist = $this->istitutoConPlessi('PEIC828004');
        $ist->update(['richieste_delega_spid' => true]);
        $utente = $this->utenteSpid();
        $this->delega($utente, $ist, null);
        $prima = Delega::plessiCopertiDa($utente)->pluck('id_plesso')->all();
        $this->assertNotEmpty($prima);

        $this->actingAs($this->admin)->patch(route('admin.categorie-enti.update', $ist->categoria), [
            'nome' => 'Nome nuovo', 'ordine' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Nome nuovo', $ist->categoria()->first()->nome);
        $this->assertSame($prima, Delega::plessiCopertiDa($utente)->pluck('id_plesso')->all());
    }

    public function test_import_miur_unico(): void
    {
        $a = CategoriaEnte::factory()->create(['import_miur' => true]);
        $b = CategoriaEnte::factory()->create();

        $this->actingAs($this->admin)->patch(route('admin.categorie-enti.update', $b), [
            'nome' => $b->nome, 'ordine' => 0, 'import_miur' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($a->fresh()->import_miur);
        $this->assertTrue($b->fresh()->import_miur);

        $this->actingAs($this->admin)->post(route('admin.categorie-enti.store'), [
            'nome' => 'Terza', 'import_miur' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, CategoriaEnte::where('import_miur', true)->count());
        $this->assertTrue(CategoriaEnte::where('nome', 'Terza')->sole()->import_miur);
    }

    public function test_select_sede_raggruppata_per_categoria(): void
    {
        $this->seed(\Database\Seeders\TabelleRiferimentoSeeder::class);
        $cat = CategoriaEnte::factory()->create(['nome' => 'Comune']);
        $ente = $this->ente($cat, 'Palazzo');
        Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Municipio']);
        $u = User::factory()->create(['auth_source' => 'ldap']);
        $u->assignRole('segnalatore');

        $this->actingAs($u)->get(route('segnalazioni.create'))
            ->assertOk()
            ->assertSee('<optgroup label="Comune">', false)
            ->assertSee('Municipio');
    }

    public function test_non_admin_non_entra(): void
    {
        $g = User::factory()->create(['attivo' => true, 'approval_status' => 'approved']);
        $g->assignRole('gestore');

        $this->actingAs($g)->get(route('admin.enti.index'))->assertForbidden();
    }

    public function test_vecchi_url_reindirizzano_301(): void
    {
        $ente = $this->ente(CategoriaEnte::factory()->create());

        $this->actingAs($this->admin);
        foreach (['/admin/organizzazioni', '/admin/organizzazioni/create', '/admin/sedi', '/admin/sedi/create'] as $url) {
            $this->get($url)->assertStatus(301)->assertRedirect(route('admin.enti.index'));
        }
        $this->get('/admin/organizzazioni/'.$ente->id_istituto.'/edit')->assertStatus(301)->assertRedirect(route('admin.enti.show', $ente));
    }

    public function test_nome_categoria_oltre_80_caratteri_rifiutato(): void
    {
        $lungo = str_repeat('a', 81);
        $this->actingAs($this->admin)->post(route('admin.categorie-enti.store'), ['nome' => $lungo])
            ->assertSessionHasErrors('nome');
        $this->assertDatabaseMissing('categorie_enti', ['nome' => $lungo]);

        $cat = CategoriaEnte::factory()->create();
        $this->actingAs($this->admin)->patch(route('admin.categorie-enti.update', $cat), ['nome' => $lungo])
            ->assertSessionHasErrors('nome');

        $this->actingAs($this->admin)->post(route('admin.categorie-enti.store'), ['nome' => str_repeat('b', 80)])
            ->assertSessionHasNoErrors();
    }
}
