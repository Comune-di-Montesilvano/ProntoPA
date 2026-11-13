<?php

namespace Tests\Feature;

use App\Models\Azione;
use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Models\NotaSegnalazione;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use App\Notifications\AccorpataNotification;
use App\Notifications\ArchiviataInattivitaNotification;
use App\Notifications\DigestGestoreNotification;
use App\Notifications\FineLavoriNotification;
use App\Notifications\ImpresaAssegnataNotification;
use App\Notifications\InattivitaNotification;
use App\Notifications\NotaDaEsternoNotification;
use App\Notifications\NuovaNotaSegnalazione;
use App\Notifications\NuovaSegnalazioneNotification;
use App\Notifications\OperatoreAssegnatoNotification;
use App\Notifications\RiepilogoInattivitaNotification;
use App\Notifications\SegnalazioneChiusaNotification;
use App\Notifications\SegnalazioneStatoCambiato;
use App\Notifications\SlaViolazioneNotification;
use App\Notifications\SlaWarningNotification;
use App\Notifications\SollecitoNotification;
use App\Notifications\SquadraAssegnataNotification;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Ogni email su una segnalazione dice cosa e dove: id, tipologia, titolo nell'oggetto (spec §8). */
class EmailDescrizioneSegnalazioneTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['name' => 'Anna Bianchi', 'supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    private function segnalazione(array $attr = []): Segnalazione
    {
        $ente = Istituto::create(['descrizione' => 'IC Rodari', 'id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);
        $plesso = Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Primaria Lazio', 'indirizzo' => 'Via Lazio 1', 'referente' => 'Maria Verdi', 'recapiti' => '085 123']);

        return Segnalazione::factory()->create(array_merge([
            'id_stato_segnalazione' => 3,
            'id_tipologia_segnalazione' => 6, // IMPIANTO TERMICO
            'id_plesso' => $plesso->id_plesso,
            'titolo' => 'Termosifoni freddi aula 3B',
            'testo_segnalazione' => 'Da lunedì i termosifoni della 3B sono freddi.',
            'data_scadenza_sla' => now()->addDays(3),
        ], $attr));
    }

    /** @return array<string, array{0: \Closure(Segnalazione, User): Notification}> */
    public static function notifiche(): array
    {
        $nota = fn (Segnalazione $s, User $u) => NotaSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'testo' => 'Fatto', 'id_utente' => $u->id]);

        return [
            'nuova' => [fn ($s) => new NuovaSegnalazioneNotification($s)],
            'riclassificata' => [fn ($s) => new NuovaSegnalazioneNotification($s, true)],
            'operatore' => [fn ($s, $u) => new OperatoreAssegnatoNotification($s, Azione::find(2), $u)],
            'squadra' => [fn ($s, $u) => new SquadraAssegnataNotification($s->id_segnalazione, Squadra::create(['nome' => 'Idraulici', 'id_caposquadra' => $u->id])->id_squadra)],
            'impresa' => [fn ($s, $u) => new ImpresaAssegnataNotification($s, Azione::find(3), $u)],
            'sollecito' => [fn ($s) => new SollecitoNotification($s, 'Novità?')],
            'chiusa' => [fn ($s, $u) => new SegnalazioneChiusaNotification($s, Azione::find(9), $u)],
            'stato' => [fn ($s, $u) => new SegnalazioneStatoCambiato($s, Azione::find(4), $u)],
            'nota' => [fn ($s, $u) => new NuovaNotaSegnalazione($s, $nota($s, $u))],
            'esterno' => [fn ($s, $u) => new NotaDaEsternoNotification($s, $nota($s, $u), 'La scuola')],
            'fine-lavori' => [fn ($s, $u) => new FineLavoriNotification($s, $nota($s, $u), 'Edil')],
            'sla-warning' => [fn ($s) => new SlaWarningNotification($s)],
            'sla-violata' => [fn ($s) => new SlaViolazioneNotification($s)],
            'inattivita' => [fn ($s) => new InattivitaNotification($s, null, Carbon::now()->subDays(30))],
            'archiviata' => [fn ($s) => new ArchiviataInattivitaNotification($s)],
            'accorpata' => [fn ($s) => new AccorpataNotification($s, Segnalazione::factory()->create(), 'lavoro')],
        ];
    }

    #[DataProvider('notifiche')]
    public function test_oggetto_contiene_id_tipologia_titolo(\Closure $crea): void
    {
        $s = $this->segnalazione();

        $oggetto = $crea($s, $this->gestore)->toMail($this->gestore)->subject;

        $this->assertStringContainsString('#'.$s->id_segnalazione, $oggetto);
        $this->assertStringContainsString('IMPIANTO TERMICO', $oggetto);
        $this->assertStringContainsString('Termosifoni freddi aula 3B', $oggetto);
    }

    public function test_assegnazione_operaio_ha_testo_sede_referente_nota(): void
    {
        $s = $this->segnalazione();

        $righe = implode("\n", (new OperatoreAssegnatoNotification($s, Azione::find(2), $this->gestore, 'Chiavi in portineria'))
            ->toMail($this->gestore)->introLines);

        $this->assertStringContainsString('Da lunedì i termosifoni della 3B sono freddi.', $righe);
        $this->assertStringContainsString('Primaria Lazio', $righe);
        $this->assertStringContainsString('Maria Verdi', $righe);
        $this->assertStringContainsString('Chiavi in portineria', $righe);
    }

    public function test_segnalazione_senza_titolo_e_senza_plesso(): void
    {
        $s = $this->segnalazione(['titolo' => null, 'id_plesso' => 0]);

        $mail = (new OperatoreAssegnatoNotification($s, Azione::find(2), $this->gestore))->toMail($this->gestore);

        $this->assertStringContainsString('Da lunedì i termosifoni', $mail->subject);
        $this->assertStringNotContainsString('·  ·', $mail->subject);
    }

    public function test_digest_non_fa_una_query_per_riga(): void
    {
        $conta = function (int $n): int {
            $ids = Segnalazione::factory()->count($n)->create(['id_tipologia_segnalazione' => 6])->pluck('id_segnalazione')->all();
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            (new DigestGestoreNotification([], [], $ids))->toMail($this->gestore);
            $q = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $q;
        };

        $this->assertSame($conta(1), $conta(5));
    }

    public function test_riepilogo_digest_righe_con_tipologia(): void
    {
        $s = $this->segnalazione();

        $digest = implode("\n", (new DigestGestoreNotification([$s->id_segnalazione], [], []))->toMail($this->gestore)->introLines);
        $riepilogo = implode("\n", (new RiepilogoInattivitaNotification([$s], []))->toMail($this->gestore)->introLines);

        foreach ([$digest, $riepilogo] as $testo) {
            $this->assertStringContainsString('IMPIANTO TERMICO', $testo);
            $this->assertStringContainsString('Termosifoni freddi aula 3B', $testo);
        }
    }
}
