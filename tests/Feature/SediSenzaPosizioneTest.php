<?php

namespace Tests\Feature;

use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** Sedi senza posizione ben visibili, e ritorno alla scheda dopo averla corretta. */
class SediSenzaPosizioneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Istituto $conPosizione;

    private Istituto $senza;

    private Plesso $sede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->admin = User::factory()->create(['amministratore' => true]);
        $this->admin->assignRole('admin');
        $categoria = CategoriaEnte::factory()->create()->id_categoria;
        $this->conPosizione = Istituto::create(['id_categoria' => $categoria, 'descrizione' => 'Ente Mappato', 'attivo' => true]);
        Plesso::create(['id_istituto' => $this->conPosizione->id_istituto, 'nome' => 'Sede mappata', 'indirizzo' => 'Via A 1', 'latitudine' => 42.5, 'longitudine' => 14.1]);
        $this->senza = Istituto::create(['id_categoria' => $categoria, 'descrizione' => 'Ente Smarrito', 'attivo' => true]);
        $this->sede = Plesso::create(['id_istituto' => $this->senza->id_istituto, 'nome' => 'Sede smarrita', 'indirizzo' => 'Via B 2']);
    }

    public function test_elenco_e_scheda_ente_segnalano_senza_posizione(): void
    {
        $this->actingAs($this->admin)->get(route('admin.enti.index'))->assertOk()->assertSee('senza posizione');
        $this->actingAs($this->admin)->get(route('admin.enti.show', $this->senza))->assertOk()->assertSee('senza posizione');
        $this->actingAs($this->admin)->get(route('admin.enti.show', $this->conPosizione))->assertOk()->assertDontSee('senza posizione');
    }

    public function test_filtro_senza_posizione(): void
    {
        $this->actingAs($this->admin)->get(route('admin.enti.index', ['senza_posizione' => 1]))
            ->assertOk()->assertSee('Ente Smarrito')->assertDontSee('Ente Mappato');
    }

    public function test_stato_sistema_porta_all_elenco_filtrato(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->assertSee(route('admin.enti.index', ['senza_posizione' => 1]), false);
    }

    private function salva(?string $ritorno): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.sedi.update', $this->sede), [
            'id_istituto' => $this->senza->id_istituto, 'nome' => 'Sede smarrita', 'indirizzo' => 'Via B 2',
            'latitudine' => 42.4, 'longitudine' => 14.2, 'ritorno' => $ritorno,
        ]);
    }

    public function test_update_rispetta_ritorno_interno(): void
    {
        $this->salva('/segnalazioni/3579')->assertRedirect('/segnalazioni/3579');
    }

    public function test_update_ignora_ritorno_esterno(): void
    {
        foreach (['https://evil.example/x', '//evil.example', '/\\evil.example', 'javascript:alert(1)'] as $cattivo) {
            $this->salva($cattivo)->assertRedirect(route('admin.enti.show', $this->senza->id_istituto));
        }
    }

    public function test_edit_porta_il_ritorno_nel_form(): void
    {
        $this->actingAs($this->admin)->get(route('admin.sedi.edit', [$this->sede, 'ritorno' => '/segnalazioni/3579']))
            ->assertOk()->assertSee('name="ritorno" value="/segnalazioni/3579"', false);
    }
}
