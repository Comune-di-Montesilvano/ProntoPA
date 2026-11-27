<?php

namespace Tests\Feature\Avvisi;

use App\Models\Impostazione;
use App\Models\User;
use App\Support\Avvisi\Categorie;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpostazioniRitmiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, RolesAndPermissionsSeeder::class, ImpostazioniSeeder::class]);
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    public function test_seeder_crea_ritmi_di_default(): void
    {
        $this->assertSame('ora', Categorie::ritmo('assegnazioni'));
        $this->assertSame('giorno', Categorie::ritmo('scadenze'));
        $this->assertNotNull(Impostazione::find('avvisi_ritmo_esiti'));
        $this->assertTrue((bool) Impostazione::get('telegram_riepiloghi'));
    }

    public function test_seeder_non_sovrascrive_ritmo_configurato(): void
    {
        Impostazione::set('avvisi_ritmo_nuove', 'ora');

        $this->seed(ImpostazioniSeeder::class);

        $this->assertSame('ora', Categorie::ritmo('nuove'));
    }

    public function test_admin_salva_ritmo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.regole.avvisi.update'), [
            'impostazioni' => ['avvisi_ritmo_esiti' => 'ora'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('ora', Categorie::ritmo('esiti'));

        $this->actingAs($admin)->patch(route('admin.regole.avvisi.update'), [
            'impostazioni' => ['avvisi_ritmo_esiti' => 'subito'],
        ])->assertSessionHasErrors('impostazioni.avvisi_ritmo_esiti');
        $this->assertSame('ora', Categorie::ritmo('esiti'));
    }

    public function test_ordine_ed_etichette_complete(): void
    {
        $this->assertSame(
            ['assegnazioni', 'solleciti', 'richieste', 'lavoro', 'scadenze', 'nuove', 'inattivita', 'esiti'],
            Categorie::ORDINE,
        );
        foreach (Categorie::ORDINE as $categoria) {
            $this->assertNotSame('', Categorie::etichetta($categoria));
            $this->assertArrayHasKey($categoria, Categorie::DEFAULT);
        }
        $this->assertSame('Ferme o archiviate', Categorie::etichetta('inattivita'));
    }

    public function test_pagina_avvisi_mostra_ritmi_e_non_vecchi_interruttori(): void
    {
        $this->actingAs($this->admin())->get(route('admin.regole.avvisi'))
            ->assertOk()
            ->assertSee('Ogni ora')
            ->assertSee('Una volta al giorno')
            ->assertSee('Assegnate a te')
            ->assertDontSee('telegram_notifica_nuova_nota');
    }
}
