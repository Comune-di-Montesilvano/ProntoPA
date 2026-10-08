<?php

namespace Tests\Feature\Deleghe;

use App\Models\Delega;
use App\Models\User;
use App\Notifications\Deleghe\EsitoDelegaNotification;
use App\Notifications\Deleghe\RichiestaDelegaNotification;
use App\Notifications\Deleghe\RinnovoDelegheNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

class RinnoviScadenzeTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
    }

    private function percorso(string $url): string
    {
        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    /** @return string link della pagina rinnovo (dalla notifica) */
    private function linkRinnovo(): string
    {
        $url = null;
        Notification::assertSentOnDemand(RinnovoDelegheNotification::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        return (string) $url;
    }

    public function test_rinnovi_una_email_per_scuola_solo_in_scadenza(): void
    {
        $ist = $this->istitutoConPlessi();
        $altro = $this->istitutoConPlessi('PEIC000002', 'peic000002@istruzione.it');
        $this->delega($this->utenteSpid(), $ist, null, Delega::ATTIVA, ['valida_fino_at' => now()->addDays(20)]);
        $this->delega($this->utenteSpid('VRDLGU75B02H501K'), $ist, null, Delega::ATTIVA, ['valida_fino_at' => now()->addDays(25)]);
        $this->delega($this->utenteSpid('BNCGNN70C03F205Z'), $altro, null, Delega::ATTIVA, ['valida_fino_at' => now()->addMonths(6)]);

        $this->artisan('deleghe:rinnovi')->assertSuccessful();

        Notification::assertSentOnDemandTimes(RinnovoDelegheNotification::class, 1);
        Notification::assertSentOnDemand(RinnovoDelegheNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'peic828004@istruzione.it' && count($n->persone) === 2);

        $this->artisan('deleghe:rinnovi');
        Notification::assertSentOnDemandTimes(RinnovoDelegheNotification::class, 1); // nessun doppio invio
    }

    public function test_pagina_rinnovo_conferma_e_revoca_per_riga(): void
    {
        $ist = $this->istitutoConPlessi();
        $mario = $this->utenteSpid();
        $luigi = $this->utenteSpid('VRDLGU75B02H501K');
        $d1 = $this->delega($mario, $ist, null, Delega::ATTIVA, ['valida_fino_at' => now()->addDays(10)]);
        $d2 = $this->delega($luigi, $ist, null, Delega::ATTIVA, ['valida_fino_at' => now()->addDays(10)]);
        $this->artisan('deleghe:rinnovi');
        $url = $this->percorso($this->linkRinnovo());

        $this->get($url)->assertOk()->assertSee($mario->name)->assertSee($luigi->name)->assertSee('Conferma')->assertDontSee('Conferma tutti');
        $this->assertSame(Delega::ATTIVA, $d1->fresh()->stato); // la GET non cambia nulla

        $this->post($url, ['delega' => $d1->id, 'azione' => 'conferma'])->assertOk()->assertSee('Confermata');
        $this->post($url, ['delega' => $d2->id, 'azione' => 'revoca'])->assertOk()->assertSee('Revocata');

        $this->assertTrue($d1->fresh()->valida_fino_at->gt(now()->addMonths(11)));
        $this->assertNull($d1->fresh()->rinnovo_inviato_at);
        $this->assertSame('rinnovata', $d1->fresh()->storico()->latest('id')->first()->evento);
        $this->assertSame(Delega::REVOCATA, $d2->fresh()->stato);
        $this->assertSame('segreteria', $d2->fresh()->decisa_via);
        Notification::assertSentTo($luigi, EsitoDelegaNotification::class, fn ($n) => $n->esito === 'revocata');
    }

    public function test_rinnovo_rifiuta_delega_di_altra_scuola(): void
    {
        $ist = $this->istitutoConPlessi();
        $this->delega($this->utenteSpid(), $ist, null, Delega::ATTIVA, ['valida_fino_at' => now()->addDays(10)]);
        $estranea = $this->delega($this->utenteSpid('VRDLGU75B02H501K'), $this->istitutoConPlessi('PEIC000002', 'x@istruzione.it'), null);
        $this->artisan('deleghe:rinnovi');

        $this->post($this->percorso($this->linkRinnovo()), ['delega' => $estranea->id, 'azione' => 'revoca'])->assertNotFound();
        $this->assertSame(Delega::ATTIVA, $estranea->fresh()->stato);
    }

    public function test_scadenze_chiude_attive_e_richieste_scadute(): void
    {
        $ist = $this->istitutoConPlessi();
        $mario = $this->utenteSpid();
        $scaduta = $this->delega($mario, $ist, null, Delega::ATTIVA, ['valida_fino_at' => now()->subDay()]);
        $richiesta = $this->delega($this->utenteSpid('VRDLGU75B02H501K'), $ist, null, Delega::RICHIESTA, ['token_scadenza_at' => now()->subDay(), 'richiesta_inviata_at' => now()->subDays(31)]);

        $this->artisan('deleghe:scadenze')->assertSuccessful();

        $this->assertSame(Delega::SCADUTA, $scaduta->fresh()->stato);
        $this->assertSame(Delega::SCADUTA, $richiesta->fresh()->stato);
        Notification::assertSentTo($mario, EsitoDelegaNotification::class, fn ($n) => $n->esito === 'scaduta');
    }

    public function test_scadenze_invia_le_richieste_in_coda(): void
    {
        $ist = $this->istitutoConPlessi();
        $this->delega($this->utenteSpid(), $ist, null, Delega::RICHIESTA, ['richiesta_inviata_at' => null]);

        $this->artisan('deleghe:scadenze');

        Notification::assertSentOnDemand(RichiestaDelegaNotification::class);
    }

    public function test_avviso_al_delegato_una_volta_sola(): void
    {
        $ist = $this->istitutoConPlessi();
        $mario = $this->utenteSpid();
        $this->delega($mario, $ist, null, Delega::ATTIVA, ['valida_fino_at' => now()->addDays(5), 'rinnovo_inviato_at' => now()->subDays(20)]);

        $this->artisan('deleghe:scadenze');
        $this->artisan('deleghe:scadenze');

        Notification::assertSentToTimes($mario, EsitoDelegaNotification::class, 1);
    }

    public function test_comandi_schedulati(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('deleghe:scadenze')
            ->expectsOutputToContain('deleghe:rinnovi');
    }

    public function test_rinnovi_escludono_utenti_bloccati(): void
    {
        $ist = $this->istitutoConPlessi();
        $bloccato = $this->utenteSpid();
        $bloccato->forceFill(['bloccato_at' => now()])->save();
        $this->delega($bloccato, $ist, null, Delega::ATTIVA, ['valida_fino_at' => now()->addDays(10)]);

        $this->artisan('deleghe:rinnovi');

        Notification::assertNothingSent();
    }

    public function test_richiesta_in_coda_scade_se_mai_inviata(): void
    {
        $ist = $this->istitutoConPlessi(email: null);
        $delega = $this->delega($this->utenteSpid(), $ist, null, Delega::RICHIESTA, ['richiesta_inviata_at' => null]);

        $this->travel(31)->days();
        $this->artisan('deleghe:scadenze');

        $this->assertSame(Delega::SCADUTA, $delega->fresh()->stato);
    }
}
