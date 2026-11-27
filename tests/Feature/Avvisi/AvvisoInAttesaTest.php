<?php

namespace Tests\Feature\Avvisi;

use App\Models\AvvisoInAttesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

class AvvisoInAttesaTest extends TestCase
{
    use RefreshDatabase;

    private function avviso(array $extra = []): AvvisoInAttesa
    {
        return AvvisoInAttesa::create($extra + [
            'id_notifica'       => 'abc',
            'destinatario_tipo' => 'utente',
            'id_utente'         => 0,
            'email'             => '',
            'categoria'         => 'nuove',
            'ritmo'             => 'ora',
            'id_segnalazione'   => 1,
            'tipo'              => 'X',
            'notifica'          => 'x',
        ]);
    }

    public function test_notificabile_utente_disattivato_null(): void
    {
        $u = User::factory()->create(['attivo' => false]);
        $this->assertNull($this->avviso(['id_utente' => $u->id])->notificabile());

        $u->update(['attivo' => true]);
        $this->assertTrue($this->avviso(['id_notifica' => 'b', 'id_utente' => $u->id])->notificabile()->is($u));
        $this->assertNull($this->avviso(['id_notifica' => 'c', 'id_utente' => 99999])->notificabile());
    }

    public function test_notificabile_email_anonymous(): void
    {
        $n = $this->avviso(['destinatario_tipo' => 'email', 'email' => 'ditta@example.it'])->notificabile();
        $this->assertInstanceOf(AnonymousNotifiable::class, $n);
        $this->assertSame('ditta@example.it', $n->routes['mail']);
    }

    public function test_notifica_corrotta_restituisce_null(): void
    {
        $this->assertNull($this->avviso(['notifica' => 'O:9:"Inesistente":0:{}'])->notifica());
        $this->assertNull($this->avviso(['id_notifica' => 'z', 'notifica' => 'non serializzato'])->notifica());
    }

    public function test_chiave_destinatario_email_minuscola(): void
    {
        $this->assertSame('e:ditta@example.it', $this->avviso(['destinatario_tipo' => 'email', 'email' => 'Ditta@Example.IT'])->chiaveDestinatario());
        $this->assertSame('u:7', $this->avviso(['id_notifica' => 'q', 'id_utente' => 7])->chiaveDestinatario());
    }

    public function test_scope_in_attesa_e_ritmo(): void
    {
        $this->avviso();
        $this->avviso(['id_notifica' => 'g', 'ritmo' => 'giorno', 'inviata_at' => now()]);
        $this->assertSame(1, AvvisoInAttesa::inAttesa()->count());
        $this->assertSame(1, AvvisoInAttesa::inAttesa()->delRitmo('ora')->count());
        $this->assertSame(0, AvvisoInAttesa::inAttesa()->delRitmo('giorno')->count());
    }
}
