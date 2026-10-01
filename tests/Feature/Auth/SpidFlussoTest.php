<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeOidcProvider;
use Tests\TestCase;

class SpidFlussoTest extends TestCase
{
    use RefreshDatabase;

    private FakeOidcProvider $idp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        config(['app.url' => 'https://prontopa.test']);
        $this->idp = new FakeOidcProvider();
        $this->idp->configura();
        $this->idp->userinfo = ['sub' => 'sub-1', 'fiscal_number' => 'TINIT-RSSMRA80A01G482X', 'given_name' => 'Mario', 'family_name' => 'Rossi', 'email' => 'mario@example.it'];
        $this->idp->fake();
    }

    /** Avvia il login e restituisce lo state generato. */
    private function avvia(): string
    {
        $this->get('/auth/spid')->assertRedirect();
        $this->idp->nonce = session('spid.nonce');

        return session('spid.state');
    }

    public function test_start_redirige_al_proxy_con_state_in_sessione(): void
    {
        $risposta = $this->get('/auth/spid');

        $this->assertStringStartsWith(FakeOidcProvider::ISSUER.'/OIDC/authorization?', $risposta->headers->get('Location'));
        $this->assertNotEmpty(session('spid.state'));
        $this->assertNotEmpty(session('spid.verifier'));
    }

    public function test_primo_accesso_porta_a_completa_profilo_senza_creare_utente(): void
    {
        $state = $this->avvia();

        $this->get('/auth/spid/callback?code=c1&state='.$state)->assertRedirect(route('spid.completa-profilo'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->get(route('spid.completa-profilo'))->assertOk()->assertSee('Mario Rossi')->assertSee('mario@example.it');
    }

    public function test_completa_profilo_crea_utente_e_invia_verifica(): void
    {
        $state = $this->avvia();
        $this->get('/auth/spid/callback?code=c1&state='.$state);

        $this->post(route('spid.completa-profilo.store'), ['email' => 'Mario.Rossi@Scuola.it'])
            ->assertRedirect(route('verification.notice'));

        $user = User::where('codice_fiscale', 'RSSMRA80A01G482X')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('mario.rossi@scuola.it', $user->email);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertNull(session('spid.identita'));
    }

    public function test_utente_esistente_entra_direttamente(): void
    {
        $user = User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X', 'password' => null]);
        $user->assignRole('segnalatore');
        $state = $this->avvia();

        $this->get('/auth/spid/callback?code=c1&state='.$state)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty(session('spid.id_token'));
    }

    public function test_callback_riaperto_con_state_consumato(): void
    {
        $state = $this->avvia();
        $this->get('/auth/spid/callback?code=c1&state='.$state);

        $this->get('/auth/spid/callback?code=c1&state='.$state)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => 'Sessione di accesso scaduta, riprova.']);
    }

    public function test_state_errato_non_contatta_il_proxy(): void
    {
        $this->avvia();

        $this->get('/auth/spid/callback?code=c1&state=falso')
            ->assertSessionHasErrors(['username' => 'Sessione di accesso scaduta, riprova.']);

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/OIDC/token'));
    }

    public function test_utente_annulla_sul_proxy(): void
    {
        $state = $this->avvia();

        $this->get('/auth/spid/callback?error=access_denied&state='.$state)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => 'Accesso SPID/CIE annullato.']);
    }

    public function test_proxy_giu_messaggio_non_disponibile(): void
    {
        $this->idp->proxyGiu = true;

        $this->get('/auth/spid')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => 'Accesso SPID/CIE temporaneamente non disponibile. Riprova più tardi.']);
    }

    public function test_token_non_valido_messaggio_generico(): void
    {
        $state = $this->avvia();
        $this->idp->statoToken = 400;

        $this->get('/auth/spid/callback?code=c1&state='.$state)
            ->assertSessionHasErrors(['username' => 'Accesso SPID/CIE non riuscito, riprova.']);
        $this->assertGuest();
    }

    public function test_utente_bloccato_non_entra(): void
    {
        User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X', 'password' => null, 'bloccato_at' => now()]);
        $state = $this->avvia();

        $this->get('/auth/spid/callback?code=c1&state='.$state)
            ->assertSessionHasErrors(['username' => 'Accesso non consentito. Contatta l\'ente.']);
        $this->assertGuest();
    }

    public function test_completa_profilo_senza_identita_in_sessione_torna_al_login(): void
    {
        $this->get(route('spid.completa-profilo'))->assertRedirect(route('login'));
        $this->post(route('spid.completa-profilo.store'), ['email' => 'a@b.it'])->assertRedirect(route('login'));
    }

    public function test_email_di_ditta_attiva_rifiutata_email_di_utente_ad_ammessa(): void
    {
        User::factory()->create(['email' => 'presa@ditta.it']);
        User::factory()->create(['email' => 'dipendente@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1', 'password' => null]);
        $state = $this->avvia();
        $this->get('/auth/spid/callback?code=c1&state='.$state);

        $this->post(route('spid.completa-profilo.store'), ['email' => 'presa@ditta.it'])->assertSessionHasErrors('email');
        $this->post(route('spid.completa-profilo.store'), ['email' => 'dipendente@ente.it'])->assertRedirect(route('verification.notice'));
    }

    public function test_pulsante_spid_sulla_pagina_di_login(): void
    {
        $this->get('/login')->assertSee(route('spid.start'), false)->assertSee('SPID');
    }
}
