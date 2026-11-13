<?php

namespace Tests\Feature\Admin;

use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use App\Services\Tipologie\UnisciTipologie;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 1.0.0: tipologie gestite dall'admin (aggiungi, rinomina, disattiva, unisci). */
class TipologieTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->admin = User::factory()->create(['amministratore' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_icona_facoltativa_e_nome_entro_50_caratteri(): void
    {
        $this->actingAs($this->admin)->post(route('admin.tipologie.store'), ['descrizione' => 'Ascensori', 'icona' => ''])
            ->assertRedirect(route('admin.tipologie.index'))->assertSessionHasNoErrors();
        $t = TipologiaSegnalazione::where('descrizione', 'Ascensori')->firstOrFail();
        $this->assertSame('', (string) $t->icona);

        $this->actingAs($this->admin)->patch(route('admin.tipologie.update', $t), ['descrizione' => 'Ascensori e scale', 'icona' => '', 'attiva' => '1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('admin.tipologie.store'), ['descrizione' => str_repeat('a', 51)])
            ->assertSessionHasErrors('descrizione');
    }

    public function test_admin_aggiunge_rinomina_disattiva(): void
    {
        $this->actingAs($this->admin)->get(route('admin.tipologie.index'))->assertOk()->assertSee('IMPIANTO ELETTRICO');

        $this->actingAs($this->admin)->post(route('admin.tipologie.store'), ['descrizione' => 'Ascensori', 'icona' => 'fas fa-elevator'])
            ->assertRedirect(route('admin.tipologie.index'));
        $t = TipologiaSegnalazione::where('descrizione', 'Ascensori')->firstOrFail();
        $this->assertTrue((bool) $t->attiva);

        $this->actingAs($this->admin)->patch(route('admin.tipologie.update', $t), ['descrizione' => 'Ascensori e montacarichi', 'icona' => 'fas fa-elevator', 'attiva' => '0'])
            ->assertRedirect(route('admin.tipologie.index'));
        $t->refresh();
        $this->assertSame('Ascensori e montacarichi', $t->descrizione);
        $this->assertFalse(TipologiaSegnalazione::attive()->whereKey($t->id_tipologia_segnalazione)->exists());
    }

    public function test_unisci_sposta_tutto_senza_duplicati(): void
    {
        $gestore = User::factory()->create();
        $gestore->assignRole('gestore');
        $gestore->tipologieGestite()->sync([1, 3]);
        $s = Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);
        $regola = SlaConfigurazione::create(['id_tipologia_segnalazione' => 1, 'livello_priorita' => 2, 'ore_target' => 3, 'ore_warning' => 2, 'unita' => 'giorni']);

        $this->actingAs($this->admin)->post(route('admin.tipologie.unisci', 1), ['id_destinazione' => 3])
            ->assertRedirect(route('admin.tipologie.index'));

        $this->assertNull(TipologiaSegnalazione::find(1));
        $this->assertSame(3, (int) $s->fresh()->id_tipologia_segnalazione);
        $this->assertSame(3, (int) $regola->fresh()->id_tipologia_segnalazione);
        $this->assertSame([3], $gestore->tipologieGestite()->pluck('tipologie_segnalazioni.id_tipologia_segnalazione')->map(fn ($v) => (int) $v)->all());
    }

    public function test_non_admin_403(): void
    {
        $gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $gestore->assignRole('gestore');

        $this->actingAs($gestore)->get(route('admin.tipologie.index'))->assertForbidden();
        $this->actingAs($gestore)->post(route('admin.tipologie.store'), ['descrizione' => 'X'])->assertForbidden();
    }

    public function test_seeder_non_ricrea_tipologie_cancellate(): void
    {
        app(UnisciTipologie::class)->unisci(12, 3);
        $this->seed(TabelleRiferimentoSeeder::class);

        $this->assertNull(TipologiaSegnalazione::find(12));
    }

    public function test_installazione_nuova_senza_sfalci_doppi(): void
    {
        $this->assertSame(1, TipologiaSegnalazione::where('descrizione', 'SFALCI E POTATURE')->count());
    }

    public function test_migration_unisce_sfalci_doppi(): void
    {
        DB::table('tipologie_segnalazioni')->insertOrIgnore(['id_tipologia_segnalazione' => 11, 'descrizione' => 'SFALCI E POTATURE', 'icona' => 'fab fa-pagelines']);
        Segnalazione::factory()->count(2)->create(['id_tipologia_segnalazione' => 11]);
        Segnalazione::factory()->create(['id_tipologia_segnalazione' => 4]);

        (require database_path('migrations/2026_11_15_000001_tipologie_gestibili.php'))->unisciSfalci();

        $this->assertSame(1, TipologiaSegnalazione::where('descrizione', 'SFALCI E POTATURE')->count());
        $this->assertNotNull(TipologiaSegnalazione::find(11), 'resta la più usata');
        $this->assertSame(3, Segnalazione::where('id_tipologia_segnalazione', 11)->count());
    }
}
