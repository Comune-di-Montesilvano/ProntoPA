<?php

namespace Tests\Feature\Deleghe;

use App\Models\Delega;
use App\Services\Deleghe\DelegaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

class AreaDelegatoTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
    }

    public function test_senza_delega_confinato_alle_deleghe(): void
    {
        $user = $this->utenteSpid();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('scuola.deleghe.index'));
        $this->actingAs($user)->get(route('segnalazioni.create'))->assertRedirect(route('scuola.deleghe.index'));
        $this->actingAs($user)->get(route('spid.attesa'))->assertRedirect(route('scuola.deleghe.index'));
        $this->actingAs($user)->get(route('scuola.deleghe.index'))->assertOk()->assertSee('Richiedi una delega');
    }

    public function test_con_delega_attiva_accede_al_resto(): void
    {
        $user = $this->utenteSpid();
        $this->delega($user, $this->istitutoConPlessi(), null);

        $this->actingAs($user)->get(route('segnalazioni.create'))->assertOk();
    }

    public function test_ricerca_mostra_solo_istituti_attivi_con_plessi(): void
    {
        $this->istitutoConPlessi('PEIC828004');
        $this->istitutoConPlessi('PEIC000000', plessi: 0);

        $this->actingAs($this->utenteSpid())->get(route('scuola.deleghe.index', ['q' => 'PEIC']))
            ->assertOk()
            ->assertSee('IC PEIC828004')
            ->assertDontSee('IC PEIC000000');
    }

    public function test_richiesta_dal_form(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1] = $this->idPlessi($ist);
        $user = $this->utenteSpid();

        $this->actingAs($user)->get(route('scuola.deleghe.create', $ist))->assertOk()->assertSee('Plesso 1 PEIC828004');

        $this->actingAs($user)->post(route('scuola.deleghe.store', $ist), ['plessi' => [$p1]])
            ->assertRedirect(route('scuola.deleghe.index'))
            ->assertSessionHas('success');

        $this->assertSame($p1, (int) Delega::where('user_id', $user->id)->sole()->id_plesso);
    }

    public function test_richiesta_istituto_intero_e_errori_mostrati(): void
    {
        $ist = $this->istitutoConPlessi();
        $user = $this->utenteSpid();

        $this->actingAs($user)->post(route('scuola.deleghe.store', $ist), ['tutto' => '1'])->assertSessionHas('success');
        $this->actingAs($user)->post(route('scuola.deleghe.store', $ist), ['tutto' => '1'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'già una richiesta in attesa'));
        $this->actingAs($user)->post(route('scuola.deleghe.store', $this->istitutoConPlessi('PEIC000002', 'b@istruzione.it')), [])
            ->assertSessionHasErrors('plessi');
    }

    public function test_solo_utenti_spid(): void
    {
        $locale = \App\Models\User::factory()->create();
        $locale->assignRole('segnalatore');

        $this->actingAs($locale)->get(route('scuola.deleghe.index'))->assertForbidden();
    }

    public function test_rinuncia_e_niente_rinuncia_su_deleghe_altrui(): void
    {
        $ist = $this->istitutoConPlessi();
        $user = $this->utenteSpid();
        $mia = $this->delega($user, $ist, null);
        $altrui = $this->delega($this->utenteSpid('VRDLGU75B02H501K'), $ist, null);

        $this->actingAs($user)->post(route('scuola.deleghe.rinuncia', $altrui))->assertForbidden();
        $this->actingAs($user)->post(route('scuola.deleghe.rinuncia', $mia))->assertRedirect(route('scuola.deleghe.index'));

        $this->assertSame(Delega::REVOCATA, $mia->fresh()->stato);
        $this->assertSame('utente', $mia->fresh()->decisa_via);
        $this->assertSame(Delega::ATTIVA, $altrui->fresh()->stato);
    }

    public function test_pre_delega_agganciata_alla_verifica_email(): void
    {
        $ist = $this->istitutoConPlessi();
        $pre = Delega::create([
            'user_id' => null, 'codice_fiscale' => 'RSSMRA80A01G482X', 'id_istituto' => $ist->id_istituto,
            'id_plesso' => null, 'gruppo_richiesta' => (string) \Illuminate\Support\Str::uuid(),
            'stato' => Delega::ATTIVA, 'valida_fino_at' => now()->addYear(), 'decisa_via' => 'admin',
        ]);
        $user = $this->utenteSpid(verificato: false);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url);

        $this->assertSame($user->id, $pre->fresh()->user_id);
        $this->assertSame('agganciata', $pre->fresh()->storico()->latest('id')->first()->evento);
    }

    public function test_aggancio_ignora_utenti_non_verificati(): void
    {
        $ist = $this->istitutoConPlessi();
        Delega::create([
            'codice_fiscale' => 'RSSMRA80A01G482X', 'id_istituto' => $ist->id_istituto,
            'gruppo_richiesta' => (string) \Illuminate\Support\Str::uuid(), 'stato' => Delega::ATTIVA,
        ]);

        $this->assertSame(0, app(DelegaService::class)->agganciaPredeleghe($this->utenteSpid(verificato: false)));
    }

    public function test_annulla_richiesta_chiude_tutto_il_gruppo(): void
    {
        $ist = $this->istitutoConPlessi();
        [$p1, $p2] = $this->idPlessi($ist);
        $user = $this->utenteSpid();
        $gruppo = app(DelegaService::class)->richiedi($user, $ist, [$p1, $p2])['gruppo'];
        $prima = Delega::where('gruppo_richiesta', $gruppo)->orderBy('id')->first();

        $this->actingAs($user)->post(route('scuola.deleghe.rinuncia', $prima))->assertRedirect();

        $this->assertSame([Delega::REVOCATA, Delega::REVOCATA], Delega::where('gruppo_richiesta', $gruppo)->pluck('stato')->all());
    }
}
