<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\Azione;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\ImpresaAssegnataNotification;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** v0.8.19: email alla ditta = persone delegate + email aziendale, link solo alla pagina SPID. */
class NotificheDittaTest extends TestCase
{
    use RefreshDatabase;

    private User $attore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->attore = User::factory()->create(['auth_source' => 'ldap']);
        $this->attore->syncRoles(['admin']);
        Notification::fake();
    }

    private function delegato(Impresa $impresa, string $email): User
    {
        $u = User::factory()->create(['auth_source' => 'spid', 'email' => $email, 'id_impresa' => $impresa->id_impresa]);
        $u->syncRoles(['impresa']);

        return $u;
    }

    private function assegna(Impresa $impresa): Segnalazione
    {
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'Appalto', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $segnalazione = Segnalazione::create([
            'id_tipologia_segnalazione' => 1, 'id_utente_segnalazione' => $this->attore->id,
            'testo_segnalazione' => 'Guasto', 'flag_riservata' => false, 'flag_pubblicata' => false,
            'id_stato_segnalazione' => 2, 'id_provenienza' => 1,
        ]);

        // azione 3 = assegna_impresa (stati sorgente: [2,6])
        app(SegnalazioneWorkflowService::class)->eseguiAzione($segnalazione, 3, $this->attore, ['id_appalto' => $appalto->id_appalto]);

        return $segnalazione->fresh();
    }

    /** @return list<string> */
    private function indirizziOnDemand(): array
    {
        $indirizzi = [];
        Notification::assertSentOnDemand(ImpresaAssegnataNotification::class, function ($n, $canali, AnonymousNotifiable $dest) use (&$indirizzi) {
            $indirizzi[] = $dest->routes['mail'];

            return true;
        });

        return $indirizzi;
    }

    public function test_notifica_a_persone_delegate_e_email_aziendale(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta', 'email' => 'info@ditta.it']);
        $delegato = $this->delegato($impresa, 'a@ditta.it');

        $this->assegna($impresa);

        Notification::assertSentTo($delegato, ImpresaAssegnataNotification::class);
        $this->assertSame(['info@ditta.it'], $this->indirizziOnDemand());
    }

    public function test_email_uguale_una_sola_notifica(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta', 'email' => ' info@ditta.it ']);
        $delegato = $this->delegato($impresa, 'Info@Ditta.it');

        $this->assegna($impresa);

        Notification::assertSentTo($delegato, ImpresaAssegnataNotification::class);
        Notification::assertSentOnDemandTimes(ImpresaAssegnataNotification::class, 0);
    }

    public function test_senza_delegati_solo_email_aziendale(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta', 'email' => 'info@ditta.it']);

        $this->assegna($impresa);

        $this->assertSame(['info@ditta.it'], $this->indirizziOnDemand());
    }

    public function test_delegato_disattivato_non_riceve(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta']);
        $delegato = $this->delegato($impresa, 'a@ditta.it');
        $delegato->update(['attivo' => false]);

        $this->assegna($impresa);

        Notification::assertNotSentTo($delegato, ImpresaAssegnataNotification::class);
    }

    public function test_ditta_disattivata_nessuna_notifica(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta', 'email' => 'info@ditta.it', 'attiva' => false]);
        $delegato = $this->delegato($impresa, 'a@ditta.it');

        $this->assegna($impresa);

        Notification::assertNothingSent();
        Notification::assertNotSentTo($delegato, ImpresaAssegnataNotification::class);
    }

    public function test_email_ditta_punta_ad_accesso_spid(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta']);
        $segnalazione = $this->assegna($impresa);

        $mail = (new ImpresaAssegnataNotification($segnalazione, Azione::find(3), $this->attore))
            ->toMail(new AnonymousNotifiable());

        $this->assertSame(route('accesso'), $mail->actionUrl);
        $this->assertStringNotContainsString('signature=', json_encode($mail->toArray()));
    }

    public function test_magic_link_rimosso(): void
    {
        $this->assertFalse(Route::has('magic-link.show'));
        $this->assertFalse(Route::has('magic-link.azione'));
    }
}
