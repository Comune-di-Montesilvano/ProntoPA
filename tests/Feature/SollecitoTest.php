<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use App\Notifications\SollecitoNotification;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Il comune sollecita chi ha il lavoro: ditta (delegati + email aziendale), operaio o caposquadra. */
class SollecitoTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        Notification::fake();
    }

    private function delegato(Impresa $impresa, string $email, bool $attivo = true): User
    {
        $u = User::factory()->create(['auth_source' => 'spid', 'email' => $email, 'id_impresa' => $impresa->id_impresa, 'attivo' => $attivo]);
        $u->assignRole('impresa');

        return $u;
    }

    private function aDitta(Impresa $impresa): Segnalazione
    {
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);

        return Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);
    }

    /** @return list<string> */
    private function indirizzi(): array
    {
        $indirizzi = [];
        Notification::assertSentOnDemand(SollecitoNotification::class, function ($n, $c, AnonymousNotifiable $d) use (&$indirizzi) {
            $indirizzi[] = $d->routes['mail'];

            return true;
        });

        return $indirizzi;
    }

    public function test_sollecito_a_ditta_delegati_attivi_ed_email_aziendale(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil', 'email' => 'Info@Edil.it ']);
        $a = $this->delegato($impresa, 'a@edil.it');
        $b = $this->delegato($impresa, 'info@edil.it');
        $spento = $this->delegato($impresa, 'c@edil.it', attivo: false);
        $s = $this->aDitta($impresa);

        $this->actingAs($this->gestore)->post(route('segnalazioni.sollecita', $s), ['testo' => 'Quando passate?'])
            ->assertRedirect(route('segnalazioni.show', $s->id_segnalazione));

        Notification::assertSentTo([$a, $b], SollecitoNotification::class);
        Notification::assertNotSentTo($spento, SollecitoNotification::class);
        // L'email aziendale coincide (maiuscole e spazi a parte) con quella di un delegato: niente doppione.
        Notification::assertSentOnDemandTimes(SollecitoNotification::class, 0);
    }

    public function test_sollecito_a_ditta_senza_delegato_con_stessa_email_avvisa_email_aziendale(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil', 'email' => 'info@edil.it']);
        $this->delegato($impresa, 'a@edil.it');
        $s = $this->aDitta($impresa);

        $this->actingAs($this->gestore)->post(route('segnalazioni.sollecita', $s));

        $this->assertSame(['info@edil.it'], $this->indirizzi());
    }

    public function test_ditta_disattivata_nessuna_notifica(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil', 'email' => 'info@edil.it', 'attiva' => false]);
        $a = $this->delegato($impresa, 'a@edil.it');
        $s = $this->aDitta($impresa);

        $this->actingAs($this->gestore)->post(route('segnalazioni.sollecita', $s))->assertRedirect();

        Notification::assertNotSentTo($a, SollecitoNotification::class);
        Notification::assertSentOnDemandTimes(SollecitoNotification::class, 0);
    }

    public function test_sollecito_a_operaio(): void
    {
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id]);

        $this->actingAs($this->gestore)->post(route('segnalazioni.sollecita', $s));

        Notification::assertSentTo($operaio, SollecitoNotification::class);
    }

    public function test_sollecito_a_squadra_avvisa_caposquadra(): void
    {
        Impostazione::set('squadre_enabled', true);
        $capo = User::factory()->create();
        $capo->assignRole('operaio');
        $squadra = Squadra::create(['nome' => 'Edile', 'id_caposquadra' => $capo->id]);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => 0, 'id_squadra_assegnata' => $squadra->id_squadra]);

        $this->actingAs($this->gestore)->post(route('segnalazioni.sollecita', $s));

        Notification::assertSentTo($capo, SollecitoNotification::class);
    }

    public function test_nota_di_sollecito_con_visibilita_giusta(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil']);
        $s = $this->aDitta($impresa);

        $this->actingAs($this->gestore)->post(route('segnalazioni.sollecita', $s));

        $nota = NotaSegnalazione::where('id_segnalazione', $s->id_segnalazione)->firstOrFail();
        $this->assertSame('Sollecito: aggiornare sullo stato del lavoro.', $nota->testo);
        $this->assertTrue($nota->visibile_impresa);
        $this->assertFalse($nota->visibile_web);
    }

    public function test_segnalatore_non_puo_sollecitare(): void
    {
        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_utente_segnalazione' => $segnalatore->id]);

        $this->actingAs($segnalatore)->post(route('segnalazioni.sollecita', $s))->assertForbidden();
    }
}
