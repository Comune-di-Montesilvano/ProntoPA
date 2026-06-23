<?php

namespace Tests\Feature;

use App\Models\AdesioneSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MergeSegnalazioniTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        \Illuminate\Support\Facades\Cache::flush();
        Impostazione::set('adesioni_enabled', true);
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

    public function test_merge_negato_su_destinazione_non_gestibile(): void
    {
        // Gestore non supervisore: può aggiornare solo le segnalazioni assegnate a lui.
        $gestore = User::factory()->create([
            'amministratore'           => false,
            'gestore_segnalazioni'     => true,
            'supervisore_segnalazioni' => false,
        ]);
        $gestore->assignRole('gestore');

        $duplicato    = Segnalazione::factory()->create(['id_operatore_assegnato' => $gestore->id]);
        $destinazione = Segnalazione::factory()->create(); // non assegnata a lui

        $response = $this->actingAs($gestore)->post(
            route('segnalazioni.unisci', $duplicato),
            ['id_destinazione' => $destinazione->id_segnalazione]
        );

        $response->assertForbidden();
        $this->assertFalse($duplicato->fresh()->isChiusa());
    }

    public function test_merge_chiude_duplicato_e_crea_adesione(): void
    {
        $madre     = Segnalazione::factory()->create();
        $duplicato = Segnalazione::factory()->create([
            'segnalante' => 'Pina Verdi',
            'telefono'   => '333 444555',
            'email'      => 'pina@example.com',
        ]);

        $response = $this->actingAs($this->admin())->post(
            route('segnalazioni.unisci', $duplicato),
            ['id_destinazione' => $madre->id_segnalazione]
        );

        $response->assertRedirect(route('segnalazioni.show', $madre->id_segnalazione));

        $duplicato->refresh();
        $this->assertTrue($duplicato->isChiusa());
        $this->assertEquals(\App\Enums\SegnalazioneStato::DUPLICATA, $duplicato->id_stato_segnalazione);

        $adesione = $madre->adesioni()->first();
        $this->assertNotNull($adesione);
        $this->assertSame('Pina Verdi', $adesione->segnalante);
    }

    public function test_merge_negato_a_segnalatore(): void
    {
        $madre     = Segnalazione::factory()->create();
        $duplicato = Segnalazione::factory()->create();

        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');

        $response = $this->actingAs($segnalatore)->post(
            route('segnalazioni.unisci', $duplicato),
            ['id_destinazione' => $madre->id_segnalazione]
        );

        $response->assertForbidden();
    }

    public function test_merge_su_se_stessa_rifiutato(): void
    {
        $seg = Segnalazione::factory()->create();

        $response = $this->actingAs($this->admin())->post(
            route('segnalazioni.unisci', $seg),
            ['id_destinazione' => $seg->id_segnalazione]
        );

        $response->assertSessionHasErrors('id_destinazione');
    }

    public function test_merge_su_chiusa_rifiutato(): void
    {
        $madre     = Segnalazione::factory()->create(['data_chiusura' => now()]);
        $duplicato = Segnalazione::factory()->create();

        $response = $this->actingAs($this->admin())->post(
            route('segnalazioni.unisci', $duplicato),
            ['id_destinazione' => $madre->id_segnalazione]
        );

        $response->assertSessionHasErrors('id_destinazione');
    }
}
