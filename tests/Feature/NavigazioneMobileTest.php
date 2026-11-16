<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Paginazione in italiano e "torna indietro" dalla scheda (barra in alto, pensata per il telefono). */
class NavigazioneMobileTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    public function test_paginazione_in_italiano(): void
    {
        app()->setLocale('it');

        $this->assertSame('Successivo &raquo;', __('pagination.next'));
        $this->assertSame('&laquo; Indietro', __('pagination.previous'));
        $this->assertSame('risultati', __('results'));
    }

    public function test_scheda_torna_alla_pagina_di_provenienza(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);
        $elenco = route('gestione.dashboard', ['page' => 2, 'filtro' => 'scadute']);

        $this->actingAs($this->gestore)->withHeader('referer', $elenco)->get(route('segnalazioni.show', $s))
            ->assertOk()
            ->assertSee('aria-label="Torna indietro"', false)
            ->assertSee('href="'.e($elenco).'"', false);
    }

    public function test_scheda_senza_provenienza_torna_alla_dashboard(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);

        $this->actingAs($this->gestore)->withHeader('referer', 'https://altro.example.it/x')->get(route('segnalazioni.show', $s))
            ->assertOk()
            ->assertSee('href="'.e(route('dashboard')).'"', false);
    }
}
