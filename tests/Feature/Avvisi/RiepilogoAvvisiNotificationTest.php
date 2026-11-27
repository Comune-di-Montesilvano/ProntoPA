<?php

namespace Tests\Feature\Avvisi;

use App\Channels\TelegramChannel;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\RiepilogoAvvisiNotification;
use App\Services\Avvisi\Composizione;
use App\Services\Avvisi\Voce;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RiepilogoAvvisiNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
    }

    private function segnalazione(array $attr = []): Segnalazione
    {
        Segnalazione::$silenziaAvvisi = true;
        try {
            return Segnalazione::factory()->create($attr);
        } finally {
            Segnalazione::$silenziaAvvisi = false;
        }
    }

    private function voce(string $categoria, array $attr = [], array $motivi = [], array $tipi = []): Voce
    {
        $s = $this->segnalazione($attr);

        return new Voce($s, $categoria, $motivi, '#'.$s->id_segnalazione.' · riga', null, $tipi);
    }

    /** @param  array<string, list<Voce>>  $sezioni */
    private function composizione(array $sezioni = [], array $quadro = []): Composizione
    {
        return new Composizione($quadro + ['nuove' => [], 'da_fare' => [], 'in_ritardo' => []], $sezioni, [], null);
    }

    private function mailTesto(Notification $n, object $chi): string
    {
        $m = $n->toMail($chi);

        return $m->subject."\n".implode("\n", array_map(
            fn ($l) => is_string($l) ? $l : '',
            [...$m->introLines, ...$m->outroLines],
        ))."\n".($m->actionUrl ?? '');
    }

    public function test_oggetto_e_sezioni_in_ordine(): void
    {
        $a = $this->voce('assegnazioni', motivi: ['Assegnata a te']);
        $b = $this->voce('scadenze', motivi: ['Scaduta', 'Nuova nota']);
        $n = new RiepilogoAvvisiNotification($this->composizione(['assegnazioni' => [$a], 'scadenze' => [$b]]), false);

        $utente = User::factory()->create();
        $mail = $n->toMail($utente);
        $testo = $this->mailTesto($n, $utente);

        $this->assertSame('Riepilogo ProntoPA — 2 segnalazioni', $mail->subject);
        $this->assertLessThan(strpos($testo, 'Scadenze (1)'), strpos($testo, 'Assegnate a te (1)'));
        $this->assertStringContainsString('— Scaduta · Nuova nota', $testo);
        $this->assertStringContainsString(route('segnalazioni.show', $b->segnalazione->id_segnalazione), $testo);
        $this->assertStringNotContainsString('Il quadro di oggi', $testo);
        $this->assertSame([$a->segnalazione->id_segnalazione, $b->segnalazione->id_segnalazione], $n->segnalazioniTracciate());
    }

    public function test_ditta_link_accesso(): void
    {
        $a = $this->voce('assegnazioni', motivi: ['Assegnata a te']);
        $n = new RiepilogoAvvisiNotification($this->composizione(['assegnazioni' => [$a]]), false);

        $testo = $this->mailTesto($n, new AnonymousNotifiable);

        $this->assertStringContainsString(route('accesso'), $testo);
        $this->assertStringNotContainsString('/segnalazioni/'.$a->segnalazione->id_segnalazione, $testo);
    }

    public function test_quadro_mattino_in_testa(): void
    {
        $nuova = $this->voce('nuove');
        $dafare = $this->voce('da_fare', ['data_scadenza_sla' => now()->addHours(10)]);
        $lontana = $this->voce('da_fare', ['data_scadenza_sla' => now()->addDays(10)]);
        $ritardo = $this->voce('in_ritardo');
        $sezione = $this->voce('scadenze', motivi: ['A rischio']);
        $n = new RiepilogoAvvisiNotification($this->composizione(
            ['scadenze' => [$sezione]],
            ['nuove' => [$nuova], 'da_fare' => [$dafare, $lontana], 'in_ritardo' => [$ritardo]],
        ), true);

        $testo = $this->mailTesto($n, User::factory()->create());

        $this->assertStringContainsString('Il quadro di oggi', $testo);
        $p = [strpos($testo, 'Nuove (1)'), strpos($testo, 'Da fare (2)'), strpos($testo, 'In ritardo (1)'), strpos($testo, 'Scadenze (1)')];
        $this->assertNotContains(false, $p);
        $ordinato = $p;
        sort($ordinato);
        $this->assertSame($ordinato, $p);
        $this->assertSame(1, substr_count($testo, '· a rischio'));
        $this->assertStringContainsString('Riepilogo ProntoPA — 5 segnalazioni', $testo);
    }

    public function test_telegram_massimo_10_pulsanti_e_altre_n(): void
    {
        $voci = [];
        for ($i = 0; $i < 13; $i++) {
            $voci[] = $this->voce('assegnazioni', motivi: ['Assegnata a te']);
        }
        $n = new RiepilogoAvvisiNotification($this->composizione(['assegnazioni' => $voci]), false);

        $m = $n->toTelegram(User::factory()->create());

        $this->assertCount(10, $m['inline_keyboard']);
        $this->assertSame('apri:'.$voci[0]->segnalazione->id_segnalazione, $m['inline_keyboard'][0][0]['callback_data']);
        $this->assertStringContainsString('e altre 3', $m['text']);
        $this->assertLessThanOrEqual(4000, mb_strlen($m['text']));
    }

    public function test_telegram_testo_troncato(): void
    {
        $s = $this->segnalazione();
        $voce = new Voce($s, 'assegnazioni', [], str_repeat('x', 5000), null);
        $n = new RiepilogoAvvisiNotification($this->composizione(['assegnazioni' => [$voce]]), false);

        $testo = $n->toTelegram(User::factory()->create())['text'];

        $this->assertSame(4000, mb_strlen($testo));
        $this->assertStringEndsWith('…', $testo);
    }

    public function test_tipi_tracciati_solo_per_voci_con_avvisi(): void
    {
        $con = $this->voce('assegnazioni', tipi: ['OperatoreAssegnatoNotification']);
        $senza = $this->voce('nuove');
        $n = new RiepilogoAvvisiNotification($this->composizione(['assegnazioni' => [$con]], ['nuove' => [$senza]]), true);

        $this->assertSame([$con->segnalazione->id_segnalazione => ['OperatoreAssegnatoNotification']], $n->tipiTracciati());
    }

    public function test_canale_telegram_solo_se_abilitato(): void
    {
        $u = User::factory()->create(['telegram_chat_id' => '123']);
        $u->assignRole('gestore');
        $n = new RiepilogoAvvisiNotification($this->composizione(), false);

        $this->assertSame(['mail', TelegramChannel::class], $n->via($u));
        Impostazione::set('telegram_riepiloghi', false);
        $this->assertSame(['mail'], $n->via($u));
        $this->assertSame(['mail'], $n->via(new AnonymousNotifiable));
    }

    private function inviaTelegram(Notification $n): void
    {
        Impostazione::set('telegram_bot_token', '123:abc');
        $u = User::factory()->create(['telegram_chat_id' => '555']);
        $u->assignRole('gestore');
        app(TelegramChannel::class)->send($u, $n);
    }

    public function test_telegram_fallito_lancia_per_riepilogo(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        $n = new RiepilogoAvvisiNotification($this->composizione(['assegnazioni' => [$this->voce('assegnazioni')]]), false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Telegram: invio non riuscito');
        $this->inviaTelegram($n);
    }

    public function test_telegram_fallito_non_lancia_per_altre(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        $altra = new class extends Notification
        {
            public function toTelegram(object $n): array
            {
                return ['text' => 'ciao'];
            }
        };

        $this->inviaTelegram($altra);

        $this->addToAssertionCount(1);
    }
}
