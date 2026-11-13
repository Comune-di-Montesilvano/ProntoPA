<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\ImpresaAssegnataNotification;
use App\Notifications\OperatoreAssegnatoNotification;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Transizioni dello schema semplificato (spec §1): assegna da Nuova, riassegna, assegna a me, riapri, riprendi. */
class WorkflowSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const PRENDI = 1;
    private const OPERATORE = 2;
    private const IMPRESA = 3;
    private const RIPRENDI = 5;
    private const CHIUDI = 9;
    private const RIAPRI = 14;

    private User $gestore;

    private User $operaio;

    private SegnalazioneWorkflowService $wf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        SlaConfigurazione::create(['livello_priorita' => 2, 'ore_target' => 10, 'ore_warning' => 14, 'unita' => 'giorni', 'descrizione' => 'Media']);

        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->operaio = User::factory()->create();
        $this->operaio->assignRole('operaio');
        $this->wf = app(SegnalazioneWorkflowService::class);
    }

    private function segnalazione(array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create(array_merge(['id_stato_segnalazione' => 1, 'livello_priorita' => 2], $attr));
    }

    /** @return array{0: User, 1: Appalto} */
    private function ditta(): array
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil']);
        $appalto = Appalto::create(['descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $utente = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $utente->assignRole('impresa');

        return [$utente, $appalto];
    }

    public function test_assegna_operatore_da_nuova(): void
    {
        $s = $this->segnalazione();

        $this->wf->eseguiAzione($s, self::OPERATORE, $this->gestore, ['id_operatore' => $this->operaio->id]);

        $s->refresh();
        $this->assertSame(SegnalazioneStato::ASSEGNATA_OPERATORE, $s->statoEnum());
        $this->assertSame($this->operaio->id, $s->id_operatore_assegnato);
        Notification::assertSentTo($this->operaio, OperatoreAssegnatoNotification::class);
    }

    public function test_assegna_a_me_da_nuova(): void
    {
        $s = $this->segnalazione();

        $this->wf->eseguiAzione($s, self::PRENDI, $this->gestore);

        $s->refresh();
        $this->assertSame(SegnalazioneStato::IN_CARICO, $s->statoEnum());
        $this->assertSame($this->gestore->id, $s->id_operatore_assegnato);
        Notification::assertNotSentTo($this->gestore, OperatoreAssegnatoNotification::class);
    }

    public function test_riassegna_da_ditta_a_operaio_toglie_visibilita_ditta(): void
    {
        [$utenteDitta, $appalto] = $this->ditta();
        $s = $this->segnalazione(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);
        $this->assertTrue(Segnalazione::visibileA($utenteDitta)->whereKey($s->id_segnalazione)->exists());

        $this->wf->eseguiAzione($s, self::OPERATORE, $this->gestore, ['id_operatore' => $this->operaio->id]);

        $s->refresh();
        $this->assertNull($s->id_appalto);
        $this->assertSame(SegnalazioneStato::ASSEGNATA_OPERATORE, $s->statoEnum());
        $this->assertFalse(Segnalazione::visibileA($utenteDitta)->whereKey($s->id_segnalazione)->exists());
        Notification::assertNotSentTo($utenteDitta, ImpresaAssegnataNotification::class);
    }

    public function test_assegna_ditta_azzera_operatore(): void
    {
        [, $appalto] = $this->ditta();
        $s = $this->segnalazione(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $this->operaio->id]);

        $this->wf->eseguiAzione($s, self::IMPRESA, $this->gestore, ['id_appalto' => $appalto->id_appalto]);

        $s->refresh();
        $this->assertSame(0, $s->id_operatore_assegnato);
        $this->assertSame($appalto->id_appalto, (int) $s->id_appalto);
    }

    public function test_riassegna_conserva_scadenza_manuale(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $this->operaio->id]);
        $s->forceFill(['scadenza_manuale' => true, 'data_scadenza_sla' => now()->addDays(20)])->save();
        $prima = $s->refresh()->data_scadenza_sla;
        $altro = User::factory()->create();
        $altro->assignRole('operaio');

        $this->wf->eseguiAzione($s, self::OPERATORE, $this->gestore, ['id_operatore' => $altro->id]);

        $this->assertTrue($prima->equalTo($s->refresh()->data_scadenza_sla));
        $this->assertTrue($s->scadenza_manuale);
    }

    public function test_riassegna_con_nuova_data_la_imposta(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $this->operaio->id]);
        $data = Carbon::now()->addDays(30)->format('Y-m-d');

        $this->wf->eseguiAzione($s, self::OPERATORE, $this->gestore, ['id_operatore' => $this->operaio->id, 'data_scadenza' => $data]);

        $s->refresh();
        $this->assertTrue($s->scadenza_manuale);
        $this->assertGreaterThanOrEqual($data, $s->data_scadenza_sla->format('Y-m-d'));
    }

    public function test_assegnazione_azzera_fine_lavori(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $this->operaio->id]);
        $s->forceFill(['fine_lavori_at' => now()])->save();

        $this->wf->eseguiAzione($s, self::OPERATORE, $this->gestore, ['id_operatore' => $this->operaio->id]);

        $this->assertNull($s->refresh()->fine_lavori_at);
    }

    public function test_riapri_mette_in_carico_a_chi_riapre_e_azzera_manuale(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 7, 'data_chiusura' => now(), 'id_operatore_assegnato' => $this->operaio->id]);
        $s->forceFill(['scadenza_manuale' => true, 'fine_lavori_at' => now()])->save();

        $this->wf->eseguiAzione($s, self::RIAPRI, $this->gestore);

        $s->refresh();
        $this->assertSame(SegnalazioneStato::IN_CARICO, $s->statoEnum());
        $this->assertSame($this->gestore->id, $s->id_operatore_assegnato);
        $this->assertFalse($s->scadenza_manuale);
        $this->assertNull($s->fine_lavori_at);
    }

    public function test_riprendi_ricalcola_scadenza(): void
    {
        $s = $this->segnalazione(['id_stato_segnalazione' => 6]);
        $s->forceFill(['scadenza_manuale' => true, 'data_scadenza_sla' => now()->subDays(40)])->save();
        StoricoStatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 2, 'id_utente' => $this->gestore->id, 'id_utente_collegato' => 0, 'id_appalto' => 0]);

        $this->wf->eseguiAzione($s, self::RIPRENDI, $this->gestore);

        $s->refresh();
        $this->assertFalse($s->scadenza_manuale);
        $this->assertTrue($s->data_scadenza_sla->isAfter(now()->subDays(40)->addDay()));
    }

    public function test_chiudi_da_nuova_non_applicabile(): void
    {
        $this->expectException(ValidationException::class);

        $this->wf->eseguiAzione($this->segnalazione(), self::CHIUDI, $this->gestore);
    }

    public function test_chiudi_da_preventivo_non_applicabile(): void
    {
        $this->expectException(ValidationException::class);

        $this->wf->eseguiAzione($this->segnalazione(['id_stato_segnalazione' => 5]), self::CHIUDI, $this->gestore);
    }
}
