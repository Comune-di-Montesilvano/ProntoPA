<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Impostazione;
use App\Services\ClamAvService;
use App\Services\Directory\Directory;
use App\Services\Impostazioni\SalvaImpostazioni;
use App\Services\Oidc\OidcClient;
use App\Services\Oidc\OidcConfig;
use App\Services\OllamaService;
use App\Services\TelegramBotService;
use App\Support\Impostazioni\Sezioni;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class IntegrazioniController extends Controller
{
    /** Sezioni di questa pagina: slug in URL => nome in Sezioni. */
    public const SEZIONI = [
        'spid' => 'INTEGRAZIONI_SPID',
        'telegram' => 'INTEGRAZIONI_TELEGRAM',
        'webhook' => 'INTEGRAZIONI_WEBHOOK',
        'ai' => 'INTEGRAZIONI_AI',
        'antivirus' => 'INTEGRAZIONI_ANTIVIRUS',
        'allegati' => 'INTEGRAZIONI_ALLEGATI',
        'miur' => 'INTEGRAZIONI_MIUR',
        'deleghe' => 'INTEGRAZIONI_DELEGHE',
    ];

    /** Sezioni con il tasto "Prova". */
    public const CON_PROVA = ['spid', 'telegram', 'ai', 'antivirus'];

    public function index(OidcConfig $oidc, Directory $directory): View
    {
        $problemiSpid = $oidc->problemi();
        $attivo = [
            'spid' => $problemiSpid === [],
            'telegram' => filled(Impostazione::get('telegram_bot_token')),
            'webhook' => filled(Impostazione::get('webhook_cittadini_url')),
            'ai' => (bool) Impostazione::get('ai_enabled', false),
            'antivirus' => (bool) Impostazione::get('antivirus_enabled', false),
        ];
        $motivi = [
            'spid' => implode(' ', $problemiSpid),
            'telegram' => $attivo['telegram'] ? '' : 'Manca il token del bot.',
            'webhook' => $attivo['webhook'] ? '' : 'Manca l\'indirizzo a cui inviare gli aggiornamenti.',
            'ai' => $attivo['ai'] ? '' : 'Spenta: titolo, suggerimenti e ricerca dei duplicati simili non sono disponibili.',
            'antivirus' => $attivo['antivirus'] ? '' : 'Spento: gli allegati non vengono controllati.',
        ];

        $esiti = [];
        foreach (self::CON_PROVA as $slug) {
            $esiti[$slug] = Cache::get("integrazioni:prova:$slug");
        }

        return view('admin.integrazioni', [
            'slugs' => array_keys(self::SEZIONI),
            'attivo' => $attivo,
            'motivi' => $motivi,
            'esiti' => $esiti,
            'conProva' => self::CON_PROVA,
            'redirectUri' => $oidc->redirectUri(),
            'urlTelegram' => app(TelegramBotService::class)->urlWebhook(),
            'syncConfigurata' => $directory->syncConfigurata(),
        ]);
    }

    public function update(Request $request, string $sezione, SalvaImpostazioni $salva): RedirectResponse
    {
        $salva->salva(self::SEZIONI[$sezione], $request->input('impostazioni', []), (array) $request->input('impostazioni_rimuovi', []));

        // Un esito di prova fallito non deve sopravvivere alla correzione.
        Cache::forget("integrazioni:prova:$sezione");

        if ($sezione === 'telegram') {
            return $this->collegaTelegram();
        }

        return back()->with('success', 'Impostazioni salvate.');
    }

    /**
     * Telegram con un solo tasto (1.0.9): dal token ProntoPA legge il nome del bot, genera il segreto,
     * registra il webhook, imposta il menu dei comandi e fa la prova. Prima servivano la console
     * (`telegram:set-webhook`) e BotFather (/setcommands).
     */
    private function collegaTelegram(): RedirectResponse
    {
        $bot = app(TelegramBotService::class);
        if (! $bot->isConfigured()) {
            return back()->with('success', 'Impostazioni salvate.');
        }

        $username = $bot->botVerificato();
        if ($username === null) {
            return $this->esito('telegram', false, 'Telegram non riconosce il token: ricopialo da @BotFather e salva di nuovo.');
        }
        Impostazione::set('telegram_bot_username', $username);

        if (! $bot->registraWebhook()) {
            return $this->esito('telegram', false, "Il bot @{$username} esiste, ma Telegram ha rifiutato il collegamento: l'indirizzo {$bot->urlWebhook()} deve essere pubblico e in HTTPS.");
        }

        $comandi = $bot->impostaComandi();
        [$ok, $messaggio] = $this->provaTelegram();
        if ($ok) {
            $messaggio = 'Salvato e collegato. '.$messaggio.($comandi ? ' Menu dei comandi impostato.' : ' Il menu dei comandi non è stato impostato: salva di nuovo per riprovare.');
        }

        return $this->esito('telegram', $ok, $messaggio);
    }

    private function esito(string $sezione, bool $ok, string $messaggio): RedirectResponse
    {
        Cache::put("integrazioni:prova:$sezione", [
            'ok' => $ok,
            'messaggio' => $messaggio,
            'quando' => now()->toIso8601String(),
        ], now()->addMinutes(30));

        return back()->with($ok ? 'success' : 'error', $messaggio);
    }

    public function prova(string $sezione): RedirectResponse
    {
        [$ok, $messaggio] = match ($sezione) {
            'spid' => $this->provaSpid(),
            'telegram' => $this->provaTelegram(),
            'ai' => $this->provaAi(),
            'antivirus' => $this->provaAntivirus(),
            default => abort(404),
        };

        return $this->esito($sezione, $ok, $messaggio);
    }

    /** @return array{0: bool, 1: string} */
    private function provaSpid(): array
    {
        $motivo = app(OidcClient::class)->verificaDiscovery();

        return $motivo === null
            ? [true, 'SPID raggiungibile: il servizio di accesso risponde correttamente.']
            : [false, $motivo];
    }

    /** @return array{0: bool, 1: string} */
    private function provaTelegram(): array
    {
        $bot = app(TelegramBotService::class);
        if (! $bot->isConfigured()) {
            return [false, 'Manca il token del bot.'];
        }
        $username = $bot->botVerificato();
        if ($username === null) {
            return [false, 'Telegram non risponde o il token non è valido.'];
        }

        $info = $bot->infoWebhook();
        $atteso = $bot->urlWebhook();
        if ($info === null) {
            return [false, "Il bot @{$username} esiste, ma non riesco a leggere lo stato del collegamento."];
        }
        if ($info['url'] !== $atteso) {
            return [false, $info['url'] === ''
                ? "Il bot @{$username} esiste ma non è collegato a ProntoPA: premi «Salva» per collegarlo."
                : "Il bot @{$username} è collegato a un altro indirizzo ({$info['url']}): premi «Salva» per collegarlo qui."];
        }
        if ($info['errore'] !== null) {
            return [false, "Telegram non riesce a consegnare i messaggi a {$atteso}: «{$info['errore']}». L'indirizzo deve essere raggiungibile da internet in HTTPS."];
        }

        return [true, "Telegram collegato al bot @{$username}: i messaggi arrivano a ProntoPA."];
    }

    /** @return array{0: bool, 1: string} */
    private function provaAi(): array
    {
        return app(OllamaService::class)->raggiungibile()
            ? [true, 'L\'assistente locale risponde.']
            : [false, 'L\'assistente locale non risponde: controlla l\'indirizzo e che il container del profilo Docker `ai` sia avviato.'];
    }

    /** @return array{0: bool, 1: string} */
    private function provaAntivirus(): array
    {
        return app(ClamAvService::class)->raggiungibile()
            ? [true, 'L\'antivirus risponde.']
            : [false, 'L\'antivirus non risponde: serve il container del profilo Docker `security`.'];
    }
}
