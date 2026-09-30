<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\NormalizzaUsernameAd;
use PHPUnit\Framework\TestCase;

class NormalizzaUsernameAdTest extends TestCase
{
    public function test_riconosce_upn_del_dominio(): void
    {
        $this->assertTrue(NormalizzaUsernameAd::èUpn('M.Rossi@Ente.Local', '%s@ente.local'));
        $this->assertFalse(NormalizzaUsernameAd::èUpn('info@ditta.it', '%s@ente.local'));
        $this->assertFalse(NormalizzaUsernameAd::èUpn('m.rossi@ente.local', 'CN=%s,OU=Utenti,DC=ente,DC=local'));
    }

    public function test_normalizza_upn_dominio_e_nudo(): void
    {
        $t = '%s@ente.local';

        $this->assertSame('m.rossi', NormalizzaUsernameAd::normalizza(' m.rossi@ente.local ', $t));
        $this->assertSame('m.rossi', NormalizzaUsernameAd::normalizza('ENTE\\m.rossi', $t));
        $this->assertSame('m.rossi', NormalizzaUsernameAd::normalizza('m.rossi', $t));
    }
}
