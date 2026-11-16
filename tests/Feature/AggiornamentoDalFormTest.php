<?php

namespace Tests\Feature;

use App\Models\AdesioneSegnalazione;
use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\SollecitoNotification;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

/** Dal form di nuova segnalazione: scrivere sulla segnalazione già aperta invece di aprirne un'altra. */
class AggiornamentoDalFormTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private Istituto $scuola;

    private User $autore;

    private User $collega;

    private User $gestore;

    private Segnalazione $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        Storage::fake('local');
        Impostazione::set('adesioni_enabled', false);

        $this->scuola = $this->istitutoConPlessi();
        $plesso = $this->idPlessi($this->scuola)[0];
        $this->autore = $this->utenteSpid('RSSMRA80A01G482X');
        $this->collega = $this->utenteSpid('BNCLRA82B42G482Y');
        $this->delega($this->autore, $this->scuola, null);
        $this->delega($this->collega, $this->scuola, null);
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');

        $this->s = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $this->autore->id, 'id_plesso' => $plesso,
            'id_stato_segnalazione' => 2, 'id_operatore_assegnato' => $this->gestore->id,
            'data_chiusura' => null, 'data_scadenza_sla' => now()->addDays(5),
        ]);
    }

    private function invia(User $u, array $dati = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u)->post(route('segnalazioni.aggiornamento', $this->s), $dati);
    }

    public function test_testo_vuoto_scrive_sollecito_e_avvisa(): void
    {
        $this->invia($this->autore)
            ->assertRedirect(route('segnalazioni.show', $this->s->id_segnalazione))
            ->assertSessionHas('success', "Inviato alla #{$this->s->id_segnalazione}.");

        $nota = $this->s->note()->firstOrFail();
        $this->assertSame(NotaSegnalazione::ORIGINE_SOLLECITO, $nota->origine);
        $this->assertSame("Sollecito: il problema c'è ancora, chiedo un aggiornamento.", $nota->testo);
        $this->assertTrue($nota->visibile_web);
        Notification::assertSentTo($this->gestore, SollecitoNotification::class);
    }

    public function test_con_testo_scrive_integrazione(): void
    {
        $this->invia($this->autore, ['testo' => 'Ora perde anche il lavandino'])->assertRedirect();

        $nota = $this->s->note()->firstOrFail();
        $this->assertSame(NotaSegnalazione::ORIGINE_INTEGRAZIONE, $nota->origine);
        $this->assertSame('Ora perde anche il lavandino', $nota->testo);
    }

    public function test_solo_foto_scrive_integrazione_con_allegato(): void
    {
        $this->invia($this->autore, ['allegati' => [UploadedFile::fake()->image('perdita.jpg')]])->assertRedirect();

        $nota = $this->s->note()->firstOrFail();
        $this->assertSame(NotaSegnalazione::ORIGINE_INTEGRAZIONE, $nota->origine);
        $this->assertSame('Ha aggiunto delle foto.', $nota->testo);
        $this->assertSame(1, $this->s->allegati()->count());
    }

    public function test_non_autore_diventa_aderente_una_volta(): void
    {
        $this->invia($this->collega)->assertRedirect();
        $this->invia($this->collega, ['testo' => 'Confermo'])->assertRedirect();

        $this->assertSame(1, AdesioneSegnalazione::where('id_segnalazione', $this->s->id_segnalazione)->where('id_utente', $this->collega->id)->count());
        $this->assertSame(0, AdesioneSegnalazione::where('id_utente', $this->autore->id)->count());
    }

    public function test_403_se_non_la_vede(): void
    {
        $estraneo = User::factory()->create();
        $estraneo->assignRole('segnalatore');

        $this->invia($estraneo)->assertForbidden();
    }

    public function test_rifiutato_su_chiusa(): void
    {
        $this->s->update(['id_stato_segnalazione' => 7, 'data_chiusura' => now()]);

        $this->invia($this->autore)->assertForbidden();
    }

    public function test_non_serve_scadenza_o_inattivita(): void
    {
        $this->assertFalse($this->autore->can('sollecitaComeSegnalante', $this->s));

        $this->invia($this->autore)->assertRedirect();
        $this->assertSame(1, $this->s->note()->count());
    }
}
