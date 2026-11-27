<?php

namespace Tests\Feature\Avvisi;

use App\Models\Appalto;
use App\Models\AvvisoInAttesa;
use App\Models\Azione;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\BackupFallitoNotification;
use App\Notifications\OperatoreAssegnatoNotification;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Le notifiche raggruppabili non partono: restano in attesa del riepilogo, una riga per destinatario. */
class IntercettaAvvisiTest extends TestCase
{
    use RefreshDatabase;

    private User $attore;

    /** @var list<NotificationSent> */
    private array $inviate = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->attore = User::factory()->create(['auth_source' => 'ldap']);
        $this->attore->syncRoles(['admin']);
        Mail::fake();
        Http::fake();
        // Mail::fake() non registra le email delle notifiche (non sono Mailable): si contano gli invii veri.
        Event::listen(NotificationSent::class, function (NotificationSent $e) {
            $this->inviate[] = $e;
        });
    }

    /** @return list<string> "Tipo:canale" di quanto è partito davvero */
    private function partite(): array
    {
        return array_map(fn (NotificationSent $e) => class_basename($e->notification).':'.$e->channel, $this->inviate);
    }

    private function segnalazione(): Segnalazione
    {
        $s = new Segnalazione([
            'id_tipologia_segnalazione' => 1, 'id_utente_segnalazione' => $this->attore->id,
            'testo_segnalazione' => 'Guasto', 'flag_riservata' => false, 'flag_pubblicata' => false,
            'id_stato_segnalazione' => 2,
        ]);
        Segnalazione::$silenziaAvvisi = true;
        try {
            $s->save();
        } finally {
            Segnalazione::$silenziaAvvisi = false;
        }

        return $s;
    }

    private function assegnaOperaio(User $operaio): Segnalazione
    {
        $s = $this->segnalazione();
        // azione 2 = assegna_operatore
        app(SegnalazioneWorkflowService::class)->eseguiAzione($s, 2, $this->attore, ['id_operatore' => $operaio->id]);

        return $s->fresh();
    }

    public function test_assegnazione_finisce_in_attesa_e_non_parte(): void
    {
        $operaio = User::factory()->create();
        $operaio->syncRoles(['operaio']);

        $s = $this->assegnaOperaio($operaio);

        Mail::assertNothingSent();
        $this->assertSame([], $this->partite());
        $righe = AvvisoInAttesa::where('tipo', 'OperatoreAssegnatoNotification')->get();
        $this->assertCount(1, $righe);
        $riga = $righe->first();
        $this->assertSame('assegnazioni', $riga->categoria);
        $this->assertSame('ora', $riga->ritmo);
        $this->assertSame('utente', $riga->destinatario_tipo);
        $this->assertSame($operaio->id, (int) $riga->id_utente);
        $this->assertSame('', $riga->email);
        $this->assertSame($s->id_segnalazione, (int) $riga->id_segnalazione);
        $this->assertNotSame('', $riga->id_notifica);
        $notifica = $riga->notifica();
        $this->assertInstanceOf(OperatoreAssegnatoNotification::class, $notifica);
        $this->assertSame($s->id_segnalazione, $notifica->segnalazioneRiepilogo());
        $this->assertTrue($notifica->ancoraValida($operaio));
    }

    public function test_ditta_email_aziendale_in_attesa(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica', 'email' => 'Ufficio@EdilAdriatica.it']);
        $delegato = User::factory()->create(['auth_source' => 'spid', 'email' => 'marco@ditta.it', 'id_impresa' => $impresa->id_impresa]);
        $delegato->syncRoles(['impresa']);
        $appalto = Appalto::create(['descrizione' => 'Edile', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);

        $s = $this->segnalazione();
        // azione 3 = assegna_impresa
        app(SegnalazioneWorkflowService::class)->eseguiAzione($s, 3, $this->attore, ['id_appalto' => $appalto->id_appalto]);

        $this->assertSame([], $this->partite());
        $righe = AvvisoInAttesa::where('tipo', 'ImpresaAssegnataNotification')->get();
        $this->assertCount(2, $righe);
        $email = $righe->firstWhere('destinatario_tipo', 'email');
        $this->assertNotNull($email);
        $this->assertSame('ufficio@ediladriatica.it', $email->email);
        $this->assertSame(0, (int) $email->id_utente);
        $this->assertTrue($righe->contains(fn ($r) => $r->destinatario_tipo === 'utente' && (int) $r->id_utente === $delegato->id));
        $this->assertTrue($email->notifica()->ancoraValida($email->notificabile()));
    }

    public function test_due_canali_una_riga(): void
    {
        $operaio = User::factory()->create(['telegram_chat_id' => '12345']);
        $operaio->syncRoles(['operaio']);

        $this->assegnaOperaio($operaio);

        $this->assertSame([], $this->partite());
        Http::assertNothingSent();
        $this->assertSame(1, AvvisoInAttesa::where('tipo', 'OperatoreAssegnatoNotification')->count());
    }

    public function test_reinvio_da_riepilogo_non_intercettato(): void
    {
        $operaio = User::factory()->create();
        $operaio->syncRoles(['operaio']);
        $s = $this->segnalazione();

        $notifica = new OperatoreAssegnatoNotification($s, Azione::where('codice', 'assegna_operatore')->firstOrFail(), $this->attore);
        $notifica->daRiepilogo = true;
        $operaio->notifyNow($notifica, ['mail']);

        $this->assertSame(['OperatoreAssegnatoNotification:mail'], $this->partite());
        $this->assertSame(0, AvvisoInAttesa::count());
    }

    public function test_notifiche_di_sistema_restano_immediate(): void
    {
        $this->attore->notify(new BackupFallitoNotification('notturno.zip', 'disco pieno'));

        $this->assertSame(['BackupFallitoNotification:mail'], $this->partite());
        $this->assertSame(0, AvvisoInAttesa::count());
    }
}
