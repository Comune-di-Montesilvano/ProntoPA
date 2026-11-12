<?php

namespace Tests\Feature\Deleghe;

use App\Models\Delega;
use App\Models\Istituto;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

/**
 * "Attiva" e "accetta richieste di delega via SPID" sono distinte: un asilo
 * nido comunale caricato a mano è attivo (sedi, predelega dall'admin) ma
 * nessuno dall'esterno può chiedere una delega da solo.
 */
class RichiesteDelegaSpidTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->create(['amministratore' => true, 'attivo' => true, 'approval_status' => 'approved']);
        $this->admin->syncRoles(['admin']);
    }

    private function asiloSenzaRichieste(): Istituto
    {
        $ist = $this->istitutoConPlessi('NIDO000001');
        $ist->update(['richieste_delega_spid' => false]);

        return $ist;
    }

    public function test_istituto_senza_richieste_spid_non_compare_nella_ricerca(): void
    {
        $this->asiloSenzaRichieste();
        $this->istitutoConPlessi('PEIC828004');

        $this->actingAs($this->utenteSpid())->get(route('scuola.deleghe.index', ['q' => 'I']))
            ->assertOk()
            ->assertSee('IC PEIC828004')
            ->assertDontSee('IC NIDO000001');
    }

    public function test_richiesta_spid_rifiutata_anche_forzando_url(): void
    {
        $ist = $this->asiloSenzaRichieste();
        [$p1] = $this->idPlessi($ist);
        $user = $this->utenteSpid();

        $this->actingAs($user)->get(route('scuola.deleghe.create', $ist))->assertNotFound();
        $this->actingAs($user)->post(route('scuola.deleghe.store', $ist), ['plessi' => [$p1]])->assertNotFound();

        $this->assertSame(0, Delega::count());
    }

    public function test_admin_predelega_comunque(): void
    {
        $ist = $this->asiloSenzaRichieste();
        [$p1] = $this->idPlessi($ist);

        $this->actingAs($this->admin)->get(route('admin.deleghe.create'))->assertOk()->assertSee('IC NIDO000001');

        $this->actingAs($this->admin)->post(route('admin.deleghe.store'), [
            'codice_fiscale' => 'RSSMRA80A01G482X', 'id_istituto' => $ist->id_istituto, 'plessi' => [$p1], 'motivo' => 'Personale asilo nido',
        ])->assertRedirect(route('admin.deleghe.index'));

        $this->assertSame(Delega::ATTIVA, Delega::sole()->stato);
    }

    public function test_nuova_organizzazione_manuale_parte_senza_richieste_spid(): void
    {
        $this->actingAs($this->admin)->post(route('admin.organizzazioni.store'), [
            'descrizione' => 'Asili Nido Comunali', 'id_categoria' => \App\Models\CategoriaEnte::perImportMiur()->id_categoria, 'attivo' => '1',
        ])->assertRedirect(route('admin.organizzazioni.index'));

        $ist = Istituto::where('descrizione', 'Asili Nido Comunali')->sole();
        $this->assertTrue($ist->attivo);
        $this->assertFalse($ist->richieste_delega_spid);
    }

    public function test_spunta_richieste_spid_salvata_in_modifica(): void
    {
        $ist = $this->asiloSenzaRichieste();

        $this->actingAs($this->admin)->put(route('admin.organizzazioni.update', $ist), [
            'descrizione' => $ist->descrizione, 'id_categoria' => \App\Models\CategoriaEnte::perImportMiur()->id_categoria,
            'codice_meccanografico' => $ist->codice_meccanografico, 'attivo' => '1', 'richieste_delega_spid' => '1',
        ])->assertRedirect(route('admin.organizzazioni.index'));

        $this->assertTrue($ist->fresh()->richieste_delega_spid);
    }
}
