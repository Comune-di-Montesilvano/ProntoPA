<?php

namespace Tests\Feature\Deleghe;

use App\Models\Delega;
use App\Models\User;
use App\Notifications\Deleghe\AvvisoDelegheAdmin;
use App\Notifications\Deleghe\EsitoDelegaNotification;
use App\Services\Auth\AccessoSpidNegato;
use App\Services\Auth\SpidLoginService;
use App\Services\Deleghe\DelegaService;
use App\Services\Oidc\IdentitaSpid;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

class DecisioneSegreteriaTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        $this->user = $this->utenteSpid();
        $this->admin = User::factory()->create(['amministratore' => true, 'attivo' => true]);
    }

    /** @return array{0: string, 1: string} link e gruppo */
    private function richiesta(array $idPlessi = []): array
    {
        $ist = $this->istitutoConPlessi();
        $service = app(DelegaService::class);
        $gruppo = $service->richiedi($this->user, $ist, $idPlessi)['gruppo'];

        return [$service->invia($gruppo, true), $gruppo];
    }

    private function percorso(string $url): string
    {
        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function test_get_mostra_richiesta_e_non_cambia_nulla(): void
    {
        [$url, $gruppo] = $this->richiesta();

        $this->get($this->percorso($url))
            ->assertOk()
            ->assertSee($this->user->name)
            ->assertSee('RSSMRA80A01G482X')
            ->assertSee('Approva')
            ->assertSee('Non conosco questa persona');

        $this->assertSame(Delega::RICHIESTA, Delega::where('gruppo_richiesta', $gruppo)->sole()->stato);
    }

    public function test_approva(): void
    {
        [$url, $gruppo] = $this->richiesta();

        $this->post($this->percorso($url), ['azione' => 'approva'])->assertOk()->assertSee('approvata');

        $delega = Delega::where('gruppo_richiesta', $gruppo)->sole();
        $this->assertSame(Delega::ATTIVA, $delega->stato);
        $this->assertSame('segreteria', $delega->decisa_via);
        $this->assertTrue($delega->valida_fino_at->between(now()->addMonths(12)->subMinute(), now()->addMonths(12)->addMinute()));
        $this->assertSame('approvata', $delega->storico()->latest('id')->first()->evento);
        $this->assertNotNull($delega->storico()->latest('id')->first()->ip);
        Notification::assertSentTo($this->user, EsitoDelegaNotification::class, fn ($n) => $n->esito === 'approvata');
    }

    public function test_approvazione_istituto_intero_assorbe_deleghe_per_plesso(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1] = $this->idPlessi($ist);
        $perPlesso = $this->delega($this->user, $ist, $p1);
        $service = app(DelegaService::class);
        $gruppo = $service->richiedi($this->user, $ist, [])['gruppo'];

        $service->approva(Delega::where('gruppo_richiesta', $gruppo)->get(), 'segreteria');

        $this->assertSame(Delega::REVOCATA, $perPlesso->fresh()->stato);
        $this->assertSame('assorbita da delega istituto', $perPlesso->fresh()->motivo);
    }

    public function test_rifiuta(): void
    {
        [$url, $gruppo] = $this->richiesta();

        $this->post($this->percorso($url), ['azione' => 'rifiuta'])->assertOk()->assertSee('rifiutata');

        $this->assertSame(Delega::RIFIUTATA, Delega::where('gruppo_richiesta', $gruppo)->sole()->stato);
        $this->assertNull($this->user->fresh()->bloccato_at);
        Notification::assertSentTo($this->user, EsitoDelegaNotification::class, fn ($n) => $n->esito === 'rifiutata');
    }

    public function test_rifiuta_e_blocca_chiude_tutte_le_pendenti(): void
    {
        [$url] = $this->richiesta();
        $altra = app(DelegaService::class)->richiedi($this->user, $this->istitutoConPlessi('PEIC000002', 'peic000002@istruzione.it'), [])['gruppo'];

        $this->post($this->percorso($url), ['azione' => 'rifiuta', 'non_conosco' => '1'])->assertOk();

        $this->assertNotNull($this->user->fresh()->bloccato_at);
        $this->assertSame(Delega::RIFIUTATA, Delega::where('gruppo_richiesta', $altra)->sole()->stato);
        Notification::assertSentTo($this->admin, AvvisoDelegheAdmin::class);
    }

    public function test_blocco_impedisce_login_spid(): void
    {
        [$url] = $this->richiesta();
        $this->post($this->percorso($url), ['azione' => 'rifiuta', 'non_conosco' => '1']);

        $this->expectException(AccessoSpidNegato::class);
        app(SpidLoginService::class)->accedi(new IdentitaSpid(
            codiceFiscale: 'RSSMRA80A01G482X', subject: 'sub', nome: 'Mario', cognome: 'Rossi', email: null,
        ));
    }

    public function test_seconda_decisione_non_cambia_esito(): void
    {
        [$url, $gruppo] = $this->richiesta();
        $this->post($this->percorso($url), ['azione' => 'approva']);
        Notification::fake();

        $this->post($this->percorso($url), ['azione' => 'rifiuta', 'non_conosco' => '1'])
            ->assertOk()
            ->assertSee('già gestita');

        $this->assertSame(Delega::ATTIVA, Delega::where('gruppo_richiesta', $gruppo)->sole()->stato);
        $this->assertNull($this->user->fresh()->bloccato_at);
        Notification::assertNothingSent();
    }

    public function test_link_riaperto_dopo_decisione(): void
    {
        [$url] = $this->richiesta();
        $this->post($this->percorso($url), ['azione' => 'approva']);

        $this->get($this->percorso($url))->assertOk()->assertSee('già gestita il '.now()->format('d/m/Y'))->assertSee('approvata');
    }

    public function test_firma_manomessa_o_scaduta_pagina_neutra(): void
    {
        [$url] = $this->richiesta();

        $this->get(str_replace('signature=', 'signature=x', $this->percorso($url)))->assertStatus(410)->assertSee('Link non più valido');

        $this->travel(31)->days();
        $this->get($this->percorso($url))->assertStatus(410)->assertSee('Link non più valido');
    }

    public function test_token_vecchio_dopo_reinvio(): void
    {
        [$vecchio, $gruppo] = $this->richiesta();
        app(DelegaService::class)->invia($gruppo, true);

        $this->get($this->percorso($vecchio))->assertStatus(410);
        $this->post($this->percorso($vecchio), ['azione' => 'approva'])->assertStatus(410);
        $this->assertSame(Delega::RICHIESTA, Delega::where('gruppo_richiesta', $gruppo)->sole()->stato);
    }

    public function test_azione_non_valida(): void
    {
        [$url] = $this->richiesta();

        $this->post($this->percorso($url), ['azione' => 'boh'])->assertSessionHasErrors('azione');
    }

    public function test_gruppo_parzialmente_revocato_resta_decidibile(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1, $p2] = $this->idPlessi($ist);
        $service = app(DelegaService::class);
        $gruppo = $service->richiedi($this->user, $ist, [$p1, $p2])['gruppo'];
        $url = $service->invia($gruppo, true);
        $prima = Delega::where('gruppo_richiesta', $gruppo)->orderBy('id')->first();
        Notification::fake();

        $service->revoca($prima, 'admin', $this->admin, 'plesso chiuso');
        Notification::assertNotSentTo($this->user, EsitoDelegaNotification::class); // mai stata attiva

        $this->get($this->percorso($url))->assertOk()->assertSee('Approva')->assertDontSee('approvata');
        $this->post($this->percorso($url), ['azione' => 'approva'])->assertOk()->assertSee('approvata');

        $this->assertSame(Delega::ATTIVA, Delega::where('gruppo_richiesta', $gruppo)->where('id_plesso', $p2)->sole()->stato);
    }

    public function test_richiesta_annullata_non_mostra_approvata(): void
    {
        [$url, $gruppo] = $this->richiesta();
        app(DelegaService::class)->revoca(Delega::where('gruppo_richiesta', $gruppo)->sole(), 'utente', $this->user);

        $this->get($this->percorso($url))->assertOk()->assertSee('annullata')->assertDontSee('approvata');
    }
}
