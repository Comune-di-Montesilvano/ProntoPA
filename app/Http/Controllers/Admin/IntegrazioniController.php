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
            'syncConfigurata' => $directory->syncConfigurata(),
        ]);
    }

    public function update(Request $request, string $sezione, SalvaImpostazioni $salva): RedirectResponse
    {
        $salva->salva(self::SEZIONI[$sezione], $request->input('impostazioni', []));

        return back()->with('success', 'Impostazioni salvate.');
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

        Cache::put("integrazioni:prova:$sezione", [
            'ok' => $ok,
            'messaggio' => $messaggio,
            'quando' => now()->toIso8601String(),
        ], now()->addMinutes(30));

        return back()->with($ok ? 'success' : 'error', $messaggio);
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

        return $username !== null
            ? [true, "Telegram collegato al bot @{$username}."]
            : [false, 'Telegram non risponde o il token non è valido.'];
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
