<?php

namespace Tests\Feature\Scuole;

use App\Models\Delega;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Doppioni legacy/MIUR: lo storico si sposta, non si perde. */
class UnisciSediTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Istituto $istituto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(\Database\Seeders\TabelleRiferimentoSeeder::class);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['attivo' => true, 'approval_status' => 'approved']);
        $this->admin->syncRoles(['admin']);
        $this->istituto = Istituto::create([
            'descrizione' => 'D.D. MONTESILVANO', 'codice_meccanografico' => 'PEEE037001',
            'tipo' => 'Scuola', 'tipo_ente' => 'scuola', 'attivo' => true,
        ]);
    }

    private function plesso(string $nome, ?string $codice = null, ?Istituto $istituto = null): Plesso
    {
        return Plesso::create(['id_istituto' => ($istituto ?? $this->istituto)->id_istituto, 'nome' => $nome, 'codice_meccanografico' => $codice]);
    }

    public function test_unisci_sposta_segnalazioni_e_deleghe_ed_elimina(): void
    {
        $legacy = $this->plesso("scuola primaria VALLE D'AOSTA");
        $miur = $this->plesso('D.D. MONTESILVANO-V. VERROCCHIO', 'PEEE037078');
        Segnalazione::factory()->count(2)->create(['id_plesso' => $legacy->id_plesso]);
        $delega = Delega::create([
            'codice_fiscale' => 'RSSMRA80A01G482X', 'id_istituto' => $this->istituto->id_istituto,
            'id_plesso' => $legacy->id_plesso, 'gruppo_richiesta' => (string) \Illuminate\Support\Str::uuid(), 'stato' => Delega::ATTIVA,
        ]);

        $this->actingAs($this->admin)->get(route('admin.sedi.edit', $legacy))
            ->assertSee("Unisci in un'altra sede", false)
            ->assertSee('D.D. MONTESILVANO-V. VERROCCHIO — primaria · PEEE037078');

        $this->actingAs($this->admin)->post(route('admin.sedi.unisci', $legacy), ['id_destinazione' => $miur->id_plesso])
            ->assertRedirect(route('admin.sedi.edit', $miur))
            ->assertSessionHas('success');

        $this->assertModelMissing($legacy);
        $this->assertSame(2, Segnalazione::where('id_plesso', $miur->id_plesso)->count());
        $this->assertSame($miur->id_plesso, $delega->fresh()->id_plesso);
    }

    public function test_unisci_rifiuta_sede_di_altro_istituto(): void
    {
        $altro = Istituto::create(['descrizione' => 'IC SILONE', 'tipo' => 'Scuola', 'tipo_ente' => 'scuola', 'attivo' => true]);
        $legacy = $this->plesso('vecchia');
        $estranea = $this->plesso('estranea', null, $altro);

        $this->actingAs($this->admin)->post(route('admin.sedi.unisci', $legacy), ['id_destinazione' => $estranea->id_plesso])
            ->assertSessionHasErrors('id_destinazione');
        $this->assertModelExists($legacy);
    }

    public function test_elimina_rifiutata_se_ha_storico(): void
    {
        $legacy = $this->plesso('vecchia');
        Segnalazione::factory()->create(['id_plesso' => $legacy->id_plesso]);

        $this->actingAs($this->admin)->delete(route('admin.sedi.destroy', $legacy))
            ->assertSessionHas('error');
        $this->assertModelExists($legacy);
    }

    public function test_elimina_sede_vuota(): void
    {
        $vuota = $this->plesso('vuota');

        $this->actingAs($this->admin)->delete(route('admin.sedi.destroy', $vuota))->assertSessionHas('success');
        $this->assertModelMissing($vuota);
    }

    public function test_etichetta_con_ordine_codice_e_indirizzo(): void
    {
        $p = new Plesso(['nome' => 'MONTESILVANO-VIA DANTE', 'codice_meccanografico' => 'PEAA03701R', 'indirizzo' => 'VIA DANTE, MONTESILVANO']);
        $this->assertSame('MONTESILVANO-VIA DANTE — infanzia · PEAA03701R · VIA DANTE, MONTESILVANO', $p->etichetta());

        $legacy = new Plesso(['nome' => 'scuola primaria VIALE ABRUZZO']);
        $this->assertSame('scuola primaria VIALE ABRUZZO — senza codice', $legacy->etichetta());
    }
}
