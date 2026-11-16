<?php

namespace Tests\Feature;

use App\Models\AdesioneSegnalazione;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Segnalata N volte": persone e volte vere, senza contare due volte la stessa. */
class VolteSegnalataTest extends TestCase
{
    use RefreshDatabase;

    private User $autore;

    private User $altro;

    private Segnalazione $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->autore = User::factory()->create();
        $this->altro = User::factory()->create();
        $this->s = Segnalazione::factory()->create(['id_utente_segnalazione' => $this->autore->id, 'id_stato_segnalazione' => 2]);
    }

    private function nota(User $u, ?string $origine): void
    {
        NotaSegnalazione::create([
            'id_segnalazione' => $this->s->id_segnalazione, 'testo' => 'x', 'id_utente' => $u->id,
            'visibile_web' => true, 'visibile_impresa' => false, 'origine' => $origine,
        ]);
    }

    private function accorpata(User $u): void
    {
        Segnalazione::factory()->create([
            'id_utente_segnalazione' => $u->id, 'id_stato_segnalazione' => 8,
            'id_segnalazione_madre' => $this->s->id_segnalazione,
        ]);
    }

    private function volte(): int
    {
        $diretta = $this->s->fresh()->volteSegnalata();
        $daElenco = Segnalazione::query()->conVolteSegnalata()->findOrFail($this->s->id_segnalazione)->volteSegnalata();
        $this->assertSame($diretta, $daElenco, 'elenco e scheda devono contare uguale');

        return $diretta;
    }

    public function test_adesione_conta_una_volta(): void
    {
        AdesioneSegnalazione::create(['id_segnalazione' => $this->s->id_segnalazione, 'id_utente' => $this->altro->id]);

        $this->assertSame(2, $this->volte());
    }

    public function test_sollecito_dell_autore_conta(): void
    {
        $this->nota($this->autore, NotaSegnalazione::ORIGINE_SOLLECITO);
        $this->nota($this->autore, null);

        $this->assertSame(2, $this->volte());
    }

    public function test_integrazione_di_altra_persona_con_adesione_non_conta_doppio(): void
    {
        AdesioneSegnalazione::create(['id_segnalazione' => $this->s->id_segnalazione, 'id_utente' => $this->altro->id]);
        $this->nota($this->altro, NotaSegnalazione::ORIGINE_INTEGRAZIONE);

        $this->assertSame(2, $this->volte());
    }

    public function test_accorpata_dello_stesso_autore_conta(): void
    {
        $this->accorpata($this->autore);

        $this->assertSame(2, $this->volte());
    }

    public function test_accorpata_di_altro_autore_conta_solo_la_sua_adesione(): void
    {
        $this->accorpata($this->altro);
        AdesioneSegnalazione::create(['id_segnalazione' => $this->s->id_segnalazione, 'id_utente' => $this->altro->id]);

        $this->assertSame(2, $this->volte());
    }
}
