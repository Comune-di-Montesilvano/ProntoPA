<?php

namespace Tests\Feature\Admin;

use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PuliziaLegacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
    }

    private function segnalatoreLdap(): User
    {
        $u = User::factory()->create(['attivo' => true, 'auth_source' => 'ldap']);
        $u->assignRole('segnalatore');

        return $u;
    }

    public function test_colonna_profilo_e_tabella_rimosse(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'id_profilo'));
        $this->assertFalse(Schema::hasTable('profili'));
    }

    public function test_segnalatore_interno_vede_solo_le_sue(): void
    {
        $u = $this->segnalatoreLdap();
        $mia = Segnalazione::factory()->create(['id_utente_segnalazione' => $u->id]);
        $altra = Segnalazione::factory()->create(['id_plesso' => $mia->id_plesso]);

        $ids = Segnalazione::query()->visibileA($u)->pluck('id_segnalazione')->all();

        $this->assertContains($mia->id_segnalazione, $ids);
        $this->assertNotContains($altra->id_segnalazione, $ids);
    }

    public function test_form_segnalazione_mostra_tutte_le_sedi_al_dominio(): void
    {
        $a = Plesso::create(['nome' => 'Plesso Alfa', 'id_istituto' => Istituto::create(['id_categoria' => \App\Models\CategoriaEnte::perImportMiur()->id_categoria, 'descrizione' => 'IC Uno'])->id_istituto]);
        $b = Plesso::create(['nome' => 'Plesso Beta', 'id_istituto' => Istituto::create(['id_categoria' => \App\Models\CategoriaEnte::perImportMiur()->id_categoria, 'descrizione' => 'IC Due'])->id_istituto]);

        $this->actingAs($this->segnalatoreLdap())->get(route('segnalazioni.create'))
            ->assertOk()
            ->assertSee('Plesso Alfa')
            ->assertSee('Plesso Beta');
    }

    public function test_rotte_profili_e_provenienze_non_esistono(): void
    {
        $this->assertFalse(Route::has('admin.profili.index'));
        $this->assertFalse(Route::has('admin.provenienze.index'));
    }

    public function test_vecchi_url_reindirizzano_alla_dashboard_admin(): void
    {
        $admin = User::factory()->create(['attivo' => true]);
        $admin->assignRole('admin');

        foreach (['/admin/profili', '/admin/profili/3/edit', '/admin/provenienze', '/admin/provenienze/create'] as $url) {
            $this->actingAs($admin)->get($url)->assertStatus(301)->assertRedirect(route('admin.dashboard'));
        }
    }

    public function test_chiave_telegram_morta_rimossa(): void
    {
        $this->seed(ImpostazioniSeeder::class);

        $this->assertNull(Impostazione::find('telegram_notifica_stato_critico'));
    }
}
