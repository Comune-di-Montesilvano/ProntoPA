<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Menu Amministrazione in quattro blocchi e vecchi URL che non danno mai 404. */
class MenuERedirectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->admin = User::factory()->create(['attivo' => true, 'auth_source' => 'ldap', 'ldap_guid' => 'g-admin']);
        $this->admin->assignRole('admin');
    }

    public function test_vecchi_url_reindirizzano(): void
    {
        $casi = [
            '/admin/impostazioni' => route('admin.aspetto'),
            '/admin/utenti' => route('admin.utenti.dominio'),
            '/admin/organizzazioni' => route('admin.enti.index'),
            '/admin/organizzazioni/create' => route('admin.enti.index'),
            '/admin/organizzazioni/5/edit' => route('admin.enti.show', 5),
            '/admin/sedi' => route('admin.enti.index'),
            '/admin/sedi?id_istituto=7' => route('admin.enti.show', 7),
            '/admin/sedi/create' => route('admin.enti.index'),
            '/admin/sla' => route('admin.regole.scadenze'),
            '/admin/profili' => route('admin.dashboard'),
            '/admin/profili/3/edit' => route('admin.dashboard'),
            '/admin/provenienze' => route('admin.dashboard'),
            '/admin/provenienze/create' => route('admin.dashboard'),
        ];

        foreach ($casi as $vecchio => $nuovo) {
            $this->actingAs($this->admin)->get($vecchio)->assertStatus(301)->assertRedirect($nuovo);
        }
    }

    public function test_rotte_del_vecchio_form_non_esistono_piu(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.impostazioni.update'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.impostazioni.pubblica-esistenti'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.impostazioni.index'));
    }

    public function test_menu_admin_quattro_blocchi(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Stato del sistema', 'Persone', 'Utenti di dominio', 'Scuole (SPID)', 'Ditte (SPID)', 'Deleghe delle scuole', 'Squadre', 'Tipologie dei gestori', 'Enti e sedi', 'Regole di lavoro', 'Scadenze', 'Segnalazioni', 'Avvisi', 'Ente e sistema', 'Aspetto', 'Integrazioni', 'Backup']);
    }

    public function test_supervisore_vede_solo_squadre_e_tipologie(): void
    {
        $supervisore = User::factory()->create(['attivo' => true, 'supervisore_segnalazioni' => true, 'email' => 'sup@ente.test']);
        $supervisore->assignRole('gestore');

        $this->actingAs($supervisore)->get(route('organizzazione.squadre.index'))
            ->assertOk()
            ->assertSee('Squadre')
            ->assertSee('Tipologie dei gestori')
            ->assertDontSee('Enti e sedi')
            ->assertDontSee('Utenti di dominio')
            ->assertDontSee('Integrazioni');
    }
}
