<?php

namespace Tests\Feature;

use App\Models\AllegatoSegnalazione;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\NotaDaEsternoNotification;
use App\Support\Segnalazioni\Attivita;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Storia unica della segnalazione (note, stati, allegati) letta per ruolo, e note scritte da ditta e segnalante. */
class AttivitaTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $operaio;

    private User $segnalatore;

    private User $ditta;

    private Segnalazione $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);

        $this->gestore = User::factory()->create(['name' => 'Anna Gestore', 'supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->operaio = User::factory()->create(['name' => 'Paolo Neri']);
        $this->operaio->assignRole('operaio');
        $this->segnalatore = User::factory()->create(['name' => 'Giulia Ferri']);
        $this->segnalatore->assignRole('segnalatore');

        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $appalto = Appalto::create(['descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');

        $this->s = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $this->segnalatore->id,
            'id_stato_segnalazione' => 4,
            'id_appalto' => $appalto->id_appalto,
        ]);
        $storico = fn (int $stato, int $collegato = 0, int $appalto = 0, string $quando = '2026-10-21 10:00') => StoricoStatoSegnalazione::create([
            'id_segnalazione' => $this->s->id_segnalazione, 'id_stato_segnalazione' => $stato,
            'id_utente' => $this->gestore->id, 'id_utente_collegato' => $collegato, 'id_appalto' => $appalto,
        ])->forceFill(['data_registrazione' => $quando])->save();
        $storico(2, quando: '2026-10-21 09:00');
        $storico(3, $this->operaio->id, quando: '2026-10-21 10:00');
        $storico(4, 0, $appalto->id_appalto, '2026-10-22 10:00');
        NotaSegnalazione::create([
            'id_segnalazione' => $this->s->id_segnalazione, 'testo' => 'Ricambio ordinato',
            'id_utente' => $this->gestore->id, 'visibile_web' => true, 'visibile_impresa' => true,
        ]);
    }

    /** @return list<string> */
    private function testi(User $u): array
    {
        return Attivita::per($this->s->fresh(), $u)
            ->map(fn ($v) => $v['autore'].' | '.$v['frase'].' | '.($v['testo'] ?? ''))
            ->all();
    }

    /** Storico inserito con id decrescenti: l'ordine deve venire dalla data. */
    private function segnalazioneConStorico(array $righe): Segnalazione
    {
        $s = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $this->segnalatore->id,
            'id_stato_segnalazione' => 2,
            'id_operatore_assegnato' => $this->gestore->id,
        ]);
        foreach (array_reverse($righe) as [$stato, $collegato, $quando]) {
            $collegato = $collegato === -1 ? $this->gestore->id : $collegato; // -1 = chi registra
            StoricoStatoSegnalazione::create([
                'id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => $stato,
                'id_utente' => $this->gestore->id, 'id_utente_collegato' => $collegato, 'id_appalto' => 0,
            ])->forceFill(['data_registrazione' => $quando])->save();
        }

        return $s->fresh();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function passaggiDalPiuVecchio(Segnalazione $s): \Illuminate\Support\Collection
    {
        return Attivita::per($s, $this->gestore)->where('tipo', 'stato')->reverse()->values();
    }

    public function test_storico_3579_racconta_una_presa_in_carico_e_la_ripresa(): void
    {
        $g = $this->gestore->id;
        $s = $this->segnalazioneConStorico([
            [2, $g, '2026-11-13 08:16:08'],
            [6, 0, '2026-11-13 14:00:34'],
            [2, 0, '2026-11-13 14:01:02'],
            [2, $g, '2026-11-13 14:01:23'],
        ]);

        $stati = $this->passaggiDalPiuVecchio($s);

        $this->assertSame(["l'ha presa in carico", 'ha messo in pausa', 'ha ripreso il lavoro'], $stati->pluck('frase')->all());
        $this->assertSame('08:16', $stati->first()['quando']->format('H:i'));
    }

    public function test_righe_identiche_consecutive_mostrate_una_volta(): void
    {
        $o = $this->operaio->id;
        $s = $this->segnalazioneConStorico([
            [3, $o, '2026-11-13 09:00:00'],
            [3, $o, '2026-11-13 09:01:00'],
        ]);

        $this->assertCount(1, $this->passaggiDalPiuVecchio($s));
    }

    public function test_segnalante_vede_ufficio_non_nomi(): void
    {
        $tutto = implode("\n", $this->testi($this->segnalatore));

        $this->assertStringNotContainsString('Anna Gestore', $tutto);
        $this->assertStringNotContainsString('Paolo Neri', $tutto);
        $this->assertStringNotContainsString('Edil Adriatica', $tutto);
        $this->assertStringContainsString('Ufficio manutenzioni', $tutto);
        $this->assertStringContainsString('Ricambio ordinato', $tutto);
    }

    public function test_ditta_vede_eventi_da_assegnazione(): void
    {
        $tutto = implode("\n", $this->testi($this->ditta));

        $this->assertStringNotContainsString("l'ha presa in carico", $tutto);
        $this->assertStringContainsString('Edil Adriatica', $tutto);
    }

    public function test_gestore_vede_frase_assegnazione(): void
    {
        $tutto = implode("\n", $this->testi($this->gestore));

        $this->assertStringContainsString("l'ha assegnata a Paolo Neri", $tutto);
        $this->assertStringContainsString("l'ha riassegnata a Edil Adriatica", $tutto);
        $this->assertStringContainsString("l'ha presa in carico", $tutto);
        $prima = Attivita::per($this->s->fresh(), $this->gestore)->first();
        $this->assertSame('Ricambio ordinato', $prima['testo']);
    }

    public function test_affidata_ad_altro(): void
    {
        $collega = User::factory()->create(['name' => 'Bruno Collega']);
        $s = $this->segnalazioneConStorico([[2, $collega->id, '2026-11-13 08:16:08']]);

        $this->assertSame(["l'ha affidata a Bruno Collega"], $this->passaggiDalPiuVecchio($s)->pluck('frase')->all());
    }

    public function test_stesso_passaggio_ripetuto_compare_una_volta(): void
    {
        $s = $this->segnalazioneConStorico([
            [2, 0, '2026-11-13 08:16:00'],
            [6, 0, '2026-11-13 14:00:00'],
            [2, 0, '2026-11-13 14:01:10'],
            [2, -1, '2026-11-13 14:01:30'],
        ]);

        $this->assertSame(["l'ha presa in carico", 'ha messo in pausa', 'ha ripreso il lavoro'], $this->passaggiDalPiuVecchio($s)->pluck('frase')->all());
    }

    public function test_risposta_a_scuola_la_chiama_scuola(): void
    {
        $this->segnalatore->forceFill(['auth_source' => 'spid'])->save();
        NotaSegnalazione::create([
            'id_segnalazione' => $this->s->id_segnalazione, 'testo' => 'Arriviamo',
            'id_utente' => $this->gestore->id, 'visibile_web' => true, 'visibile_impresa' => false,
        ]);

        $voce = Attivita::per($this->s->fresh(), $this->gestore)->firstWhere('testo', 'Arriviamo');

        $this->assertSame('ha risposto alla scuola', $voce['frase']);
        $this->assertSame('La scuola la legge', $voce['letta_da']);
    }

    public function test_riassegnata(): void
    {
        $altro = User::factory()->create(['name' => 'Luca Verdi']);
        $s = $this->segnalazioneConStorico([
            [3, $this->operaio->id, '2026-11-13 09:00:00'],
            [3, $altro->id, '2026-11-13 11:00:00'],
        ]);

        $this->assertSame(["l'ha assegnata a Paolo Neri", "l'ha riassegnata a Luca Verdi"], $this->passaggiDalPiuVecchio($s)->pluck('frase')->all());
    }

    public function test_scadenza_spostata_frase_e_prefisso_tolto(): void
    {
        $s = $this->segnalazioneConStorico([]);
        NotaSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'testo' => 'Scadenza spostata al 16/11/2026: manca il pezzo',
            'id_utente' => $this->gestore->id, 'visibile_web' => true, 'visibile_impresa' => false, 'origine' => NotaSegnalazione::ORIGINE_SCADENZA,
        ]);

        $voce = Attivita::per($s->fresh(), $this->gestore)->firstWhere('tipo', 'nota');

        $this->assertSame('ha spostato la scadenza a lunedì 16 novembre', $voce['frase']);
        $this->assertSame('manca il pezzo', $voce['nota']);
        $this->assertNull($voce['testo']);
        $this->assertSame('passaggio', $voce['forma']);
    }

    public function test_passaggio_e_nota_stesso_minuto_fusi(): void
    {
        $s = $this->segnalazioneConStorico([[6, 0, '2026-11-13 10:00:00']]);
        NotaSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'testo' => 'Aspettiamo il pezzo',
            'id_utente' => $this->gestore->id, 'visibile_web' => false, 'visibile_impresa' => false,
        ])->forceFill(['data' => '2026-11-13 10:00:30'])->save();

        $voci = Attivita::per($s->fresh(), $this->gestore);

        $this->assertCount(1, $voci);
        $this->assertSame('ha messo in pausa', $voci[0]['frase']);
        $this->assertSame('Aspettiamo il pezzo', $voci[0]['nota']);
        $this->assertSame('Solo il comune', $voci[0]['letta_da']);
    }

    public function test_letta_da_nei_quattro_casi(): void
    {
        foreach ([[true, true, 'ditta e web'], [true, false, 'solo web'], [false, true, 'solo ditta'], [false, false, 'interna']] as [$web, $impresa, $testo]) {
            NotaSegnalazione::create([
                'id_segnalazione' => $this->s->id_segnalazione, 'testo' => $testo,
                'id_utente' => $this->gestore->id, 'visibile_web' => $web, 'visibile_impresa' => $impresa,
            ]);
        }

        $voci = Attivita::per($this->s->fresh(), $this->gestore)->keyBy('testo');

        $this->assertSame('La leggono ditta e chi ha segnalato', $voci['ditta e web']['letta_da']);
        $this->assertSame('La legge chi ha segnalato', $voci['solo web']['letta_da']);
        $this->assertSame('La legge la ditta', $voci['solo ditta']['letta_da']);
        $this->assertSame('Solo il comune', $voci['interna']['letta_da']);
        $this->assertSame('ha risposto a chi ha segnalato', $voci['solo web']['frase']);
        $this->assertSame('ha scritto una nota', $voci['interna']['frase']);
        $this->assertSame('messaggio', $voci['interna']['forma']);
        $this->assertNull(Attivita::per($this->s->fresh(), $this->ditta)->first()['letta_da']);
    }

    public function test_nota_ditta_forzata_visibile_impresa(): void
    {
        $this->actingAs($this->ditta)->post(route('segnalazioni.nota', $this->s), [
            'testo' => 'Passiamo domani', 'visibile_web' => '1',
        ])->assertRedirect();

        $nota = NotaSegnalazione::where('testo', 'Passiamo domani')->firstOrFail();
        $this->assertTrue($nota->visibile_impresa);
        $this->assertFalse($nota->visibile_web);
    }

    public function test_nota_segnalante_forzata_visibile_web_e_avvisa_supervisori(): void
    {
        Notification::fake();

        $this->actingAs($this->segnalatore)->post(route('segnalazioni.nota', $this->s), [
            'testo' => 'Ora non si chiude affatto', 'visibile_impresa' => '1',
        ])->assertRedirect();

        $nota = NotaSegnalazione::where('testo', 'Ora non si chiude affatto')->firstOrFail();
        $this->assertTrue($nota->visibile_web);
        $this->assertFalse($nota->visibile_impresa);
        Notification::assertSentTo($this->gestore, NotaDaEsternoNotification::class);
    }

    public function test_nota_segnalante_avvisa_gestore_assegnatario_se_presente(): void
    {
        Notification::fake();
        $assegnatario = User::factory()->create(['supervisore_segnalazioni' => false]);
        $assegnatario->assignRole('gestore');
        $this->s->update(['id_operatore_assegnato' => $assegnatario->id]);

        $this->actingAs($this->segnalatore)->post(route('segnalazioni.nota', $this->s), ['testo' => 'Sollecito']);

        Notification::assertSentTo($assegnatario, NotaDaEsternoNotification::class);
        Notification::assertNotSentTo($this->gestore, NotaDaEsternoNotification::class);
    }

    public function test_nota_con_foto_salva_allegato(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->actingAs($this->segnalatore)->post(route('segnalazioni.nota', $this->s), [
            'testo' => 'Ecco la foto',
            'allegati' => [UploadedFile::fake()->image('finestra.jpg')],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, AllegatoSegnalazione::where('id_segnalazione', $this->s->id_segnalazione)->count());
    }
}
