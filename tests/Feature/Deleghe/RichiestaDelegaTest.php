<?php

namespace Tests\Feature\Deleghe;

use App\Models\Delega;
use App\Models\User;
use App\Notifications\Deleghe\AvvisoDelegheAdmin;
use App\Notifications\Deleghe\RichiestaDelegaNotification;
use App\Services\Deleghe\DelegaService;
use App\Services\Deleghe\RichiestaDelegaRifiutata;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DelegheFixture;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RichiestaDelegaTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->create(['amministratore' => true, 'attivo' => true]);
    }

    private function service(): DelegaService
    {
        return app(DelegaService::class);
    }

    private function rifiutata(callable $azione, string $messaggio): void
    {
        try {
            $azione();
            $this->fail('RichiestaDelegaRifiutata attesa');
        } catch (RichiestaDelegaRifiutata $e) {
            $this->assertStringContainsString($messaggio, $e->getMessage());
        }
    }

    public function test_richiesta_plessi_crea_righe_e_scrive_alla_segreteria(): void
    {
        $ist = $this->istitutoConPlessi(plessi: 3);
        [$p1, $p2] = $this->idPlessi($ist);
        $user = $this->utenteSpid();

        $esito = $this->service()->richiedi($user, $ist, [$p1, $p2]);

        $righe = Delega::where('gruppo_richiesta', $esito['gruppo'])->get();
        $this->assertCount(2, $righe);
        $this->assertTrue($righe->every(fn ($d) => $d->stato === Delega::RICHIESTA && $d->token_hash !== null && $d->richiesta_inviata_at !== null));
        $this->assertTrue($esito['inviata']);
        $this->assertSame(2, $righe->first()->storico()->count()); // richiesta + inviata

        Notification::assertSentOnDemand(RichiestaDelegaNotification::class, function ($n, $canali, $notifiable) use ($user) {
            return $notifiable->routes['mail'] === 'peic828004@istruzione.it'
                && $n->richiedente->is($user)
                && count($n->plessi) === 2
                && str_contains($n->url, '/deleghe/decidi/');
        });
    }

    public function test_richiesta_istituto_intero_una_riga_senza_plesso(): void
    {
        $ist = $this->istitutoConPlessi();
        $esito = $this->service()->richiedi($this->utenteSpid(), $ist, []);

        $riga = Delega::where('gruppo_richiesta', $esito['gruppo'])->sole();
        $this->assertNull($riga->id_plesso);
        Notification::assertSentOnDemand(RichiestaDelegaNotification::class, fn ($n) => $n->plessi === []);
    }

    public function test_utente_bloccato(): void
    {
        $user = $this->utenteSpid();
        $user->forceFill(['bloccato_at' => now()])->save();

        $this->rifiutata(fn () => $this->service()->richiedi($user, $this->istitutoConPlessi(), []), 'Contatta l\'ente');
    }

    public function test_istituto_senza_email_avvisa_admin(): void
    {
        $ist = $this->istitutoConPlessi(email: null);

        $this->rifiutata(fn () => $this->service()->richiedi($this->utenteSpid(), $ist, []), 'non è ancora configurata');

        $this->assertSame(0, Delega::count());
        Notification::assertSentTo($this->admin, AvvisoDelegheAdmin::class);
    }

    public function test_richiesta_gia_pendente_per_la_stessa_scuola(): void
    {
        $ist = $this->istitutoConPlessi();
        $user = $this->utenteSpid();
        $this->service()->richiedi($user, $ist, []);

        $this->rifiutata(fn () => $this->service()->richiedi($user, $ist, []), 'già una richiesta in attesa');
    }

    public function test_troppe_richieste_pendenti(): void
    {
        $user = $this->utenteSpid();
        foreach (['PEIC000001', 'PEIC000002', 'PEIC000003'] as $codice) {
            $this->service()->richiedi($user, $this->istitutoConPlessi($codice, strtolower($codice).'@istruzione.it'), []);
        }

        $this->rifiutata(fn () => $this->service()->richiedi($user, $this->istitutoConPlessi('PEIC000004', 'x@istruzione.it'), []), 'troppe richieste');
    }

    public function test_rifiuto_recente_blocca_per_30_giorni(): void
    {
        $ist = $this->istitutoConPlessi();
        $user = $this->utenteSpid();
        $this->delega($user, $ist, null, Delega::RIFIUTATA, ['decisa_at' => now()->subDays(10)]);

        $this->rifiutata(fn () => $this->service()->richiedi($user, $ist, []), 'potrai ripresentarla dal '.now()->addDays(20)->format('d/m/Y'));

        $this->travel(21)->days();
        $this->assertTrue($this->service()->richiedi($user, $ist, [])['inviata']);
    }

    public function test_plessi_gia_coperti_esclusi(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1, $p2] = $this->idPlessi($ist);
        $user = $this->utenteSpid();
        $this->delega($user, $ist, $p1);

        $esito = $this->service()->richiedi($user, $ist, [$p1, $p2]);

        $this->assertSame(1, $esito['esclusi']);
        $this->assertSame([$p2], Delega::where('gruppo_richiesta', $esito['gruppo'])->pluck('id_plesso')->map(fn ($i) => (int) $i)->all());
        $this->rifiutata(fn () => $this->service()->richiedi($this->utenteSpid('VRDLGU75B02H501K'), $ist, [999999]), 'Plesso non valido');
    }

    public function test_tutti_i_plessi_gia_coperti(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1] = $this->idPlessi($ist);
        $user = $this->utenteSpid();
        $this->delega($user, $ist, $p1);

        $this->rifiutata(fn () => $this->service()->richiedi($user, $ist, [$p1]), 'già una delega attiva');

        $this->delega($user, $ist, null);
        $this->rifiutata(fn () => $this->service()->richiedi($user, $ist, []), "tutto l'istituto");
    }

    public function test_oltre_tetto_giornaliero_in_coda_salvo_plesso_scoperto(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1, $p2] = $this->idPlessi($ist);
        // tutti i plessi già coperti da altri delegati
        $this->delega($this->utenteSpid('AAAAAA80A01G482X'), $ist, null);
        // tetto: 10 gruppi già inviati oggi
        for ($i = 0; $i < 10; $i++) {
            $this->delega($this->utenteSpid(sprintf('BBBBBB80A01G%03dX', $i)), $ist, $p1, Delega::RICHIESTA, ['richiesta_inviata_at' => now()]);
        }

        $esito = $this->service()->richiedi($this->utenteSpid(), $ist, [$p2]);

        $this->assertFalse($esito['inviata']);
        $this->assertNull(Delega::where('gruppo_richiesta', $esito['gruppo'])->sole()->richiesta_inviata_at);
        Notification::assertSentTo($this->admin, AvvisoDelegheAdmin::class, fn ($n) => str_contains($n->oggetto, 'tetto'));

        $this->travel(1)->days();
        $this->assertSame(1, $this->service()->inviaInCoda());
        $this->assertNotNull(Delega::where('gruppo_richiesta', $esito['gruppo'])->sole()->richiesta_inviata_at);
    }

    public function test_plesso_scoperto_salta_il_tetto(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1] = $this->idPlessi($ist);
        for ($i = 0; $i < 10; $i++) {
            $this->delega($this->utenteSpid(sprintf('BBBBBB80A01G%03dX', $i)), $ist, $p1, Delega::RICHIESTA, ['richiesta_inviata_at' => now()]);
        }

        $this->assertTrue($this->service()->richiedi($this->utenteSpid(), $ist, [$p1])['inviata']);
    }

    public function test_reinvio_rigenera_token(): void
    {
        $ist = $this->istitutoConPlessi();
        $esito = $this->service()->richiedi($this->utenteSpid(), $ist, []);
        $prima = Delega::where('gruppo_richiesta', $esito['gruppo'])->sole()->token_hash;

        $url = $this->service()->invia($esito['gruppo'], true);

        $riga = Delega::where('gruppo_richiesta', $esito['gruppo'])->sole();
        $this->assertNotSame($prima, $riga->token_hash);
        $this->assertStringContainsString('signature=', $url);
        $this->assertSame('reinviata', $riga->storico()->latest('id')->first()->evento);
    }

    public function test_notifiche_con_link_cifrate_in_coda(): void
    {
        $ist = $this->istitutoConPlessi();
        $user = $this->utenteSpid();

        $this->assertInstanceOf(ShouldBeEncrypted::class, new RichiestaDelegaNotification($user, $ist, [], 'https://x', now()));
        $this->assertInstanceOf(ShouldBeEncrypted::class, new \App\Notifications\Deleghe\RinnovoDelegheNotification($ist, [], 'https://x', now()));
        $this->assertInstanceOf(ShouldBeEncrypted::class, new AvvisoDelegheAdmin('x', 'y'));
    }

    public function test_doppio_invio_concorrente_rifiutato(): void
    {
        $ist = $this->istitutoConPlessi();
        $user = $this->utenteSpid();
        $lock = Cache::lock("deleghe:richiedi:{$user->id}", 10);
        $lock->get();

        $this->rifiutata(fn () => $this->service()->richiedi($user, $ist, []), 'già in invio');
        $this->assertSame(0, Delega::count());
        $lock->release();
    }
}
