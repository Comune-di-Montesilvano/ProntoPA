<?php

namespace Tests\Feature;

use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** 1.0.6: il comune corregge la sede, solo tra le sedi dello stesso istituto (es. plesso di via Lazio). */
class CambiaSedeTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private Plesso $verrocchio;

    private Plesso $lazio;

    private Plesso $altroIstituto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');

        $categoria = CategoriaEnte::factory()->create()->id_categoria;
        $dd = Istituto::create(['descrizione' => 'D.D. MONTESILVANO', 'id_categoria' => $categoria, 'attivo' => true]);
        $altro = Istituto::create(['descrizione' => 'IC ALTRO', 'id_categoria' => $categoria, 'attivo' => true]);
        $this->verrocchio = Plesso::create(['id_istituto' => $dd->id_istituto, 'nome' => 'MONTESILVANO-V. VERROCCHIO']);
        $this->lazio = Plesso::create(['id_istituto' => $dd->id_istituto, 'nome' => 'MONTESILVANO-VIA LAZIO']);
        $this->altroIstituto = Plesso::create(['id_istituto' => $altro->id_istituto, 'nome' => 'ALTRA SCUOLA']);
    }

    private function segnalazione(): Segnalazione
    {
        return Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_plesso' => $this->verrocchio->id_plesso]);
    }

    public function test_gestore_sposta_nello_stesso_istituto_con_traccia(): void
    {
        $s = $this->segnalazione();

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $s->id_segnalazione))->assertOk()
            ->assertSee(route('segnalazioni.sede', $s->id_segnalazione), false)
            ->assertSee('Montesilvano-Via Lazio')->assertDontSee('Altra Scuola');

        $this->actingAs($this->gestore)
            ->patch(route('segnalazioni.sede', $s->id_segnalazione), ['id_plesso' => $this->lazio->id_plesso])
            ->assertRedirect(route('segnalazioni.show', $s->id_segnalazione))->assertSessionHas('success');

        $this->assertSame($this->lazio->id_plesso, (int) $s->fresh()->id_plesso);
        $this->assertDatabaseHas('note_segnalazioni', [
            'id_segnalazione' => $s->id_segnalazione,
            'testo' => 'Sede cambiata: Montesilvano-V. Verrocchio → Montesilvano-Via Lazio.',
            'visibile_web' => false,
        ]);
    }

    public function test_sede_di_altro_istituto_rifiutata(): void
    {
        $s = $this->segnalazione();

        foreach ([$this->altroIstituto->id_plesso, 99999] as $id) {
            $this->actingAs($this->gestore)
                ->patch(route('segnalazioni.sede', $s->id_segnalazione), ['id_plesso' => $id])
                ->assertSessionHasErrors('id_plesso');
        }
        $this->assertSame($this->verrocchio->id_plesso, (int) $s->fresh()->id_plesso);
    }

    public function test_chi_non_gestisce_non_sposta(): void
    {
        $s = $this->segnalazione();
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');

        $this->actingAs($operaio)
            ->patch(route('segnalazioni.sede', $s->id_segnalazione), ['id_plesso' => $this->lazio->id_plesso])
            ->assertForbidden();
    }

    public function test_istituto_con_una_sola_sede_niente_matita(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_plesso' => $this->altroIstituto->id_plesso]);

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $s->id_segnalazione))->assertOk()
            ->assertDontSee(route('segnalazioni.sede', $s->id_segnalazione), false);
    }
}
