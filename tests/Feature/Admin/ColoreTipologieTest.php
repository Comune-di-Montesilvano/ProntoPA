<?php

namespace Tests\Feature\Admin;

use App\Models\Plesso;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use App\Support\NomeLeggibile;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** 1.0.4: colore della tipologia scelto dall'admin, nomi leggibili. */
class ColoreTipologieTest extends TestCase
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

    public function test_installazione_nuova_ha_i_colori_autoesplicativi(): void
    {
        $colori = TipologiaSegnalazione::pluck('colore', 'descrizione')->all();

        $this->assertSame('verde', $colori['VERDE E GIARDINI']);
        $this->assertSame('blu', $colori['IMPIANTO IDRICO']);
        $this->assertSame('giallo', $colori['IMPIANTO ELETTRICO']);
        $this->assertSame('rosso', $colori['IMPIANTO TERMICO']);
        $this->assertSame('pietra', $colori['MANUTENZIONE EDILE']);
    }

    public function test_colori_iniziali_per_nome_anche_su_dati_esistenti(): void
    {
        $migration = require database_path('migrations/2026_11_16_000004_colore_tipologie.php');

        $this->assertSame('salvia', $migration::COLORI_INIZIALI['SFALCI E POTATURE']);
        $this->assertSame('marrone', $migration::COLORI_INIZIALI['INFISSI E PORTE']);
        $this->assertCount(11, $migration::COLORI_INIZIALI);
    }

    public function test_senza_colore_e_grigia(): void
    {
        $t = TipologiaSegnalazione::create(['descrizione' => 'ASCENSORI', 'icona' => '', 'attiva' => true]);

        $this->assertNull($t->colore);
        $this->assertSame('grigio', $t->tono());
        $this->assertSame('Ascensori', $t->nomeLeggibile());
    }

    public function test_admin_sceglie_il_colore(): void
    {
        $t = TipologiaSegnalazione::create(['descrizione' => 'ASCENSORI', 'icona' => '', 'attiva' => true]);

        $this->actingAs($this->admin)->get(route('admin.tipologie.index'))->assertOk()->assertSee('pa-tono-grigio', false);

        $this->actingAs($this->admin)->patch(route('admin.tipologie.update', $t), ['descrizione' => 'ASCENSORI', 'icona' => '', 'attiva' => '1', 'colore' => 'viola'])
            ->assertSessionHasNoErrors();
        $this->assertSame('viola', $t->refresh()->colore);

        $this->actingAs($this->admin)->patch(route('admin.tipologie.update', $t), ['descrizione' => 'ASCENSORI', 'icona' => '', 'attiva' => '1', 'colore' => 'fucsia'])
            ->assertSessionHasErrors('colore');

        $this->actingAs($this->admin)->post(route('admin.tipologie.store'), ['descrizione' => 'TETTI', 'colore' => 'pietra'])
            ->assertSessionHasNoErrors();
        $this->assertSame('pietra', TipologiaSegnalazione::where('descrizione', 'TETTI')->value('colore'));
    }

    public function test_nomi_leggibili(): void
    {
        $this->assertSame('D.D. Montesilvano-V. Verrocchio', NomeLeggibile::di('D.D. MONTESILVANO-V. VERROCCHIO'));
        $this->assertSame('Verde e Giardini', NomeLeggibile::di('VERDE E GIARDINI'));
        $this->assertSame('Infanzia "Giuseppe Dezio"', NomeLeggibile::di('INFANZIA "GIUSEPPE DEZIO"'));
        $this->assertSame('Rete Telefonica / Dati', NomeLeggibile::di('RETE TELEFONICA / DATI'));
        $this->assertSame('Galleria Europa', NomeLeggibile::di('Galleria Europa'));

        $p = new Plesso(['nome' => 'VIA VERROTTI SUD']);
        $this->assertSame('Via Verrotti Sud', $p->nomeLeggibile());
    }
}
