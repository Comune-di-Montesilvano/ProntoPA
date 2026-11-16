<?php

namespace Tests\Feature;

use App\Models\Azione;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use App\Services\Segnalazioni\AccorpamentoService;
use App\Services\Segnalazioni\ScadenzaManuale;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Le note automatiche sanno da dove vengono: il feed le racconta come fatti, non come messaggi. */
class OrigineNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $segnalatore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        Queue::fake();
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->segnalatore = User::factory()->create();
        $this->segnalatore->assignRole('segnalatore');
    }

    private function segnalazione(array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create(['id_utente_segnalazione' => $this->segnalatore->id, 'id_stato_segnalazione' => 2] + $attr);
    }

    private function notaGrezza(Segnalazione $s, string $testo): int
    {
        return (int) DB::table('note_segnalazioni')->insertGetId([
            'id_segnalazione' => $s->id_segnalazione, 'testo' => $testo, 'id_utente' => $this->gestore->id,
            'visibile_web' => false, 'visibile_impresa' => false, 'data' => now(), 'origine' => null,
        ], 'id_nota');
    }

    public function test_backfill_riconosce_ogni_prefisso(): void
    {
        $s = $this->segnalazione();
        $attese = [
            'Scadenza spostata al 16/11/2026.' => 'scadenza',
            "Segnalata di nuovo da Patrizia il 16/11:\nAncora rotto" => 'risegnalata',
            'Sollecito: arrivate?' => 'sollecito',
            'Rimandata al comune: manca il pezzo' => 'rimando',
            'Priorità aumentata a livello 3 dopo 2 adesioni.' => 'priorita',
            'Chiusa senza intervento: Già risolta.' => 'senza_intervento',
            'Sollecito urgente per la palestra' => null,
            'Nota qualunque' => null,
        ];
        $id = [];
        foreach (array_keys($attese) as $testo) {
            $id[$testo] = $this->notaGrezza($s, $testo);
        }

        NotaSegnalazione::riempiOrigini();

        foreach ($attese as $testo => $origine) {
            $this->assertSame($origine, DB::table('note_segnalazioni')->where('id_nota', $id[$testo])->value('origine'), $testo);
        }
    }

    public function test_testo_senza_prefisso(): void
    {
        $nota = new NotaSegnalazione(['testo' => 'Sollecito: arrivate?', 'origine' => NotaSegnalazione::ORIGINE_SOLLECITO]);
        $this->assertSame('arrivate?', $nota->testoSenzaPrefisso());

        $libera = new NotaSegnalazione(['testo' => 'Sollecito: scritto a mano']);
        $this->assertSame('Sollecito: scritto a mano', $libera->testoSenzaPrefisso());
    }

    public function test_scadenza_manuale_nasce_con_origine(): void
    {
        $s = $this->segnalazione();
        $nota = app(ScadenzaManuale::class)->imposta($s, Carbon::parse('2026-12-01'), $this->gestore, 'serve il pezzo');

        $this->assertSame('scadenza', $nota->fresh()->origine);
    }

    public function test_sollecito_segnalante_nasce_con_origine(): void
    {
        $s = $this->segnalazione(['data_scadenza_sla' => now()->subDay()]);

        $this->actingAs($this->segnalatore)->post(route('segnalazioni.sollecito-segnalante', $s))->assertRedirect();

        $this->assertSame('sollecito', $s->note()->latest('id_nota')->value('origine'));
    }

    public function test_accorpa_segna_solo_la_nota_sulla_destinazione(): void
    {
        $vecchia = $this->segnalazione();
        $nuova = $this->segnalazione(['id_stato_segnalazione' => 1]);

        app(AccorpamentoService::class)->accorpa($nuova, $vecchia, $this->gestore);

        $this->assertSame('risegnalata', $vecchia->note()->value('origine'));
        $this->assertNull($nuova->note()->value('origine'));
    }

    public function test_chiudi_senza_intervento_nasce_con_origine(): void
    {
        $s = $this->segnalazione();

        app(SegnalazioneWorkflowService::class)->eseguiAzione(
            $s, Azione::where('codice', 'annulla')->value('id_azione'), $this->gestore, ['motivo' => 'gia_risolta'],
        );

        $this->assertSame('senza_intervento', $s->note()->value('origine'));
    }

    public function test_rimanda_nasce_con_origine(): void
    {
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $s = $this->segnalazione(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id]);
        StoricoStatoSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 3,
            'id_utente' => $this->gestore->id, 'id_utente_collegato' => $operaio->id, 'id_appalto' => 0,
        ]);

        app(SegnalazioneWorkflowService::class)->rimandaAlGestore($s, $operaio, 'manca il pezzo');

        $this->assertSame('rimando', $s->note()->value('origine'));
    }
}
