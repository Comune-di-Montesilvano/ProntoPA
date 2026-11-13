<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Appalto;
use App\Models\Azione;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\FineLavoriNotification;
use App\Notifications\SegnalazioneChiusaNotification;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Correzioni della review finale della tappa 1 (iter segnalazioni semplice). */
class RevisioneIterTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $ditta;

    private Appalto $appalto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Storage::fake('local');
        Notification::fake();

        $this->gestore = User::factory()->create(['name' => 'Anna Bianchi', 'supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $impresa = Impresa::create(['ragione_sociale' => 'Edil']);
        $this->appalto = Appalto::create(['descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');
    }

    private function diDitta(int $stato): Segnalazione
    {
        return Segnalazione::factory()->create(['id_stato_segnalazione' => $stato, 'id_appalto' => $this->appalto->id_appalto]);
    }

    public function test_ditta_non_esegue_azioni_del_comune(): void
    {
        foreach ([[4, 9], [4, 3], [4, 1], [5, 7], [4, 12]] as [$stato, $azione]) {
            $s = $this->diDitta($stato);

            $this->actingAs($this->ditta)->post(route('segnalazioni.azione', $s), ['id_azione' => $azione, 'id_appalto' => $this->appalto->id_appalto, 'motivo' => 'errata'])
                ->assertSessionHasErrors('azione');

            $this->assertSame($stato, $s->fresh()->statoEnum()->value, "azione {$azione}");
        }
    }

    public function test_fine_lavori_ditta_senza_preventivo_chiude_e_avvisa_chiusa(): void
    {
        $s = $this->diDitta(4);

        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $s), ['nota' => 'Fatto'])->assertSessionHasNoErrors();

        $this->assertSame(SegnalazioneStato::COMPLETATA, $s->fresh()->statoEnum());
        Notification::assertSentTo($this->gestore, FineLavoriNotification::class, fn (FineLavoriNotification $n) => $n->chiusa === true);
    }

    public function test_fine_lavori_con_preventivo_avvisa_da_verificare(): void
    {
        $s = $this->diDitta(4);
        StoricoStatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 5, 'id_utente' => $this->ditta->id, 'id_utente_collegato' => 0, 'id_appalto' => 0]);

        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $s), ['nota' => 'Fatto']);

        Notification::assertSentTo($this->gestore, FineLavoriNotification::class, fn (FineLavoriNotification $n) => $n->chiusa === false);
        $this->assertStringContainsString('Verifica il lavoro', implode("\n", (new FineLavoriNotification($s, $s->note()->first(), 'Edil', false))->toMail($this->gestore)->introLines));
    }

    public function test_gestore_toglie_scadenza_manuale(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3]);
        $s->forceFill(['scadenza_manuale' => true, 'data_scadenza_sla' => now()->addDays(40)])->save();

        $this->actingAs($this->gestore)->patch(route('segnalazioni.scadenza-automatica', $s))->assertRedirect();

        $this->assertFalse($s->fresh()->scadenza_manuale);
    }

    public function test_scadenza_automatica_vietata_a_chi_non_gestisce(): void
    {
        $s = $this->diDitta(4);
        $s->forceFill(['scadenza_manuale' => true])->save();

        $this->actingAs($this->ditta)->patch(route('segnalazioni.scadenza-automatica', $s))->assertForbidden();
        $this->assertTrue($s->fresh()->scadenza_manuale);
    }

    public function test_azioni_rapide_solo_senza_campi(): void
    {
        $wf = app(SegnalazioneWorkflowService::class);

        foreach ([1, 2, 3, 5] as $stato) {
            $codici = $wf->getAzioniRapide(Segnalazione::factory()->create(['id_stato_segnalazione' => $stato]), $this->gestore)->pluck('codice')->all();

            foreach (['sospendi', 'annulla', 'rifiuta_preventivo', 'presenta_preventivo', 'collega', 'prendi_in_carico'] as $vietata) {
                $this->assertNotContains($vietata, $codici, "stato {$stato}");
            }
        }
    }

    public function test_riapri_stacca_ditta_e_squadra(): void
    {
        $s = $this->diDitta(7);
        $s->forceFill(['data_chiusura' => now()])->save();

        app(SegnalazioneWorkflowService::class)->eseguiAzione($s, Azione::where('codice', 'riapri')->value('id_azione'), $this->gestore);

        $s->refresh();
        $this->assertNull($s->id_appalto);
        $this->assertNull($s->id_squadra_assegnata);
        $this->assertSame($this->gestore->id, $s->id_operatore_assegnato);
    }

    public function test_email_chiusa_al_segnalante_esterno_senza_nomi_interni(): void
    {
        $scuola = User::factory()->create(['auth_source' => 'spid']);
        $scuola->assignRole('segnalatore');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 7]);
        $operaio = User::factory()->create(['name' => 'Paolo Neri']);
        $operaio->assignRole('operaio');

        $esterna = implode("\n", (new SegnalazioneChiusaNotification($s, Azione::find(9), $operaio))->toMail($scuola)->introLines);
        $interna = implode("\n", (new SegnalazioneChiusaNotification($s, Azione::find(9), $operaio))->toMail($this->gestore)->introLines);

        $this->assertStringNotContainsString('Paolo Neri', $esterna);
        $this->assertStringContainsString('dal comune', $esterna);
        $this->assertStringContainsString('Paolo Neri', $interna);
    }

    public function test_assegna_solo_a_utenti_assegnabili_e_squadre_attive(): void
    {
        $scuola = User::factory()->create(['auth_source' => 'spid']);
        $scuola->assignRole('segnalatore');
        $squadra = \App\Models\Squadra::create(['nome' => 'Elettricisti', 'id_caposquadra' => $this->gestore->id, 'attiva' => true]);
        \App\Models\Impostazione::set('squadre_enabled', false);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);

        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $s), ['id_azione' => 2, 'id_operatore' => $scuola->id])
            ->assertSessionHasErrors('id_operatore');
        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $s), ['id_azione' => 2, 'id_squadra' => $squadra->id_squadra])
            ->assertSessionHasErrors('id_squadra');
        $this->actingAs($this->gestore)->post(route('gestione.azioni-multiple'), ['ids' => [$s->id_segnalazione], 'operazione' => 'assegna', 'tipo' => 'operaio', 'id_operatore' => $scuola->id])
            ->assertSessionHasErrors('id_operatore');
        $this->actingAs($this->gestore)->post(route('gestione.azioni-multiple'), ['ids' => [$s->id_segnalazione], 'operazione' => 'assegna', 'tipo' => 'squadra', 'id_squadra' => $squadra->id_squadra])
            ->assertSessionHasErrors('id_squadra');

        $this->assertSame(SegnalazioneStato::NUOVA, $s->fresh()->statoEnum());
    }
}
