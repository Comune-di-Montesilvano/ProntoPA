<?php

namespace Tests\Feature\Deleghe;

use App\Models\Delega;
use App\Models\User;
use App\Notifications\Deleghe\EsitoDelegaNotification;
use App\Notifications\Deleghe\RichiestaDelegaNotification;
use App\Services\Deleghe\DelegaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

class AdminDelegheTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->create(['amministratore' => true, 'attivo' => true, 'approval_status' => 'approved']);
        $this->admin->syncRoles(['admin']);
    }

    public function test_solo_admin(): void
    {
        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');

        $this->actingAs($segnalatore)->get(route('admin.deleghe.index'))->assertForbidden();
        // utente SPID senza delega: rimandato alle sue deleghe prima del controllo sul ruolo
        $this->actingAs($this->utenteSpid())->get(route('admin.deleghe.index'))->assertRedirect(route('scuola.deleghe.index'));
    }

    public function test_elenco_filtri_e_istituti_senza_email(): void
    {
        $ist = $this->istitutoConPlessi();
        $this->istitutoConPlessi('PEIC000002', email: null);
        $this->delega($this->utenteSpid(), $ist, null, Delega::RICHIESTA);
        $this->delega($this->utenteSpid('VRDLGU75B02H501K'), $ist, null, Delega::ATTIVA);

        $this->actingAs($this->admin)->get(route('admin.deleghe.index', ['stato' => 'richiesta']))
            ->assertOk()
            ->assertSee('RSSMRA80A01G482X')
            ->assertDontSee('VRDLGU75B02H501K')
            ->assertSee('Scuole senza email')
            ->assertSee('IC PEIC000002');

        $this->actingAs($this->admin)->get(route('admin.deleghe.index', ['q' => 'VRDLGU']))
            ->assertSee('VRDLGU75B02H501K')
            ->assertDontSee('RSSMRA80A01G482X');
    }

    public function test_pre_delega_senza_utente_poi_agganciata(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1] = $this->idPlessi($ist);

        $this->actingAs($this->admin)->post(route('admin.deleghe.store'), [
            'codice_fiscale' => 'rssmra80a01g482x', 'id_istituto' => $ist->id_istituto, 'plessi' => [$p1], 'motivo' => 'Elenco iniziale della scuola',
        ])->assertRedirect(route('admin.deleghe.index'));

        $pre = Delega::sole();
        $this->assertNull($pre->user_id);
        $this->assertSame('RSSMRA80A01G482X', $pre->codice_fiscale);
        $this->assertSame(Delega::ATTIVA, $pre->stato);
        $this->assertSame('admin', $pre->decisa_via);
        Notification::assertNothingSent(); // nessuna email alla segreteria

        $user = $this->utenteSpid();
        app(DelegaService::class)->agganciaPredeleghe($user);
        $this->assertSame($user->id, $pre->fresh()->user_id);
    }

    public function test_pre_delega_valida_codice_fiscale(): void
    {
        $ist = $this->istitutoConPlessi();

        $this->actingAs($this->admin)->post(route('admin.deleghe.store'), [
            'codice_fiscale' => 'NONVALIDO', 'id_istituto' => $ist->id_istituto, 'motivo' => 'x',
        ])->assertSessionHasErrors('codice_fiscale');
    }

    public function test_attiva_d_ufficio_richiede_motivo(): void
    {
        $user = $this->utenteSpid();
        $gruppo = app(DelegaService::class)->richiedi($user, $this->istitutoConPlessi(), [])['gruppo'];

        $this->actingAs($this->admin)->post(route('admin.deleghe.attiva', $gruppo), [])->assertSessionHasErrors('motivo');
        $this->actingAs($this->admin)->post(route('admin.deleghe.attiva', $gruppo), ['motivo' => 'Telefonata con la DSGA'])->assertRedirect();

        $delega = Delega::where('gruppo_richiesta', $gruppo)->sole();
        $this->assertSame(Delega::ATTIVA, $delega->stato);
        $this->assertSame('admin', $delega->decisa_via);
        $this->assertSame($this->admin->id, $delega->decisa_da);
        $this->assertSame('Telefonata con la DSGA', $delega->motivo);
    }

    public function test_revoca_con_motivo_avvisa_delegato(): void
    {
        $user = $this->utenteSpid();
        $delega = $this->delega($user, $this->istitutoConPlessi(), null);

        $this->actingAs($this->admin)->post(route('admin.deleghe.revoca', $delega), ['motivo' => 'Trasferito'])->assertRedirect();

        $this->assertSame(Delega::REVOCATA, $delega->fresh()->stato);
        Notification::assertSentTo($user, EsitoDelegaNotification::class, fn ($n) => $n->esito === 'revocata');
    }

    public function test_reinvio_usa_email_corrente(): void
    {
        $ist = $this->istitutoConPlessi();
        $gruppo = app(DelegaService::class)->richiedi($this->utenteSpid(), $ist, [])['gruppo'];
        $ist->update(['email' => 'nuova@istruzione.it']);
        Notification::fake();

        $this->actingAs($this->admin)->post(route('admin.deleghe.reinvia', $gruppo))->assertRedirect()->assertSessionHas('success');

        Notification::assertSentOnDemand(RichiestaDelegaNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'nuova@istruzione.it');
        $this->assertSame('nuova@istruzione.it', Delega::where('gruppo_richiesta', $gruppo)->sole()->email_destinatario);
    }

    public function test_sblocca(): void
    {
        $user = $this->utenteSpid();
        $user->forceFill(['bloccato_at' => now(), 'motivo_blocco' => 'x'])->save();

        $this->actingAs($this->admin)->post(route('admin.deleghe.sblocca', $user))->assertRedirect();

        $this->assertNull($user->fresh()->bloccato_at);
    }
}
