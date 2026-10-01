<?php

namespace Tests\Feature\Auth;

use App\Models\Impostazione;
use App\Models\User;
use App\Services\Auth\LdapLoginService;
use App\Services\Auth\SpidLoginService;
use App\Services\Directory\Directory;
use App\Services\Oidc\IdentitaSpid;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** Correzioni dalla revisione finale della fase 2a. */
class SpidRevisioneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['oidc.mock' => true]);
    }

    private function identitaInSessione(): void
    {
        $this->post('/auth/spid/mock', ['codice_fiscale' => 'RSSMRA80A01G482X', 'nome' => 'Mario', 'cognome' => 'Rossi'])
            ->assertRedirect(route('spid.completa-profilo'));
    }

    public function test_utente_spid_omonimo_non_blocca_il_dipendente_ad(): void
    {
        app(SpidLoginService::class)->creaDaProfilo(new IdentitaSpid('RSSMRA80A01G482X', 'Mario', 'Rossi', null, 's'), 'a@scuola.it');

        $dir = (new FakeDirectory())->aggiungi('pw', FakeDirectory::identita('mario.rossi', ['PRONTOPA_OPERAI']));
        $this->app->instance(Directory::class, $dir);

        $dipendente = app(LdapLoginService::class)->login('mario.rossi', 'pw');

        $this->assertSame('ldap', $dipendente->auth_source);
        $this->assertSame('mario.rossi', $dipendente->username);
    }

    public function test_identita_spid_in_sessione_scade_dopo_15_minuti(): void
    {
        $this->identitaInSessione();

        $this->travel(16)->minutes();

        $this->get(route('spid.completa-profilo'))->assertRedirect(route('login'));
        $this->post(route('spid.completa-profilo.store'), ['email' => 'b@altro.it'])->assertRedirect(route('login'));
        $this->assertSame(0, User::where('codice_fiscale', 'RSSMRA80A01G482X')->count());
        $this->assertNull(session('spid.identita'));
    }

    public function test_identita_spid_valida_entro_15_minuti(): void
    {
        $this->identitaInSessione();

        $this->travel(14)->minutes();

        $this->get(route('spid.completa-profilo'))->assertOk();
    }

    public function test_profilo_email_unica_tra_ditte_e_scuole_attive(): void
    {
        $spidA = User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'AAAAAA00A00A000A', 'password' => null, 'email' => 'a@scuola.it']);
        $spidB = User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'BBBBBB00B00B000B', 'password' => null, 'email' => 'b@scuola.it']);
        $ditta = User::factory()->create(['email' => 'ditta@ditta.it']);

        $this->actingAs($spidB)->patch('/profile', ['name' => 'B', 'email' => 'a@scuola.it'])->assertSessionHasErrors('email');
        $this->actingAs($ditta)->patch('/profile', ['name' => 'Ditta', 'email' => 'a@scuola.it'])->assertSessionHasErrors('email');
        $this->actingAs($spidA)->patch('/profile', ['name' => 'A', 'email' => 'a@scuola.it'])->assertSessionHasNoErrors();
    }

    public function test_seeder_impostazioni_non_sovrascrive_i_valori_e_aggiunge_le_chiavi_nuove(): void
    {
        $this->seed(ImpostazioniSeeder::class);
        Impostazione::set('ente_nome', 'Comune di Montesilvano');
        Impostazione::set('oidc_issuer', 'https://login.ente.it');
        DB::table('impostazioni')->where('chiave', 'oidc_client_id')->delete();

        $this->seed(ImpostazioniSeeder::class);

        $this->assertSame('Comune di Montesilvano', DB::table('impostazioni')->where('chiave', 'ente_nome')->value('valore'));
        $this->assertSame('https://login.ente.it', DB::table('impostazioni')->where('chiave', 'oidc_issuer')->value('valore'));
        $this->assertTrue(DB::table('impostazioni')->where('chiave', 'oidc_client_id')->exists());
    }

    public function test_email_di_verifica_in_italiano(): void
    {
        $user = User::factory()->unverified()->create();

        $mail = (new VerifyEmail())->toMail($user);

        $this->assertSame('Conferma il tuo indirizzo email', $mail->subject);
        $this->assertSame('Conferma email', $mail->actionText);
    }

    public function test_login_spid_non_consuma_il_limite_del_reinvio_codice_ditta(): void
    {
        config(['oidc.mock' => false]);
        $ditta = User::factory()->create(['email' => 'mario@ditta.it']);
        $ditta->assignRole('impresa');
        $this->post('/login', ['username' => 'mario@ditta.it', 'password' => 'password']);

        $this->get('/auth/spid');

        $this->post('/two-factor-challenge/reinvia')->assertRedirect(route('two-factor.login'));
    }
}
