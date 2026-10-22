<?php

namespace Tests\Feature;

use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Chi segnala o gestisce più sedi deve vedere a quale si riferisce ogni riga. */
class ColonnaSedeTest extends TestCase
{
    use RefreshDatabase;

    public function test_liste_mostrano_sede_e_icona_tipologia(): void
    {
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $istituto = Istituto::create(['descrizione' => 'D.D. MONTESILVANO', 'tipo' => 'Scuola', 'tipo_ente' => 'scuola', 'attivo' => true]);
        $plesso = Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => 'MONTESILVANO-VIA DANTE', 'codice_meccanografico' => 'PEAA03701R']);

        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');
        $gestore = User::factory()->create(['gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true]);
        $gestore->assignRole('gestore');
        $s = Segnalazione::factory()->create(['id_plesso' => $plesso->id_plesso, 'id_utente_segnalazione' => $segnalatore->id]);
        $icona = $s->tipologia?->icona ?? 'fas fa-circle';

        $this->actingAs($segnalatore)->get(route('segnalazioni.index'))
            ->assertOk()->assertSee('>Sede<', false)->assertSee('MONTESILVANO-VIA DANTE')->assertSee($icona);

        $this->actingAs($gestore)->get(route('gestione.dashboard'))
            ->assertOk()->assertSee('>Sede<', false)->assertSee('MONTESILVANO-VIA DANTE')->assertDontSee('>Provenienza</th>', false);
    }
}
