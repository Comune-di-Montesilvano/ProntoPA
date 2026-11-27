<?php

namespace Tests\Feature\Avvisi;

use App\Channels\TelegramChannel;
use App\Jobs\InviaRiepilogo;
use App\Models\AvvisoInAttesa;
use App\Models\Impresa;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\OperatoreAssegnatoNotification;
use App\Notifications\RiepilogoAvvisiNotification;
use App\Services\Admin\Riquadro;
use App\Services\Admin\StatoSistema;
use App\Services\Directory\Directory;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** Il giro di invio: quando parte, cosa manda, cosa succede se l'invio fallisce. */
class InviaAvvisiTest extends TestCase
{
    use RefreshDatabase;

    private User $attore;

    private User $operaio;

    /** @var list<NotificationSent> */
    private array $inviate = [];

    private bool $telegramOk = true;

    /** Lunedì 30 novembre 2026, giorno lavorativo. */
    private const LUNEDI = '2026-11-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Cache::flush();
        Impostazione::set('orario_lun', '08:00-14:00');
        Impostazione::set('digest_enabled', false);
        Impostazione::set('mail_from_address', 'urp@comune.montesilvano.pe.it');
        $this->attore = User::factory()->create(['auth_source' => 'ldap']);
        $this->attore->syncRoles(['admin']);
        $this->operaio = User::factory()->create(['auth_source' => 'ldap']);
        $this->operaio->syncRoles(['operaio']);
        Mail::fake();
        Http::fake(fn ($richiesta) => str_contains($richiesta->url(), 'api.telegram.org')
            ? Http::response(['ok' => $this->telegramOk])
            : Http::response());
        // Mail::fake() non registra le email delle notifiche (non sono Mailable): si contano gli invii veri.
        Event::listen(NotificationSent::class, function (NotificationSent $e) {
            $this->inviate[] = $e;
        });
    }

    private function alle(string $quando): void
    {
        $this->travelTo(Carbon::parse($quando, config('app.timezone')));
    }

    private function segnalazione(int $stato = 2): Segnalazione
    {
        $s = new Segnalazione([
            'id_tipologia_segnalazione' => 1, 'id_utente_segnalazione' => $this->attore->id,
            'testo_segnalazione' => 'Guasto', 'flag_riservata' => false, 'flag_pubblicata' => false,
            'id_stato_segnalazione' => $stato,
        ]);
        Segnalazione::$silenziaAvvisi = true;
        try {
            $s->save();
        } finally {
            Segnalazione::$silenziaAvvisi = false;
        }

        return $s;
    }

    /** Assegnazioni vere: le notifiche finiscono in attesa tramite l'intercettazione. */
    private function assegna(int $quante, ?User $operaio = null): void
    {
        for ($i = 0; $i < $quante; $i++) {
            // azione 2 = assegna_operatore
            app(SegnalazioneWorkflowService::class)->eseguiAzione($this->segnalazione(), 2, $this->attore, ['id_operatore' => ($operaio ?? $this->operaio)->id]);
        }
    }

    /** @return list<NotificationSent> */
    private function partite(?string $tipo = null, ?object $a = null): array
    {
        return array_values(array_filter($this->inviate, fn (NotificationSent $e) => ($tipo === null || $e->notification instanceof $tipo)
            && ($a === null || ($e->notifiable instanceof User && $a instanceof User && $e->notifiable->id === $a->id))));
    }

    private function inAttesa(): int
    {
        return AvvisoInAttesa::query()->inAttesa()->count();
    }

    public function test_lunedi_10_00_parte_riepilogo_orario(): void
    {
        $this->alle(self::LUNEDI.' 09:20');
        $this->assegna(3);
        $this->assertSame(3, $this->inAttesa());

        $this->alle(self::LUNEDI.' 10:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $partite = $this->partite(RiepilogoAvvisiNotification::class, $this->operaio);
        $this->assertCount(1, $partite);
        $this->assertSame('mail', $partite[0]->channel);
        $this->assertSame(3, $partite[0]->notification->composizione->totaleSegnalazioni());
        $this->assertCount(1, $this->partite(), 'nessun altro invio');
        $this->assertSame(0, $this->inAttesa());
    }

    public function test_lunedi_15_00_non_parte(): void
    {
        $this->alle(self::LUNEDI.' 13:20');
        $this->assegna(2);

        $this->alle(self::LUNEDI.' 15:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $this->assertSame([], $this->partite());
        $this->assertSame(2, $this->inAttesa());
    }

    public function test_domenica_non_parte(): void
    {
        $this->alle('2026-11-29 10:00');
        $this->assegna(2);
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->artisan('avvisi:invia --ritmo=giorno')->assertExitCode(0);

        $this->assertSame([], $this->partite());
        $this->assertSame(2, $this->inAttesa());
    }

    public function test_fuori_ora_piena_non_parte_ma_forza_si(): void
    {
        $this->alle(self::LUNEDI.' 10:15');
        $this->assegna(2);
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertSame([], $this->partite());

        $this->artisan('avvisi:invia --ritmo=ora --forza')->assertExitCode(0);
        $this->assertCount(1, $this->partite(RiepilogoAvvisiNotification::class));
    }

    public function test_lunedi_14_00_chiusura_parte(): void
    {
        $this->alle(self::LUNEDI.' 13:50');
        $this->assegna(2);

        $this->alle(self::LUNEDI.' 14:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertCount(1, $this->partite(RiepilogoAvvisiNotification::class));

        // Dopo la chiusura, al quarto d'ora successivo, niente.
        $this->assegna(2);
        $this->alle(self::LUNEDI.' 14:15');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertCount(1, $this->partite(RiepilogoAvvisiNotification::class));
    }

    public function test_fascia_13_45_parte_alle_13_45(): void
    {
        Impostazione::set('orario_lun', '08:00-13:45');
        $this->alle(self::LUNEDI.' 13:10');
        $this->assegna(2);

        $this->alle(self::LUNEDI.' 13:30');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertSame([], $this->partite());

        $this->alle(self::LUNEDI.' 13:45');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertCount(1, $this->partite(RiepilogoAvvisiNotification::class));
    }

    public function test_giro_giorno_include_righe_orarie_e_quadro(): void
    {
        Impostazione::set('digest_enabled', true);
        $gestore = User::factory()->create(['auth_source' => 'ldap', 'gestore_tutte_tipologie' => true]);
        $gestore->syncRoles(['gestore']);

        $this->alle('2026-11-27 16:00'); // venerdì, dopo la chiusura: restano in attesa
        $this->assegna(2);
        $this->segnalazione(1); // nuova, da smistare: quadro del gestore
        $this->assertSame(2, AvvisoInAttesa::query()->inAttesa()->where('ritmo', 'ora')->count());

        $this->alle(self::LUNEDI.' 07:30');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertSame([], $this->partite(), 'il giro orario non parte prima dell\'apertura');

        $this->artisan('avvisi:invia --ritmo=giorno')->assertExitCode(0);

        $operaio = $this->partite(RiepilogoAvvisiNotification::class, $this->operaio);
        $this->assertCount(1, $operaio);
        $this->assertTrue($operaio[0]->notification->mattino);
        $this->assertCount(2, $operaio[0]->notification->composizione->quadro['da_fare']);
        $this->assertSame(['Assegnata a te'], $operaio[0]->notification->composizione->quadro['da_fare'][0]->motivi);

        // Senza avvisi in attesa, il gestore riceve comunque il quadro.
        $gest = $this->partite(RiepilogoAvvisiNotification::class, $gestore);
        $this->assertCount(1, $gest);
        $this->assertCount(1, $gest[0]->notification->composizione->quadro['nuove']);

        $this->assertSame(0, $this->inAttesa());
        // L'admin puro e chi non ha niente non ricevono nulla.
        $this->assertSame([], $this->partite(null, $this->attore));
    }

    public function test_un_solo_avviso_reinvia_email_originale(): void
    {
        $this->alle(self::LUNEDI.' 09:20');
        $this->assegna(1);

        $this->alle(self::LUNEDI.' 10:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $partite = $this->partite(null, $this->operaio);
        $this->assertCount(1, $partite);
        $this->assertInstanceOf(OperatoreAssegnatoNotification::class, $partite[0]->notification);
        $this->assertSame('mail', $partite[0]->channel);
        $this->assertStringContainsString('Assegnata a te', $partite[0]->notification->toMail($this->operaio)->subject);
        $this->assertSame(0, $this->inAttesa());
        $this->assertSame(1, AvvisoInAttesa::count(), 'il reinvio non torna in attesa');
    }

    public function test_un_solo_avviso_su_telegram_riepilogo_a_una_riga(): void
    {
        Impostazione::set('telegram_bot_token', '123:abc');
        $this->operaio->forceFill(['telegram_chat_id' => '555'])->save();

        $this->alle(self::LUNEDI.' 09:20');
        $this->assegna(1);
        $this->alle(self::LUNEDI.' 10:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $canali = array_map(fn (NotificationSent $e) => class_basename($e->notification).':'.$e->channel, $this->partite(null, $this->operaio));
        $this->assertSame(['OperatoreAssegnatoNotification:mail', 'RiepilogoAvvisiNotification:'.TelegramChannel::class], $canali);
    }

    public function test_doppio_giro_nessun_doppio_invio(): void
    {
        Impostazione::set('digest_enabled', true);
        $gestore = User::factory()->create(['auth_source' => 'ldap', 'gestore_tutte_tipologie' => true]);
        $gestore->syncRoles(['gestore']);
        $this->alle(self::LUNEDI.' 09:20');
        $this->assegna(2);

        Queue::fake();
        $this->alle(self::LUNEDI.' 10:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        Queue::assertPushed(InviaRiepilogo::class, 1);
        Queue::assertPushed(InviaRiepilogo::class, fn (InviaRiepilogo $j) => $j->chiave === 'u:'.$this->operaio->id && ! $j->mattino);

        // Giro del mattino rilanciato: il quadro del gestore accodato una volta sola.
        $this->artisan('avvisi:invia --ritmo=giorno --forza')->assertExitCode(0);
        $this->artisan('avvisi:invia --ritmo=giorno --forza')->assertExitCode(0);
        Queue::assertPushed(InviaRiepilogo::class, fn (InviaRiepilogo $j) => $j->chiave === 'u:'.$gestore->id);
        $this->assertCount(1, Queue::pushed(InviaRiepilogo::class, fn (InviaRiepilogo $j) => $j->chiave === 'u:'.$gestore->id));
    }

    public function test_utente_disattivato_niente_invio_righe_marcate(): void
    {
        $this->alle(self::LUNEDI.' 09:20');
        $this->assegna(2);
        $this->operaio->forceFill(['attivo' => false])->save();

        $this->alle(self::LUNEDI.' 10:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $this->assertSame([], $this->partite());
        $this->assertSame(0, $this->inAttesa());
        $this->assertSame(2, AvvisoInAttesa::whereNotNull('inviata_at')->count());
    }

    public function test_tre_fallimenti_scartata_e_stato_sistema_attenzione(): void
    {
        $this->alle(self::LUNEDI.' 09:20');
        $this->assegna(1);
        $this->mock(MailChannel::class, fn ($m) => $m->shouldReceive('send')->andThrow(new \RuntimeException('SMTP giù')));
        Log::spy();

        foreach (['10:00', '11:00'] as $i => $ora) {
            $this->alle(self::LUNEDI.' '.$ora);
            $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
            $riga = AvvisoInAttesa::first();
            $this->assertSame($i + 1, (int) $riga->tentativi);
            $this->assertNull($riga->giro, 'righe liberate per il giro dopo');
            $this->assertNull($riga->scartata_at);
        }
        $this->assertSame(Riquadro::OK, $this->riquadroEmail()->stato);

        $this->alle(self::LUNEDI.' 12:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $riga = AvvisoInAttesa::first();
        $this->assertSame(3, (int) $riga->tentativi);
        $this->assertNotNull($riga->scartata_at);
        Log::shouldHaveReceived('warning')->with('Riepilogo avvisi scartato', Mockery::type('array'))->once();
        $this->assertSame([], $this->partite());

        $r = $this->riquadroEmail();
        $this->assertSame(Riquadro::ATTENZIONE, $r->stato);
        $this->assertSame('1 avviso non consegnato negli ultimi 7 giorni.', $r->messaggio);

        // Al giro dopo la riga scartata non riparte.
        $this->alle(self::LUNEDI.' 13:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertSame(3, (int) AvvisoInAttesa::first()->tentativi);
    }

    public function test_pulizia_30_giorni(): void
    {
        $this->alle(self::LUNEDI.' 10:00');
        $riga = fn (array $attr) => DB::table('avvisi_in_attesa')->insertGetId($attr + [
            'id_notifica' => (string) \Illuminate\Support\Str::uuid(), 'destinatario_tipo' => 'utente',
            'id_utente' => $this->operaio->id, 'email' => '', 'categoria' => 'assegnazioni', 'ritmo' => 'giorno',
            'id_segnalazione' => 1, 'tipo' => 'OperatoreAssegnatoNotification', 'notifica' => 'x', 'tentativi' => 0,
            'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40),
        ]);
        $vecchiaInviata = $riga(['inviata_at' => now()->subDays(31)]);
        $vecchiaScartata = $riga(['scartata_at' => now()->subDays(31), 'tentativi' => 3]);
        $recente = $riga(['inviata_at' => now()->subDays(10)]);
        $bloccata = $riga(['giro' => 'vecchio', 'updated_at' => now()->subHours(4)]);
        $inCorso = $riga(['giro' => 'adesso', 'updated_at' => now()->subHour()]);

        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $this->assertNull(AvvisoInAttesa::find($vecchiaInviata));
        $this->assertNull(AvvisoInAttesa::find($vecchiaScartata));
        $this->assertNotNull(AvvisoInAttesa::find($recente));
        $this->assertNull(AvvisoInAttesa::find($bloccata)->giro, 'prenotazione di oltre 3 ore liberata');
        $this->assertSame('adesso', AvvisoInAttesa::find($inCorso)->giro);
    }

    public function test_scheduler_in_ritardo_non_perde_il_giro(): void
    {
        $this->alle(self::LUNEDI.' 09:20');
        $this->assegna(2);
        $this->alle(self::LUNEDI.' 10:01:30');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertCount(1, $this->partite(RiepilogoAvvisiNotification::class));

        $this->assegna(2);
        $this->alle(self::LUNEDI.' 10:16');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertCount(1, $this->partite(RiepilogoAvvisiNotification::class), '10:16 = quarto d\'ora 10:15: niente');

        foreach (['14:00:59', '14:01:30'] as $i => $ora) {
            $this->assegna(2);
            $this->alle(self::LUNEDI.' '.$ora);
            $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
            $this->assertCount(2 + $i, $this->partite(RiepilogoAvvisiNotification::class), "chiusura alle $ora");
        }
    }

    public function test_chiusura_non_all_ora_piena_con_scheduler_in_ritardo(): void
    {
        Impostazione::set('orario_lun', '08:00-13:45');
        $this->alle(self::LUNEDI.' 13:10');
        $this->assegna(2);
        $this->alle(self::LUNEDI.' 13:46:30');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertCount(1, $this->partite(RiepilogoAvvisiNotification::class));
    }

    public function test_festivo_nessun_giro_orario(): void
    {
        Impostazione::set('orario_ven', '08:00-14:00');
        $this->alle('2026-12-25 09:20'); // Natale, venerdì
        $this->assegna(2);
        $this->alle('2026-12-25 10:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $this->assertSame([], $this->partite());
        $this->assertSame(2, $this->inAttesa());
    }

    public function test_mail_consegnata_telegram_fallito_righe_inviate(): void
    {
        Impostazione::set('telegram_bot_token', '123:abc');
        $this->operaio->forceFill(['telegram_chat_id' => '555'])->save();
        $this->alle(self::LUNEDI.' 09:20');
        $this->assegna(2);
        $this->telegramOk = false;
        Log::spy();

        $this->alle(self::LUNEDI.' 10:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $partite = $this->partite(null, $this->operaio);
        $this->assertCount(1, $partite);
        $this->assertSame('mail', $partite[0]->channel);
        $this->assertSame(0, $this->inAttesa());
        $this->assertSame(0, (int) AvvisoInAttesa::max('tentativi'));
        Log::shouldHaveReceived('warning')->with('Riepilogo avvisi: canale non consegnato', Mockery::type('array'))->once();

        // Il giro dopo non rispedisce la mail.
        $this->alle(self::LUNEDI.' 11:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);
        $this->assertCount(1, $this->partite(null, $this->operaio));
    }

    public function test_solo_telegram_fallito_conta_il_tentativo(): void
    {
        Impostazione::set('telegram_bot_token', '123:abc');
        $this->operaio->forceFill(['telegram_chat_id' => '555', 'email' => ''])->save();
        $this->alle(self::LUNEDI.' 09:20');
        $this->assegna(2);
        $this->telegramOk = false;

        $this->alle(self::LUNEDI.' 10:00');
        $this->artisan('avvisi:invia --ritmo=ora')->assertExitCode(0);

        $this->assertSame([], $this->partite());
        $this->assertSame(2, $this->inAttesa());
        $this->assertSame(1, (int) AvvisoInAttesa::min('tentativi'));
        $this->assertSame(0, AvvisoInAttesa::whereNotNull('giro')->count());
    }

    public function test_quadro_email_aziendale_saltata_se_coincide_con_una_persona_della_ditta(): void
    {
        Impostazione::set('digest_enabled', true);
        $stessa = Impresa::create(['ragione_sociale' => 'Edil Adriatica', 'email' => ' Ufficio@EdilAdriatica.it', 'attiva' => true]);
        $persona = User::factory()->create(['auth_source' => 'spid', 'email' => 'ufficio@ediladriatica.it', 'id_impresa' => $stessa->id_impresa]);
        $persona->syncRoles(['impresa']);
        Impresa::create(['ragione_sociale' => 'Termo Sud', 'email' => 'Info@TermoSud.it', 'attiva' => true]);

        Queue::fake();
        $this->alle(self::LUNEDI.' 07:30');
        $this->artisan('avvisi:invia --ritmo=giorno')->assertExitCode(0);

        $chiavi = Queue::pushed(InviaRiepilogo::class)->map(fn (InviaRiepilogo $j) => $j->chiave)->all();
        $this->assertContains('u:'.$persona->id, $chiavi);
        $this->assertContains('e:info@termosud.it', $chiavi);
        $this->assertNotContains('e:ufficio@ediladriatica.it', $chiavi);
    }

    private function riquadroEmail(): Riquadro
    {
        Storage::fake('local');
        $this->app->instance(Directory::class, new FakeDirectory());
        foreach (app(StatoSistema::class)->riquadri() as $r) {
            if ($r->chiave === 'email') {
                return $r;
            }
        }
        $this->fail('Riquadro email assente');
    }
}
