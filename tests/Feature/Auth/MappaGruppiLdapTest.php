<?php

namespace Tests\Feature\Auth;

use App\Models\Impostazione;
use App\Services\Auth\MappaGruppiLdap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MappaGruppiLdapTest extends TestCase
{
    use RefreshDatabase;

    private function mappa(): MappaGruppiLdap
    {
        return app(MappaGruppiLdap::class);
    }

    public function test_ogni_gruppo_default_mappa_il_suo_ruolo(): void
    {
        $casi = [
            'PRONTOPA_ADMIN' => ['admin', false, false, 1],
            'PRONTOPA_SUPERVISORI' => ['gestore', true, false, 1],
            'PRONTOPA_GESTORI' => ['gestore', false, false, 1],
            'PRONTOPA_OPERAI' => ['operaio', false, false, 1],
            'PRONTOPA_URP' => ['segnalatore', false, true, 3],
            'PRONTOPA_SEGNALATORI' => ['segnalatore', false, false, 1],
        ];

        foreach ($casi as $gruppo => [$ruolo, $sup, $perConto, $prov]) {
            $r = $this->mappa()->risolvi([$gruppo]);
            $this->assertSame($ruolo, $r->ruolo, $gruppo);
            $this->assertSame($sup, $r->supervisore, $gruppo);
            $this->assertSame($perConto, $r->perConto, $gruppo);
            $this->assertSame($prov, $r->idProvenienza, $gruppo);
        }
    }

    public function test_precedenza_vince_il_ruolo_piu_alto(): void
    {
        $r = $this->mappa()->risolvi(['PRONTOPA_OPERAI', 'PRONTOPA_GESTORI', 'PRONTOPA_URP']);

        $this->assertSame('gestore', $r->ruolo);
        $this->assertFalse($r->perConto);
    }

    public function test_confronto_case_insensitive_e_gruppi_estranei_ignorati(): void
    {
        $r = $this->mappa()->risolvi(['Domain Users', ' prontopa_operai ']);

        $this->assertSame('operaio', $r->ruolo);
    }

    public function test_nessun_gruppo_prontopa_restituisce_null(): void
    {
        $this->assertNull($this->mappa()->risolvi(['Domain Users', 'VPN']));
        $this->assertNull($this->mappa()->risolvi([]));
    }

    public function test_nome_gruppo_personalizzato_da_impostazioni(): void
    {
        Impostazione::set('ldap_gruppo_gestori', 'MANUTENZIONE_GESTORI');

        $this->assertSame('gestore', $this->mappa()->risolvi(['MANUTENZIONE_GESTORI'])->ruolo);
        $this->assertNull($this->mappa()->risolvi(['PRONTOPA_GESTORI']));
    }
}
