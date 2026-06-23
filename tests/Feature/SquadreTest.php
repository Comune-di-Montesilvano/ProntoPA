<?php

namespace Tests\Feature;

use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SquadreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        \Illuminate\Support\Facades\Cache::flush();
        Impostazione::set('squadre_enabled', true);
    }

    private function squadraConMembri(): array
    {
        $capo = User::factory()->create(['attivo' => true]);
        $capo->assignRole('operaio');

        $membro = User::factory()->create(['attivo' => true]);
        $membro->assignRole('operaio');

        $squadra = Squadra::create(['nome' => 'Squadra Edile', 'id_caposquadra' => $capo->id]);
        $squadra->membri()->attach([$capo->id, $membro->id]);

        return [$squadra, $capo, $membro];
    }

    public function test_assegnazione_a_squadra_notifica_caposquadra(): void
    {
        Notification::fake();

        [$squadra, $capo, $membro] = $this->squadraConMembri();

        $admin = User::factory()->create([
            'amministratore' => true,
            'gestore_segnalazioni' => true,
            'supervisore_segnalazioni' => true,
        ]);
        $admin->assignRole('admin');

        $segnalazione = Segnalazione::factory()->create();

        $azioneOperatore = Azione::where('flag_operatore', true)->first();
        $this->assertNotNull($azioneOperatore, 'Nessuna azione con flag_operatore nei dati seed');

        $this->actingAs($admin)->post(route('segnalazioni.azione', $segnalazione), [
            'id_azione'  => $azioneOperatore->id_azione,
            'id_squadra' => $squadra->id_squadra,
        ]);

        $segnalazione->refresh();
        $this->assertSame($squadra->id_squadra, $segnalazione->id_squadra_assegnata);

        Notification::assertSentTo($capo, \App\Notifications\SquadraAssegnataNotification::class);
        Notification::assertNothingSentTo($membro);
    }

    public function test_caposquadra_vede_lavori_squadra(): void
    {
        [$squadra, $capo, $membro] = $this->squadraConMembri();

        $diSquadra = Segnalazione::factory()->create(['id_squadra_assegnata' => $squadra->id_squadra]);
        $estranea  = Segnalazione::factory()->create();

        $visibili = Segnalazione::visibileA($capo)->pluck('id_segnalazione');

        $this->assertTrue($visibili->contains($diSquadra->id_segnalazione));
        $this->assertFalse($visibili->contains($estranea->id_segnalazione));
    }

    public function test_membro_vede_solo_non_smistate_della_squadra(): void
    {
        [$squadra, $capo, $membro] = $this->squadraConMembri();

        $nonSmistata = Segnalazione::factory()->create([
            'id_squadra_assegnata'   => $squadra->id_squadra,
            'id_operatore_assegnato' => 0,
        ]);
        $smistataAlCapo = Segnalazione::factory()->create([
            'id_squadra_assegnata'   => $squadra->id_squadra,
            'id_operatore_assegnato' => $capo->id,
        ]);

        $visibili = Segnalazione::visibileA($membro)->pluck('id_segnalazione');

        $this->assertTrue($visibili->contains($nonSmistata->id_segnalazione));
        $this->assertFalse($visibili->contains($smistataAlCapo->id_segnalazione));
    }
}
