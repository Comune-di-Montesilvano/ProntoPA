<?php

namespace Tests\Feature\Scuole;

use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use Database\Seeders\ImpostazioniSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaMiurTest extends TestCase
{
    use RefreshDatabase;

    public function test_plesso_ha_fonte_dati_manuale_di_default(): void
    {
        $istituto = Istituto::create(['descrizione' => 'X', 'codice_meccanografico' => 'PEIC000001']);
        $plesso = Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => 'Sede']);

        $this->assertSame('manuale', $plesso->fresh()->fonte_dati);
        $this->assertFalse($plesso->fresh()->isMiur());
    }

    public function test_nomi_lunghi_accettati(): void
    {
        $lungo = str_repeat('A', 200);
        $istituto = Istituto::create(['descrizione' => $lungo, 'codice_meccanografico' => 'PEIC000001']);
        $plesso = Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => $lungo, 'indirizzo' => $lungo]);

        $this->assertSame($lungo, $istituto->fresh()->descrizione);
        $this->assertSame($lungo, $plesso->fresh()->indirizzo);
    }

    public function test_scope_con_codice_ignora_maiuscole_e_spazi(): void
    {
        $istituto = Istituto::create(['descrizione' => 'X', 'codice_meccanografico' => ' peic828004 ']);
        Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => 'S', 'codice_meccanografico' => 'peaa828033']);

        $this->assertTrue(Istituto::conCodice('PEIC828004')->exists());
        $this->assertTrue(Plesso::conCodice(' PEAA828033')->exists());
        $this->assertFalse(Istituto::conCodice('PEIC999999')->exists());
    }

    public function test_seeder_aggiunge_impostazioni_miur(): void
    {
        $this->seed(ImpostazioniSeeder::class);

        $this->assertStringContainsString('SCUANAGRAFESTAT', (string) Impostazione::get('miur_anagrafe_url'));
        $this->assertSame('scuole', Impostazione::find('miur_comune_default')->gruppo);
    }
}
