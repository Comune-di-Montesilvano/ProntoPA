<?php

namespace Tests\Feature\Scuole;

use App\Jobs\ScaricaAnagrafeMiur;
use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use App\Services\Scuole\AnagrafeMiur;
use Database\Seeders\ImpostazioniSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnagrafeMiurAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        Storage::fake('local');
        $this->seed(ImpostazioniSeeder::class);
    }

    private function utente(string $ruolo): User
    {
        Role::firstOrCreate(['name' => $ruolo, 'guard_name' => 'web']);
        $user = User::factory()->create(['attivo' => true, 'approval_status' => 'approved']);
        $user->syncRoles([$ruolo]);

        return $user;
    }

    private function conIndice(): void
    {
        Storage::disk('local')->put(AnagrafeMiur::GREZZO, file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')));
        app(AnagrafeMiur::class)->indicizza(AnagrafeMiur::GREZZO);
        app(AnagrafeMiur::class)->salvaStato(['stato' => 'ok', 'url' => 'https://vecchio', 'scaricato_at' => now()->toIso8601String(), 'sedi' => 7]);
        app()->forgetInstance(AnagrafeMiur::class);
    }

    public function test_solo_admin(): void
    {
        $this->actingAs($this->utente('gestore'))
            ->get(route('admin.anagrafe-miur.index'))
            ->assertForbidden();
    }

    public function test_senza_indice_invita_a_scaricare(): void
    {
        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.index'))
            ->assertOk()
            ->assertSee('Nessuna anagrafe scaricata');
    }

    public function test_scarica_accoda_job_con_url_impostazioni(): void
    {
        Queue::fake();
        Impostazione::set('miur_anagrafe_url', 'https://dati.istruzione.it/nuovo.json');

        $this->actingAs($this->utente('admin'))
            ->post(route('admin.anagrafe-miur.scarica'))
            ->assertRedirect();

        Queue::assertPushed(ScaricaAnagrafeMiur::class, fn ($job) => $job->url === 'https://dati.istruzione.it/nuovo.json');
        $this->assertSame('in_corso', app(AnagrafeMiur::class)->stato()['stato']);
    }

    public function test_scarica_rifiutato_se_in_corso(): void
    {
        Queue::fake();
        app(AnagrafeMiur::class)->salvaStato(['stato' => 'in_corso']);

        $this->actingAs($this->utente('admin'))
            ->post(route('admin.anagrafe-miur.scarica'))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_avviso_nuovo_link_e_ricerca_per_comune_default(): void
    {
        $this->conIndice();
        Impostazione::set('miur_anagrafe_url', 'https://nuovo');
        Impostazione::set('miur_comune_default', 'MONTESILVANO');

        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.index'))
            ->assertOk()
            ->assertSee('Nuovo link configurato')
            ->assertSee('I. C. I.SILONE-MONTESILVANO')
            ->assertSee('D.D. MONTESILVANO');
    }

    public function test_pagina_istituto_preseleziona_sedi_presenti(): void
    {
        $this->conIndice();
        $ist = Istituto::create(['descrizione' => 'X', 'codice_meccanografico' => 'PEIC828004']);
        Plesso::create(['id_istituto' => $ist->id_istituto, 'nome' => 'Media', 'codice_meccanografico' => 'PEMM828015']);

        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.show', 'PEIC828004'))
            ->assertOk()
            ->assertSee('value="PEMM828015" checked', false)
            ->assertDontSee('value="PEAA828033" checked', false)
            ->assertSee('Sede amministrativa');
    }

    public function test_pagina_istituto_inesistente_404(): void
    {
        $this->conIndice();

        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.show', 'PEIC999999'))
            ->assertNotFound();
    }

    public function test_salva_selezione_e_flash_con_non_selezionate(): void
    {
        $this->conIndice();
        $admin = $this->utente('admin');

        $this->actingAs($admin)->post(route('admin.anagrafe-miur.salva', 'PEIC828004'), ['sedi' => ['PEMM828015', 'PEAA828033']])
            ->assertRedirect(route('admin.anagrafe-miur.show', 'PEIC828004'));
        $this->assertSame(2, Plesso::where('fonte_dati', 'miur')->count());

        $this->actingAs($admin)->post(route('admin.anagrafe-miur.salva', 'PEIC828004'), ['sedi' => ['PEMM828015']])
            ->assertSessionHas('warning', fn ($msg) => str_contains($msg, 'MONTESILVANO-COLLEMARE'));
        $this->assertSame(2, Plesso::count());
    }

    public function test_salva_con_sede_estranea_non_scrive(): void
    {
        $this->conIndice();

        $this->actingAs($this->utente('admin'))
            ->post(route('admin.anagrafe-miur.salva', 'PEIC828004'), ['sedi' => ['PEEE037001']])
            ->assertSessionHas('error');

        $this->assertSame(0, Istituto::count());
    }

    public function test_riquadro_non_piu_presenti(): void
    {
        $this->conIndice();
        Istituto::create(['descrizione' => 'Scuola chiusa', 'codice_meccanografico' => 'PEIC000000', 'fonte_dati' => 'miur']);

        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.index'))
            ->assertSee('Non più in anagrafe MIUR')
            ->assertSee('Scuola chiusa');
    }
}
