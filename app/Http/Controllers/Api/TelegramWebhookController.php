<?php

namespace App\Http\Controllers\Api;

use App\Enums\SegnalazioneStato;
use App\Http\Controllers\Controller;
use App\Models\AllegatoSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use App\Services\Telegram\AzioniBot;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class TelegramWebhookController extends Controller
{
    /** Testo dei tasti di TelegramBotService::TASTIERA => comando. */
    private const TASTI = [
        '📋 Lista' => '/lista', '📅 Oggi' => '/oggi', '🔥 Priorità' => '/priorita',
        '⏰ Scadenze' => '/scadenze', '🔍 Cerca n°' => '/cerca', '❓ Aiuto' => '/aiuto',
    ];

    public function __construct(
        private readonly TelegramBotService $telegram,
        private readonly SegnalazioneWorkflowService $workflow,
        private readonly AzioniBot $azioni,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->isValidSecret($request)) {
            abort(403);
        }

        $payload = $request->all();

        if (isset($payload['callback_query'])) {
            $this->handleCallbackQuery($payload['callback_query']);
            return response()->json(['ok' => true]);
        }

        if (isset($payload['message'])) {
            $this->handleMessage($payload['message']);
        }

        return response()->json(['ok' => true]);
    }

    private function isValidSecret(Request $request): bool
    {
        $expected = Impostazione::get('telegram_webhook_secret');

        if (! $expected) {
            return true;
        }

        return hash_equals((string) $expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }

    private function handleMessage(array $message): void
    {
        $chatId = (string) data_get($message, 'chat.id', '');
        $text = trim((string) data_get($message, 'text', ''));

        if ($chatId === '') {
            return;
        }

        if (preg_match('/^\/start(?:\s+(.+))?$/', $text, $matches) === 1) {
            $token = trim((string) ($matches[1] ?? ''));
            $giaCollegato = $token === '' ? User::where('telegram_chat_id', $chatId)->where('attivo', true)->first() : null;
            if ($giaCollegato && $giaCollegato->usaTelegram()) {
                // Riporta la tastiera fissa (chi era collegato prima della 1.0.11 non l'aveva mai ricevuta).
                $this->telegram->sendMessage($chatId, $this->buildHelpMessage($giaCollegato), null, true);
                return;
            }
            $this->handleStartCommand($chatId, $token);
            return;
        }

        $user = User::where('telegram_chat_id', $chatId)->where('attivo', true)->first(); // disattivato = non collegato
        if ($user && ! $user->usaTelegram()) {
            $user = null; // scuole e segnalatori: Telegram non è per loro (1.0.9)
        }

        if (! $user) {
            $this->telegram->sendMessage($chatId, $this->buildUnlinkedMessage());
            return;
        }

        if (isset($message['photo'])) {
            $this->handlePhotoMessage($user, $message);
            return;
        }

        // Tasti della tastiera fissa (1.0.11): valgono come i comandi.
        $text = self::TASTI[$text] ?? $text;

        // Il bot aspettava un testo (motivo, fine lavori, nota: 1.0.14)? Un comando o un tasto annulla l'attesa.
        if (str_starts_with($text, '/')) {
            $this->azioni->annullaAttesa($user);
        } elseif ($text !== '' && ($esito = $this->azioni->consumaTesto($user, $text)) !== null) {
            $this->telegram->sendMessage($user->telegram_chat_id, $esito['risposta']);
            if ($esito['apri'] !== null) {
                $this->handleOpenCommand($user, $esito['apri']);
            }
            return;
        }

        if ($text === '/lista') {
            $this->handleListCommand($user);
            return;
        }

        if ($text === '/oggi') {
            $this->handleOggiCommand($user);
            return;
        }

        if (preg_match('/^\/apri\s+(\d+)$/', $text, $matches) === 1) {
            $this->handleOpenCommand($user, (int) $matches[1]);
            return;
        }

        if (preg_match('/^\/chiudi\s+(\d+)$/', $text, $matches) === 1) {
            $this->handleChiudiCommand($user, (int) $matches[1]);
            return;
        }

        if (preg_match('/^\/note\s+(\d+)(?:\s+([\s\S]+))?$/', $text, $matches) === 1) {
            $this->handleNoteCommand($user, (int) $matches[1], trim($matches[2] ?? ''));
            return;
        }

        if ($text === '/priorita') {
            $this->handlePrioritaCommand($user);
            return;
        }

        if ($text === '/scadenze') {
            $this->handleScadenzeCommand($user);
            return;
        }

        if ($text === '/aiuto' || $text === '/help') {
            $this->telegram->sendMessage($user->telegram_chat_id, $this->buildHelpMessage($user), null, true);
            return;
        }

        if ($text === '/cerca') {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Scrivi il numero della segnalazione, ad esempio 3592.', null, true);
            return;
        }

        // Un numero da solo (3592 o #3592) apre la segnalazione: è la risposta a «🔍 Cerca n°».
        if (preg_match('/^#?(\d{1,9})$/', $text, $matches) === 1) {
            $this->handleOpenCommand($user, (int) $matches[1]);
            return;
        }

        $this->telegram->sendMessage($chatId, $this->buildHelpMessage($user), null, true);
    }

    private function handleStartCommand(string $chatId, string $token): void
    {
        if ($token === '') {
            $this->telegram->sendMessage($chatId, $this->buildUnlinkedMessage());
            return;
        }

        $user = User::where('telegram_link_token', $token)
            ->whereNotNull('telegram_link_expires_at')
            ->where('telegram_link_expires_at', '>', now())
            ->first();

        if (! $user) {
            $this->telegram->sendMessage($chatId, 'Token non valido o scaduto. Genera un nuovo collegamento dal profilo utente.');
            return;
        }

        if (! $user->usaTelegram()) {
            $this->telegram->sendMessage($chatId, 'Il bot è riservato a chi lavora le segnalazioni (comune, operai, ditte).');
            return;
        }

        User::where('telegram_chat_id', $chatId)
            ->where('id', '!=', $user->id)
            ->update([
                'telegram_chat_id' => null,
                'telegram_verified_at' => null,
            ]);

        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_verified_at' => now(),
            'telegram_link_token' => null,
            'telegram_link_expires_at' => null,
        ])->save();

        $this->telegram->sendMessage($chatId, "Account collegato. Usa i tasti qui sotto: 📋 Lista, 📅 Oggi, 🔥 Priorità. Tocca una segnalazione per aprirla.", null, true);
    }

    /** Scadute o in scadenza entro 24 ore, le più vicine prima (come i filtri rapidi dell'elenco web). */
    private function handleScadenzeCommand(User $user): void
    {
        $segnalazioni = Segnalazione::visibileA($user)
            ->with(['stato'])
            ->aperte()
            ->where('id_stato_segnalazione', '!=', SegnalazioneStato::ATTESA_RISPOSTA->value)
            ->whereNotNull('data_scadenza_sla')
            ->where('data_scadenza_sla', '<=', now()->addDay())
            ->orderBy('data_scadenza_sla')
            ->limit(10)
            ->get();

        $this->inviaElenco($user, 'Scadute o in scadenza entro 24 ore:', 'Niente in scadenza nelle prossime 24 ore.', $segnalazioni,
            function (Segnalazione $s): string {
                $scadenza = \Illuminate\Support\Carbon::parse($s->data_scadenza_sla);

                return $scadenza->isPast()
                    ? 'scaduta'
                    : 'scade '.($scadenza->isToday() ? 'oggi' : 'domani').' alle '.$scadenza->format('H:i');
            });
    }

    private function handleListCommand(User $user): void
    {
        $segnalazioni = Segnalazione::visibileA($user)
            ->with(['stato'])
            ->aperte()
            ->orderByDesc('data_segnalazione')
            ->limit(10)
            ->get();

        $this->inviaElenco($user, 'Segnalazioni aperte, le più recenti:', 'Nessuna segnalazione aperta.', $segnalazioni,
            fn (Segnalazione $s) => $s->stato?->descrizione ?? '');
    }

    /** Titolo per Telegram: quelli scritti tutti in maiuscolo diventano una frase normale. */
    private function titolo(Segnalazione $s, int $limite): string
    {
        $titolo = trim($s->titoloLeggibile($limite));
        if ($titolo !== '' && mb_strtoupper($titolo) === $titolo) {
            $titolo = mb_ucfirst(mb_strtolower($titolo));
        }

        return Str::limit($titolo, $limite);
    }

    /**
     * Elenco a pulsanti (1.0.11): un pulsante per segnalazione, toccandolo si apre il dettaglio.
     *
     * @param  \Illuminate\Support\Collection<int, Segnalazione>  $segnalazioni
     * @param  callable(Segnalazione): string  $coda  testo dopo il titolo (stato, priorità…)
     */
    private function inviaElenco(User $user, string $intestazione, string $vuoto, $segnalazioni, callable $coda): void
    {
        if ($segnalazioni->isEmpty()) {
            $this->telegram->sendMessage($user->telegram_chat_id, $vuoto, null, true);
            return;
        }

        $pulsanti = $segnalazioni->map(fn (Segnalazione $s) => [[
            'text' => trim('#'.$s->id_segnalazione.' · '.$this->titolo($s, 40).' · '.$coda($s), ' ·'),
            'callback_data' => 'apri:'.$s->id_segnalazione,
        ]])->values()->all();

        $this->telegram->sendMessage($user->telegram_chat_id, $intestazione, $pulsanti);
    }

    private function handleOpenCommand(User $user, int $idSegnalazione): void
    {
        $segnalazione = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia', 'appalto.impresa', 'operatore', 'plesso'])
            ->find($idSegnalazione);

        if (! $segnalazione) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Segnalazione non trovata o non accessibile.');
            return;
        }

        $id = $segnalazione->id_segnalazione;
        $keyboard = $this->azioni->pulsanti($segnalazione, $user);
        $keyboard[] = [
            ['text' => '📝 Note', 'callback_data' => 'note:'.$id],
            ['text' => '⬅️ Lista', 'callback_data' => 'lista'],
        ];

        $message = implode("\n", array_filter([
            'Segnalazione #' . $id . ' · ' . $this->titolo($segnalazione, 120),
            'Stato: ' . ($segnalazione->stato?->descrizione ?? 'N/D'),
            'Tipologia: ' . ($segnalazione->tipologia?->nomeLeggibile() ?? 'N/D'),
            $segnalazione->plesso ? 'Sede: ' . $segnalazione->plesso->nomeLeggibile() : null,
            'Operatore: ' . ($segnalazione->operatore?->name ?? 'Non assegnato'),
            $segnalazione->appalto?->impresa ? 'Impresa: ' . $segnalazione->appalto->impresa->ragione_sociale : null,
            '',
            Str::limit($segnalazione->testo_segnalazione, 600),
            '',
            'Per una foto: inviala con #' . $id . ' nella didascalia.',
        ], fn ($riga) => $riga !== null));

        $this->telegram->sendMessage($user->telegram_chat_id, $message, $keyboard);
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $chatId = (string) data_get($callbackQuery, 'message.chat.id', '');
        $callbackId = (string) data_get($callbackQuery, 'id', '');
        $data = (string) data_get($callbackQuery, 'data', '');

        $user = User::where('telegram_chat_id', $chatId)->where('attivo', true)->first(); // disattivato = non collegato
        if ($user && ! $user->usaTelegram()) {
            $user = null; // scuole e segnalatori: Telegram non è per loro (1.0.9)
        }

        if (! $user) {
            $this->telegram->answerCallbackQuery($callbackId, 'Account Telegram non collegato.');
            return;
        }

        // Pulsanti di navigazione (1.0.11): visibilità controllata dai metodi chiamati (visibileA).
        if (preg_match('/^apri:(\d+)$/', $data, $m) === 1) {
            $this->telegram->answerCallbackQuery($callbackId, '');
            $this->handleOpenCommand($user, (int) $m[1]);
            return;
        }
        if (preg_match('/^note:(\d+)$/', $data, $m) === 1) {
            $this->telegram->answerCallbackQuery($callbackId, '');
            $this->handleNoteCommand($user, (int) $m[1], '');
            return;
        }
        if ($data === 'lista') {
            $this->telegram->answerCallbackQuery($callbackId, '');
            $this->handleListCommand($user);
            return;
        }

        // Azioni della scheda a pulsanti (1.0.14): assegna, senza intervento, fine lavori, rimanda…
        $esito = $this->azioni->gestisci($user, $data);
        if ($esito !== null) {
            $this->telegram->answerCallbackQuery($callbackId, $esito['risposta']);
            if ($esito['apri'] !== null) {
                $this->handleOpenCommand($user, $esito['apri']);
            }
            return;
        }

        if (! preg_match('/^azione:(\d+):(\d+)$/', $data, $matches)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Azione non valida.');
            return;
        }

        $segnalazione = Segnalazione::visibileA($user)
            ->with('stato')
            ->find((int) $matches[1]);

        if (! $segnalazione) {
            $this->telegram->answerCallbackQuery($callbackId, 'Segnalazione non accessibile.');
            return;
        }

        $azione = $this->azioniTelegram($segnalazione, $user)
            ->first(fn ($item) => (int) $item->id_azione === (int) $matches[2]);

        if (! $azione) {
            $this->telegram->answerCallbackQuery($callbackId, 'Azione non disponibile da Telegram: apri la scheda.');
            return;
        }

        try {
            $this->workflow->eseguiAzione($segnalazione, $azione->id_azione, $user);
        } catch (ValidationException $e) {
            $this->telegram->answerCallbackQuery($callbackId, (string) collect($e->errors())->flatten()->first());
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId, 'Azione eseguita.');
        $this->handleOpenCommand($user, $segnalazione->id_segnalazione);
    }

    private function handleChiudiCommand(User $user, int $idSegnalazione): void
    {
        $segnalazione = Segnalazione::visibileA($user)->with('stato')->find($idSegnalazione);

        if (! $segnalazione) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Segnalazione #' . $idSegnalazione . ' non trovata.');
            return;
        }

        if ($segnalazione->isChiusa()) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Segnalazione #' . $idSegnalazione . ' è già chiusa.');
            return;
        }

        // Solo "Chiudi" (lavoro fatto): "senza intervento" chiede un motivo, si fa dalla scheda.
        $azione = $this->azioniTelegram($segnalazione, $user)->firstWhere('codice', 'chiudi');

        if (! $azione) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Nessuna azione di chiusura disponibile per #' . $idSegnalazione . '.');
            return;
        }

        try {
            $this->workflow->eseguiAzione($segnalazione, $azione->id_azione, $user);
        } catch (ValidationException $e) {
            $this->telegram->sendMessage($user->telegram_chat_id, (string) collect($e->errors())->flatten()->first());
            return;
        }
        $this->telegram->sendMessage($user->telegram_chat_id, '✅ Azione "' . $azione->descrizione . '" eseguita su #' . $idSegnalazione . '.');
    }

    private function handleNoteCommand(User $user, int $idSegnalazione, string $testo): void
    {
        $segnalazione = Segnalazione::visibileA($user)->with('stato')->find($idSegnalazione);

        if (! $segnalazione) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Segnalazione #' . $idSegnalazione . ' non trovata.');
            return;
        }

        if ($testo !== '') {
            $segnalazione->note()->create([
                'testo'            => $testo,
                'id_utente'        => $user->id,
                'visibile_web'     => false,
                'visibile_impresa' => false,
            ]);
            $this->telegram->sendMessage($user->telegram_chat_id, '📝 Nota aggiunta a #' . $idSegnalazione . '.');
            return;
        }

        $ultime = $segnalazione->note()->visibiliA($user)->latest()->limit(3)->get();

        $aggiungi = 'Per aggiungerne una scrivi: /note ' . $idSegnalazione . ' testo della nota';
        $indietro = [[['text' => '⬅️ Torna a #' . $idSegnalazione, 'callback_data' => 'apri:' . $idSegnalazione]]];

        if ($ultime->isEmpty()) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Nessuna nota per #' . $idSegnalazione . ".\n" . $aggiungi, $indietro);
            return;
        }

        $lines = ['Ultime note su #' . $idSegnalazione . ':'];
        foreach ($ultime as $nota) {
            $lines[] = '— ' . Str::limit($nota->testo, 200);
        }
        $lines[] = '';
        $lines[] = $aggiungi;

        $this->telegram->sendMessage($user->telegram_chat_id, implode("\n", $lines), $indietro);
    }

    private function handlePrioritaCommand(User $user): void
    {
        $segnalazioni = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia'])
            ->aperte()
            ->orderByDesc('livello_priorita')
            ->limit(10)
            ->get();

        $this->inviaElenco($user, 'Segnalazioni aperte, le più urgenti:', 'Nessuna segnalazione aperta.', $segnalazioni,
            fn (Segnalazione $s) => (string) $s->label_priorita);
    }

    private function buildHelpMessage(User $user): string
    {
        return implode("\n", [
            'Usa i tasti qui sotto:',
            '📋 Lista: segnalazioni aperte, le più recenti',
            '📅 Oggi: i lavori assegnati a te',
            '🔥 Priorità: le più urgenti',
            '⏰ Scadenze: scadute o in scadenza entro 24 ore',
            '🔍 Cerca n°: scrivi il numero e si apre',
            '',
            'Tocca una segnalazione per aprirla: lì trovi le azioni e le note.',
            'Per allegare una foto inviala con il numero nella didascalia, ad esempio #3592.',
        ]);
    }

    private function buildUnlinkedMessage(): string
    {
        $botUsername = Impostazione::get('telegram_bot_username');
        $botLabel = $botUsername ? '@' . ltrim($botUsername, '@') : 'il bot configurato';

        return "Account non collegato. Genera un token dal tuo profilo ProntoPA e avvia {$botLabel} con /start <token>.";
    }

    private function handleOggiCommand(User $user): void
    {
        $query = Segnalazione::aperte();

        if ($user->hasRole('impresa')) {
            $appaltiIds = \App\Models\Appalto::where('id_impresa', $user->id_impresa)
                ->where('valido', true)
                ->pluck('id_appalto');
            $query->whereIn('id_appalto', $appaltiIds);
        } else {
            $query->where('id_operatore_assegnato', $user->id);
        }

        $segnalazioni = $query->with(['stato', 'tipologia'])
            ->orderBy('livello_priorita', 'desc')
            ->orderBy('data_segnalazione')
            ->limit(10)
            ->get();

        $this->inviaElenco($user, 'I tuoi lavori, i più urgenti prima:', 'Nessun lavoro assegnato a te.', $segnalazioni,
            fn (Segnalazione $s) => (string) $s->label_priorita);
    }

    private function handlePhotoMessage(User $user, array $message): void
    {
        $chatId = (string) data_get($message, 'chat.id', '');
        $caption = trim((string) data_get($message, 'caption', ''));

        // Durante "Fine lavori" (1.0.14) la foto senza didascalia va a quella segnalazione, come foto "dopo".
        $attesa = $this->azioni->attesa($user);
        $perFineLavori = $attesa !== null && $attesa['tipo'] === 'fl' && preg_match('/#?(\d+)/', $caption) !== 1;

        if ($perFineLavori) {
            $idSegnalazione = (int) $attesa['id'];
        } elseif (preg_match('/#?(\d+)/', $caption, $matches) === 1) {
            $idSegnalazione = (int) $matches[1];
        } else {
            $this->telegram->sendMessage($chatId, "Per favore, invia nuovamente la foto specificando il numero della segnalazione nella didascalia (es: 'Foto intervento #123' o '123').");
            return;
        }
        $segnalazione = Segnalazione::visibileA($user)->find($idSegnalazione);

        if (! $segnalazione) {
            $this->telegram->sendMessage($chatId, "Segnalazione #{$idSegnalazione} non trovata o non sei autorizzato ad accedervi.");
            return;
        }

        if ($segnalazione->isChiusa()) {
            $this->telegram->sendMessage($chatId, "Impossibile aggiungere foto ad una segnalazione chiusa.");
            return;
        }

        $photos = data_get($message, 'photo', []);
        if (empty($photos)) {
            $this->telegram->sendMessage($chatId, "Nessuna foto trovata nel messaggio.");
            return;
        }

        $largestPhoto = end($photos);
        $fileId = data_get($largestPhoto, 'file_id');

        $filePath = $this->telegram->getFilePath($fileId);
        if (! $filePath) {
            $this->telegram->sendMessage($chatId, "Errore nel recuperare i dettagli della foto da Telegram.");
            return;
        }

        $fileContent = $this->telegram->downloadFile($filePath);
        if (! $fileContent) {
            $this->telegram->sendMessage($chatId, "Errore nel download della foto.");
            return;
        }

        $disk = Impostazione::get('allegati_storage_disk', 'local');
        $filename = Str::uuid() . '.' . pathinfo($filePath, PATHINFO_EXTENSION);
        $localPath = 'allegati/' . $segnalazione->id_segnalazione . '/' . $filename;

        Storage::disk($disk)->put($localPath, $fileContent);

        $mime = 'image/jpeg';
        if (Str::endsWith(Str::lower($filePath), '.png')) {
            $mime = 'image/png';
        }

        $fase = $user->hasRole('impresa') || $perFineLavori ? 'dopo' : 'prima';

        AllegatoSegnalazione::create([
            'id_segnalazione'     => $segnalazione->id_segnalazione,
            'percorso'            => $localPath,
            'tipo'                => $mime,
            'nome_originale'      => basename($filePath),
            'dimensione'          => strlen($fileContent),
            'id_utente_creazione' => $user->id,
            'fase'                => $fase,
        ]);

        $reply = "Foto caricata con successo per la segnalazione #{$segnalazione->id_segnalazione} (Fase: " . ($fase === 'dopo' ? 'Dopo' : 'Prima') . ").";

        if ($perFineLavori) {
            $reply = "📷 Foto aggiunta a #{$segnalazione->id_segnalazione}. Mandane altre o scrivi cosa è stato fatto per finire.";
        } elseif ($user->hasRole('impresa')) {
            $reply .= "\n\nPer comunicare la fine dei lavori apri la segnalazione e premi 🏁 Fine lavori, oppure dalla scheda:\n" . route('segnalazioni.show', $segnalazione->id_segnalazione);
        }

        $this->telegram->sendMessage($chatId, $reply);
    }

    /**
     * Azioni eseguibili da Telegram: solo chi può aggiornare la segnalazione dalla scheda,
     * solo azioni senza campi da compilare.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Azione>
     */
    private function azioniTelegram(Segnalazione $segnalazione, User $user): \Illuminate\Support\Collection
    {
        if (Gate::forUser($user)->denies('update', $segnalazione)) {
            return collect();
        }

        return $this->workflow->getAzioniRapide($segnalazione, $user);
    }
}
