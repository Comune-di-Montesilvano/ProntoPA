<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SquadreGestioneTest extends TestCase
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

    private function admin(): User
    {
        $user = User::factory()->create([
            'amministratore' => true,
            'gestore_segnalazioni' => true,
            'supervisore_segnalazioni' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_admin_crea_squadra_con_membri(): void
    {
        $capo   = User::factory()->create();
        $capo->assignRole('operaio');
        $membro = User::factory()->create();
        $membro->assignRole('operaio');

        $response = $this->actingAs($this->admin())->post(route('admin.squadre.store'), [
            'nome'            => 'Squadra Idraulica',
            'id_caposquadra'  => $capo->id,
            'membri'          => [$capo->id, $membro->id],
        ]);

        $response->assertRedirect(route('admin.squadre.index'));

        $squadra = Squadra::where('nome', 'Squadra Idraulica')->first();
        $this->assertNotNull($squadra);
        $this->assertSame($capo->id, $squadra->id_caposquadra);
        $this->assertSame(2, $squadra->membri()->count());
    }

    public function test_caposquadra_smista_lavoro_a_membro(): void
    {
        $capo = User::factory()->create(['attivo' => true]);
        $capo->assignRole('operaio');
        $membro = User::factory()->create(['attivo' => true]);
        $membro->assignRole('operaio');

        $squadra = Squadra::create(['nome' => 'Squadra A', 'id_caposquadra' => $capo->id]);
        $squadra->membri()->attach([$capo->id, $membro->id]);

        $segnalazione = Segnalazione::factory()->create([
            'id_squadra_assegnata'   => $squadra->id_squadra,
            'id_operatore_assegnato' => 0,
        ]);

        $response = $this->actingAs($capo)->post(
            route('operaio.smista', $segnalazione),
            ['id_membro' => $membro->id]
        );

        $response->assertRedirect();
        $this->assertSame($membro->id, $segnalazione->fresh()->id_operatore_assegnato);
    }

    public function test_membro_non_caposquadra_non_smista(): void
    {
        $capo = User::factory()->create();
        $capo->assignRole('operaio');
        $membro = User::factory()->create();
        $membro->assignRole('operaio');

        $squadra = Squadra::create(['nome' => 'Squadra B', 'id_caposquadra' => $capo->id]);
        $squadra->membri()->attach([$capo->id, $membro->id]);

        $segnalazione = Segnalazione::factory()->create([
            'id_squadra_assegnata' => $squadra->id_squadra,
        ]);

        $response = $this->actingAs($membro)->post(
            route('operaio.smista', $segnalazione),
            ['id_membro' => $membro->id]
        );

        $response->assertForbidden();
    }
}
