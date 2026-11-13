<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Appalto;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\RimandataAlGestoreNotification;
use App\Support\Segnalazioni\Attivita;
use App\Support\Segnalazioni\ProssimoPasso;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** 1.0.0: operaio, caposquadra e ditta rimandano il lavoro al gestore che l'aveva assegnato. */
class RimandaAlGestoreTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $supervisore;

    private User $operaio;

    private User $ditta;

    private Appalto $appalto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();

        $this->gestore = User::factory()->create(['name' => 'Gino Gestore', 'supervisore_segnalazioni' => false]);
        $this->gestore->assignRole('gestore');
        $this->supervisore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->supervisore->assignRole('gestore');
        $this->operaio = User::factory()->create(['name' => 'Olga Operaia']);
        $this->operaio->assignRole('operaio');
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Rimanda']);
        $this->appalto = Appalto::create(['descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');
    }

    /** @param array<string, mixed> $attr */
    private function assegnata(int $stato, array $attr, ?User $daChi = null): Segnalazione
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => $stato, 'data_scadenza_sla' => now()->addDays(3)->startOfMinute()] + $attr);
        StoricoStatoSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => in_array($stato, [4, 5], true) ? 4 : 3,
            'id_utente' => ($daChi ?? $this->gestore)->id, 'id_utente_collegato' => $attr['id_operatore_assegnato'] ?? 0, 'id_appalto' => $attr['id_appalto'] ?? 0,
        ]);
        if ($stato === 5) {
            StoricoStatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 5, 'id_utente' => $this->ditta->id, 'id_utente_collegato' => 0, 'id_appalto' => 0]);
        }

        return $s->fresh();
    }

    public function test_operaio_rimanda_torna_in_carico_al_gestore_che_aveva_assegnato(): void
    {
        $s = $this->assegnata(3, ['id_operatore_assegnato' => $this->operaio->id]);

        $this->actingAs($this->operaio)->post(route('segnalazioni.rimanda', $s), ['nota' => 'Serve un elettricista'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame(SegnalazioneStato::IN_CARICO, $s->statoEnum());
        $this->assertSame($this->gestore->id, (int) $s->id_operatore_assegnato);
        Notification::assertSentTo($this->gestore, RimandataAlGestoreNotification::class,
            fn (RimandataAlGestoreNotification $n) => $n->nota === 'Serve un elettricista' && $n->mittente === 'Olga Operaia');
        $this->assertDatabaseHas('note_segnalazioni', ['id_segnalazione' => $s->id_segnalazione, 'visibile_web' => false]);
        $this->actingAs($this->operaio)->get(route('segnalazioni.show', $s))->assertForbidden();
    }

    public function test_ditta_rimanda_da_preventivo_perde_visibilita(): void
    {
        $s = $this->assegnata(5, ['id_appalto' => $this->appalto->id_appalto]);

        $this->actingAs($this->ditta)->post(route('segnalazioni.rimanda', $s), ['nota' => 'Non è nel nostro appalto'])
            ->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame(SegnalazioneStato::IN_CARICO, $s->statoEnum());
        $this->assertNull($s->id_appalto);
        Notification::assertSentTo($this->gestore, RimandataAlGestoreNotification::class,
            fn (RimandataAlGestoreNotification $n) => $n->mittente === 'Edil Rimanda');
        $this->actingAs($this->ditta)->get(route('segnalazioni.show', $s))->assertForbidden();
    }

    public function test_nota_obbligatoria(): void
    {
        $s = $this->assegnata(3, ['id_operatore_assegnato' => $this->operaio->id]);

        $this->actingAs($this->operaio)->post(route('segnalazioni.rimanda', $s), ['nota' => ''])
            ->assertSessionHasErrors('nota');
        $this->assertSame(SegnalazioneStato::ASSEGNATA_OPERATORE, $s->fresh()->statoEnum());
    }

    public function test_membro_non_caposquadra_403_caposquadra_si(): void
    {
        Impostazione::set('squadre_enabled', true);
        $capo = User::factory()->create();
        $capo->assignRole('operaio');
        $squadra = Squadra::create(['nome' => 'Edile', 'id_caposquadra' => $capo->id, 'attiva' => true]);
        $squadra->membri()->attach([$capo->id, $this->operaio->id]);
        $s = $this->assegnata(3, ['id_operatore_assegnato' => 0, 'id_squadra_assegnata' => $squadra->id_squadra]);

        $this->actingAs($this->operaio)->post(route('segnalazioni.rimanda', $s), ['nota' => 'No'])->assertForbidden();
        $this->actingAs($capo)->post(route('segnalazioni.rimanda', $s), ['nota' => 'Non è per noi'])->assertSessionHasNoErrors();

        $this->assertNull($s->fresh()->id_squadra_assegnata);
    }

    public function test_altri_e_stati_sbagliati_403(): void
    {
        $altro = User::factory()->create();
        $altro->assignRole('operaio');
        $s = $this->assegnata(3, ['id_operatore_assegnato' => $this->operaio->id]);
        $this->actingAs($altro)->post(route('segnalazioni.rimanda', $s), ['nota' => 'x'])->assertForbidden();
        $this->actingAs($this->gestore)->post(route('segnalazioni.rimanda', $s), ['nota' => 'x'])->assertForbidden();

        $inCarico = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_operatore_assegnato' => $this->operaio->id]);
        $this->actingAs($this->operaio)->post(route('segnalazioni.rimanda', $inCarico), ['nota' => 'x'])->assertForbidden();
    }

    public function test_gestore_non_attivo_torna_nuova_e_avvisa_supervisori(): void
    {
        $s = $this->assegnata(3, ['id_operatore_assegnato' => $this->operaio->id]);
        $this->gestore->update(['attivo' => false]);

        $this->actingAs($this->operaio)->post(route('segnalazioni.rimanda', $s), ['nota' => 'Serve altro'])->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame(SegnalazioneStato::NUOVA, $s->statoEnum());
        $this->assertSame(0, (int) $s->id_operatore_assegnato);
        Notification::assertSentTo($this->supervisore, RimandataAlGestoreNotification::class);
        Notification::assertNotSentTo($this->gestore, RimandataAlGestoreNotification::class);
    }

    public function test_scadenza_invariata(): void
    {
        $s = $this->assegnata(4, ['id_appalto' => $this->appalto->id_appalto]);
        $prima = $s->data_scadenza_sla->toDateTimeString();

        $this->actingAs($this->ditta)->post(route('segnalazioni.rimanda', $s), ['nota' => 'Non è nostro']);

        $this->assertSame($prima, $s->fresh()->data_scadenza_sla->toDateTimeString());
    }

    public function test_attivita_e_prossimo_passo(): void
    {
        $s = $this->assegnata(3, ['id_operatore_assegnato' => $this->operaio->id]);

        $passo = ProssimoPasso::per($s, $this->operaio);
        $this->assertContains('rimanda', collect($passo['azioni'])->pluck('pannello')->all());
        $this->actingAs($this->operaio)->get(route('segnalazioni.show', $s))
            ->assertOk()->assertSee('Rimanda al comune')->assertSee(route('segnalazioni.rimanda', $s), false);

        $this->actingAs($this->operaio)->post(route('segnalazioni.rimanda', $s), ['nota' => 'Serve un elettricista']);

        $frasi = Attivita::per($s->fresh(), $this->gestore)->pluck('frase')->all();
        $this->assertContains('ha rimandato al comune', $frasi);
        $this->assertNotContains('rimanda', app(\App\Services\SegnalazioneWorkflowService::class)
            ->getAzioniDisponibili($s->fresh(), $this->gestore)->pluck('codice')->all());
    }
}
