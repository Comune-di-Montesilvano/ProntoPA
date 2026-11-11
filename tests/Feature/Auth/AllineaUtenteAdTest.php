<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AllineaUtenteAd;
use App\Services\Auth\MappaGruppiLdap;
use App\Services\Auth\UsernameOccupato;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** Scrittura dell'utente da AD, condivisa da login e sync. */
class AllineaUtenteAdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param list<string> $gruppi */
    private function applica(array $gruppi, bool $login = false): User
    {
        $id = FakeDirectory::identita('m.rossi', $gruppi, guid: 'guid-1');
        $ruolo = app(MappaGruppiLdap::class)->risolvi($gruppi);
        $this->assertNotNull($ruolo);

        return app(AllineaUtenteAd::class)->applica($id, $ruolo, $login);
    }

    private function gestoreConTipologie(): User
    {
        $u = $this->applica(['PRONTOPA_GESTORI']);
        $u->tipologieGestite()->sync([1, 3]);
        $u->forceFill(['gestore_tutte_tipologie' => true])->save();

        return $u;
    }

    public function test_gestore_diventa_operaio_perde_tipologie(): void
    {
        $this->gestoreConTipologie();

        $u = $this->applica(['PRONTOPA_OPERAI'])->fresh();

        $this->assertTrue($u->hasRole('operaio'));
        $this->assertSame(0, $u->tipologieGestite()->count());
        $this->assertFalse((bool) $u->gestore_tutte_tipologie);
    }

    public function test_gestore_diventa_supervisore_perde_tipologie(): void
    {
        $this->gestoreConTipologie();

        $u = $this->applica(['PRONTOPA_SUPERVISORI'])->fresh();

        $this->assertSame(0, $u->tipologieGestite()->count());
        $this->assertFalse((bool) $u->gestore_tutte_tipologie);
    }

    public function test_gestore_diventa_admin_tiene_esplicite_perde_tutte(): void
    {
        $this->gestoreConTipologie();

        $u = $this->applica(['PRONTOPA_ADMIN'])->fresh();

        $this->assertTrue($u->hasRole('admin'));
        $this->assertEqualsCanonicalizing([1, 3], $u->tipologieGestite()->pluck('tipologie_segnalazioni.id_tipologia_segnalazione')->all());
        $this->assertFalse((bool) $u->gestore_tutte_tipologie);
    }

    public function test_resta_gestore_tiene_tipologie(): void
    {
        $this->gestoreConTipologie();

        $u = $this->applica(['PRONTOPA_GESTORI'])->fresh();

        $this->assertSame(2, $u->tipologieGestite()->count());
        $this->assertTrue((bool) $u->gestore_tutte_tipologie);
    }

    public function test_sync_non_tocca_last_login(): void
    {
        $this->assertNull($this->applica(['PRONTOPA_GESTORI'])->fresh()->last_login);
        $this->assertNotNull($this->applica(['PRONTOPA_GESTORI'], login: true)->fresh()->last_login);
    }

    public function test_username_occupato_lancia(): void
    {
        User::factory()->create(['username' => 'm.rossi', 'auth_source' => 'spid']);

        $this->expectException(UsernameOccupato::class);
        $this->applica(['PRONTOPA_GESTORI']);
    }
}
