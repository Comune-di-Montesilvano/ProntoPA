<?php

namespace Tests\Feature\Auth;

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
            'PRONTOPA_ADMIN' => ['admin', false],
            'PRONTOPA_SUPERVISORI' => ['gestore', true],
            'PRONTOPA_GESTORI' => ['gestore', false],
            'PRONTOPA_OPERAI' => ['operaio', false],
            'PRONTOPA_URP' => ['segnalatore', false],
            'PRONTOPA_SEGNALATORI' => ['segnalatore', false],
        ];

        foreach ($casi as $gruppo => [$ruolo, $sup]) {
            $r = $this->mappa()->risolvi([$gruppo]);
            $this->assertSame($ruolo, $r->ruolo, $gruppo);
            $this->assertSame($sup, $r->supervisore, $gruppo);
        }
    }

    public function test_precedenza_vince_il_ruolo_piu_alto(): void
    {
        $r = $this->mappa()->risolvi(['PRONTOPA_OPERAI', 'PRONTOPA_GESTORI', 'PRONTOPA_URP']);

        $this->assertSame('gestore', $r->ruolo);
        $this->assertFalse($r->supervisore);
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
}
