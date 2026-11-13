<?php

namespace Tests\Feature;

use App\Models\TipologiaSegnalazione;
use App\Models\User;
use App\Services\Segnalazioni\CoperturaTipologie;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoperturaTipologieTest extends TestCase
{
    use RefreshDatabase;

    private CoperturaTipologie $copertura;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->copertura = app(CoperturaTipologie::class);
    }

    /** @param list<int> $tipologie */
    private function gestore(array $tipologie = [], bool $tutte = false, array $attributi = []): User
    {
        $user = User::factory()->create(array_merge([
            'gestore_segnalazioni' => true,
            'gestore_tutte_tipologie' => $tutte,
        ], $attributi));
        $user->assignRole('gestore');
        $user->tipologieGestite()->sync($tipologie);

        return $user;
    }

    public function test_gestore_con_tipologia_copre_solo_quella(): void
    {
        $g = $this->gestore([1]);

        $this->assertTrue($this->copertura->gestoriDi(1)->contains('id', $g->id));
        $this->assertFalse($this->copertura->gestoriDi(3)->contains('id', $g->id));
        $this->assertTrue($g->copreTipologia(1));
        $this->assertFalse($g->copreTipologia(3));
    }

    public function test_gestore_tutte_copre_anche_tipologie_nuove(): void
    {
        $g = $this->gestore([], tutte: true);
        $nuova = TipologiaSegnalazione::create(['descrizione' => 'NUOVA', 'icona' => 'fas fa-star']);

        $this->assertTrue($this->copertura->gestoriDi($nuova->id_tipologia_segnalazione)->contains('id', $g->id));
        $this->assertFalse($this->copertura->orfane()->contains('id_tipologia_segnalazione', $nuova->id_tipologia_segnalazione));
    }

    public function test_admin_con_tipologie_copre_solo_quelle_mai_tutte(): void
    {
        $admin = User::factory()->create(['amministratore' => true, 'attivo' => true, 'gestore_tutte_tipologie' => true]);
        $admin->assignRole('admin');
        $admin->tipologieGestite()->sync([5]);

        $this->assertTrue($this->copertura->gestoriDi(5)->contains('id', $admin->id));
        $this->assertFalse($this->copertura->gestoriDi(1)->contains('id', $admin->id));
        $this->assertFalse($this->copertura->orfane()->contains('id_tipologia_segnalazione', 5));
        $this->assertTrue($this->copertura->orfane()->contains('id_tipologia_segnalazione', 1));
    }

    public function test_admin_senza_tipologie_o_disattivato_non_copre(): void
    {
        $admin = User::factory()->create(['amministratore' => true, 'attivo' => true]);
        $admin->assignRole('admin');
        $spento = User::factory()->create(['amministratore' => true, 'attivo' => false]);
        $spento->assignRole('admin');
        $spento->tipologieGestite()->sync([5]);

        $this->assertTrue($this->copertura->gestoriDi(5)->isEmpty());
        $this->assertTrue($this->copertura->orfane()->contains('id_tipologia_segnalazione', 5));
    }

    public function test_supervisore_non_conta_come_copertura(): void
    {
        $this->gestore([1], attributi: ['supervisore_segnalazioni' => true]);

        $this->assertTrue($this->copertura->gestoriDi(1)->isEmpty());
        $this->assertTrue($this->copertura->orfane()->contains('id_tipologia_segnalazione', 1));
    }

    public function test_gestore_disattivato_o_non_piu_gestore_non_copre(): void
    {
        $this->gestore([1], attributi: ['attivo' => false]);
        $exGestore = $this->gestore([1]);
        $exGestore->syncRoles(['operaio']);

        $this->assertTrue($this->copertura->gestoriDi(1)->isEmpty());
        $this->assertTrue($this->copertura->orfane()->contains('id_tipologia_segnalazione', 1));
        $this->assertFalse($exGestore->fresh()->copreTipologia(1));
    }

    public function test_gestore_senza_email_e_non_verificato(): void
    {
        $g = $this->gestore([1], attributi: ['email' => null]);

        $this->assertFalse($this->copertura->gestoriDi(1)->contains('id', $g->id));
        $this->assertTrue($this->copertura->nonVerificati(1)->contains('id', $g->id));
        $this->assertTrue($this->copertura->orfane()->contains('id_tipologia_segnalazione', 1));
    }

    public function test_supervisori_attivi_con_email(): void
    {
        $sup = $this->gestore(attributi: ['supervisore_segnalazioni' => true]);
        $this->gestore(attributi: ['supervisore_segnalazioni' => true, 'attivo' => false]);

        $this->assertSame([$sup->id], $this->copertura->supervisori()->pluck('id')->all());
    }

    public function test_migration_gestori_esistenti_tutte(): void
    {
        $g = $this->gestore();
        $sup = $this->gestore(attributi: ['supervisore_segnalazioni' => true]);

        $this->assertSame(1, CoperturaTipologie::abilitaTutteAiGestoriEsistenti());

        $this->assertTrue($g->fresh()->gestore_tutte_tipologie);
        $this->assertFalse($sup->fresh()->gestore_tutte_tipologie);
    }
}
