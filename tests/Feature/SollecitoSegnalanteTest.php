<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\SollecitoNotification;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Chi ha aperto la segnalazione sollecita chi ce l'ha in carico, se è in ritardo o ferma. */
class SollecitoSegnalanteTest extends TestCase
{
    use RefreshDatabase;

    private User $segnalatore;

    private User $ditta;

    private User $supervisore;

    private Segnalazione $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', 'Europe/Rome'));
        Notification::fake();

        $this->segnalatore = User::factory()->create();
        $this->segnalatore->assignRole('segnalatore');
        $this->supervisore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->supervisore->assignRole('gestore');
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'Edile', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');

        $this->s = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $this->segnalatore->id,
            'id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto,
            'data_scadenza_sla' => now()->subDay(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sollecita(?User $chi = null, ?Segnalazione $s = null)
    {
        return $this->actingAs($chi ?? $this->segnalatore)
            ->post(route('segnalazioni.sollecito-segnalante', $s ?? $this->s), ['testo' => 'Il rubinetto perde ancora']);
    }

    public function test_segnalante_sollecita_la_ditta(): void
    {
        $this->sollecita()->assertRedirect(route('segnalazioni.show', $this->s->id_segnalazione));

        $nota = $this->s->note()->latest('id_nota')->firstOrFail();
        $this->assertStringStartsWith('Sollecito', $nota->testo);
        $this->assertTrue((bool) $nota->visibile_web);
        $this->assertTrue((bool) $nota->visibile_impresa);
        Notification::assertSentTo($this->ditta, SollecitoNotification::class, fn ($n) => $n->dalSegnalante);
        Notification::assertNotSentTo($this->supervisore, SollecitoNotification::class);
    }

    public function test_non_affidata_sollecita_il_comune(): void
    {
        $s = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $this->segnalatore->id, 'id_stato_segnalazione' => 1, 'data_scadenza_sla' => now()->subDay(),
        ]);

        $this->sollecita(null, $s)->assertRedirect();

        Notification::assertSentTo($this->supervisore, SollecitoNotification::class);
    }

    public function test_una_volta_ogni_tre_giorni_lavorativi(): void
    {
        $this->sollecita()->assertRedirect();
        $this->sollecita()->assertForbidden();

        Carbon::setTestNow(now()->addDays(5)); // sabato 15/11 → lun 17/11 dopo 3 g lav. da mar 10/11 = ven 13/11
        $this->sollecita()->assertRedirect();
    }

    public function test_solo_se_in_ritardo_o_ferma(): void
    {
        $this->s->update(['data_scadenza_sla' => now()->addDays(3)]);
        $this->sollecita()->assertForbidden();

        $this->s->update(['avviso_inattivita_at' => now()->subDay()]);
        $this->sollecita()->assertRedirect();
    }

    public function test_altri_utenti_e_sospese_no(): void
    {
        $altro = User::factory()->create();
        $altro->assignRole('segnalatore');
        $this->sollecita($altro)->assertForbidden();

        $this->s->update(['id_stato_segnalazione' => 6]);
        $this->sollecita()->assertForbidden();
    }

    public function test_scheda_segnalante_mostra_sollecita(): void
    {
        $this->actingAs($this->segnalatore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertSee(route('segnalazioni.sollecito-segnalante', $this->s), false);

        $this->s->update(['data_scadenza_sla' => now()->addDays(3)]);
        $this->actingAs($this->segnalatore)->get(route('segnalazioni.show', $this->s))
            ->assertOk()
            ->assertDontSee(route('segnalazioni.sollecito-segnalante', $this->s), false);
    }
}
