<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** 1.0.4: il comune corregge la tipologia dalla scheda (es. asciugamani elettrico, non idrico). */
class CambiaTipologiaTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    private function id(string $descrizione): int
    {
        return (int) TipologiaSegnalazione::where('descrizione', $descrizione)->value('id_tipologia_segnalazione');
    }

    public function test_gestore_cambia_tipologia_con_traccia(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_tipologia_segnalazione' => $this->id('IMPIANTO IDRICO')]);

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $s->id_segnalazione))
            ->assertOk()->assertSee(route('segnalazioni.tipologia', $s->id_segnalazione), false);

        $this->actingAs($this->gestore)
            ->patch(route('segnalazioni.tipologia', $s->id_segnalazione), ['id_tipologia_segnalazione' => $this->id('IMPIANTO ELETTRICO')])
            ->assertRedirect(route('segnalazioni.show', $s->id_segnalazione))->assertSessionHas('success');

        $this->assertSame($this->id('IMPIANTO ELETTRICO'), (int) $s->fresh()->id_tipologia_segnalazione);
        $this->assertDatabaseHas('note_segnalazioni', [
            'id_segnalazione' => $s->id_segnalazione,
            'testo' => 'Tipologia cambiata: Impianto Idrico → Impianto Elettrico.',
            'visibile_web' => false,
        ]);
    }

    public function test_tipologia_disattivata_o_inesistente_rifiutata(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_tipologia_segnalazione' => $this->id('IMPIANTO IDRICO')]);
        TipologiaSegnalazione::where('descrizione', 'ARREDI')->update(['attiva' => false]);

        foreach ([$this->id('ARREDI'), 9999] as $id) {
            $this->actingAs($this->gestore)
                ->patch(route('segnalazioni.tipologia', $s->id_segnalazione), ['id_tipologia_segnalazione' => $id])
                ->assertSessionHasErrors('id_tipologia_segnalazione');
        }
        $this->assertSame($this->id('IMPIANTO IDRICO'), (int) $s->fresh()->id_tipologia_segnalazione);
    }

    public function test_chi_non_gestisce_non_cambia(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'id_tipologia_segnalazione' => $this->id('IMPIANTO IDRICO')]);
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');

        $this->actingAs($operaio)
            ->patch(route('segnalazioni.tipologia', $s->id_segnalazione), ['id_tipologia_segnalazione' => $this->id('IMPIANTO ELETTRICO')])
            ->assertForbidden();
    }
}
