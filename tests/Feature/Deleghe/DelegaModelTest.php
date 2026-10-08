<?php

namespace Tests\Feature\Deleghe;

use App\Models\Delega;
use App\Models\Impostazione;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

class DelegaModelTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_delega_per_plesso_copre_solo_quel_plesso(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1] = $this->idPlessi($ist);
        $user = $this->utenteSpid();
        $this->delega($user, $ist, $p1);

        $this->assertSame([$p1], Delega::plessiCopertiDa($user)->pluck('id_plesso')->map(fn ($i) => (int) $i)->all());
    }

    public function test_delega_istituto_intero_copre_tutti_i_plessi(): void
    {
        $ist = $this->istitutoConPlessi(plessi: 3);
        $altro = $this->istitutoConPlessi('PEIC999999');
        $user = $this->utenteSpid();
        $this->delega($user, $ist, null);

        $coperti = Delega::plessiCopertiDa($user)->pluck('id_plesso')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $this->assertSame($this->idPlessi($ist), $coperti);
        $this->assertEmpty(array_intersect($coperti, $this->idPlessi($altro)));
    }

    public function test_revocate_richieste_e_oltre_scadenza_non_coprono(): void
    {
        $ist = $this->istitutoConPlessi(plessi: 3);
        [$p1, $p2, $p3] = $this->idPlessi($ist);
        $user = $this->utenteSpid();
        $this->delega($user, $ist, $p1, Delega::REVOCATA);
        $this->delega($user, $ist, $p2, Delega::RICHIESTA);
        $this->delega($user, $ist, $p3, Delega::ATTIVA, ['valida_fino_at' => now()->subDay()]);

        $this->assertSame(0, Delega::plessiCopertiDa($user)->count());
        $this->assertSame(0, Delega::attive()->count());
    }

    public function test_deleghe_di_altri_utenti_non_coprono(): void
    {
        $ist = $this->istitutoConPlessi();
        $this->delega($this->utenteSpid('VRDLGU75B02H501K'), $ist, null);

        $this->assertSame(0, Delega::plessiCopertiDa($this->utenteSpid())->count());
    }

    public function test_storico_registra_evento(): void
    {
        $ist = $this->istitutoConPlessi();
        $user = $this->utenteSpid();
        $delega = $this->delega($user, $ist, null, Delega::RICHIESTA);

        $delega->registra('richiesta', 'utente', $user, '10.0.0.1');

        $riga = $delega->storico()->sole();
        $this->assertSame('richiesta', $riga->evento);
        $this->assertSame('utente', $riga->via);
        $this->assertSame($user->id, $riga->id_utente);
        $this->assertNotNull($riga->created_at);
        $this->assertSame(1, $user->deleghe()->count());
    }

    public function test_seeder_aggiunge_impostazioni_deleghe(): void
    {
        $this->seed(ImpostazioniSeeder::class);

        $this->assertSame('3', (string) Impostazione::get('deleghe_max_pendenti'));
        $this->assertSame('12', (string) Impostazione::get('deleghe_mesi_validita'));
        $this->assertSame('deleghe', Impostazione::find('deleghe_giorni_avviso_delegato')->gruppo);
    }
}
