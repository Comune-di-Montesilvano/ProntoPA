<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramBotService $telegram,
        private readonly SegnalazioneWorkflowService $workflow,
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
            $this->handleStartCommand($chatId, trim((string) ($matches[1] ?? '')));
            return;
        }

        $user = User::where('telegram_chat_id', $chatId)->first();

        if (! $user) {
            $this->telegram->sendMessage($chatId, $this->buildUnlinkedMessage());
            return;
        }

        if ($text === '/lista') {
            $this->handleListCommand($user);
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

        $this->telegram->sendMessage($chatId, $this->buildHelpMessage($user));
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

        $this->telegram->sendMessage($chatId, "Account collegato con successo. Usa /lista per vedere le segnalazioni assegnate o visibili.");
    }

    private function handleListCommand(User $user): void
    {
        $segnalazioni = Segnalazione::visibileA($user)
            ->with(['stato'])
            ->aperte()
            ->orderByDesc('data_segnalazione')
            ->limit(10)
            ->get();

        if ($segnalazioni->isEmpty()) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Nessuna segnalazione aperta disponibile.');
            return;
        }

        $lines = ['Segnalazioni aperte:'];
        foreach ($segnalazioni as $segnalazione) {
            $lines[] = sprintf(
                '#%d - %s (%s)',
                $segnalazione->id_segnalazione,
                Str::limit($segnalazione->testo_segnalazione, 40),
                $segnalazione->stato?->descrizione ?? 'N/D'
            );
        }
        $lines[] = 'Usa /apri <id> per il dettaglio.';

        $this->telegram->sendMessage($user->telegram_chat_id, implode("\n", $lines));
    }

    private function handleOpenCommand(User $user, int $idSegnalazione): void
    {
        $segnalazione = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia', 'appalto.impresa', 'operatore'])
            ->find($idSegnalazione);

        if (! $segnalazione) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Segnalazione non trovata o non accessibile.');
            return;
        }

        $azioni = $this->workflow->getAzioniDisponibili($segnalazione, $user)
            ->filter(fn ($azione) => ! $azione->flag_operatore && ! $azione->flag_appalto)
            ->take(6)
            ->values();

        $keyboard = $azioni->isNotEmpty()
            ? $azioni->chunk(2)->map(fn ($chunk) => $chunk->map(fn ($azione) => [
                'text' => $azione->descrizione,
                'callback_data' => sprintf('azione:%d:%d', $segnalazione->id_segnalazione, $azione->id_azione),
            ])->all())->all()
            : null;

        $message = implode("\n", [
            'Segnalazione #' . $segnalazione->id_segnalazione,
            'Stato: ' . ($segnalazione->stato?->descrizione ?? 'N/D'),
            'Tipologia: ' . ($segnalazione->tipologia?->descrizione ?? 'N/D'),
            'Operatore: ' . ($segnalazione->operatore?->name ?? 'Non assegnato'),
            'Impresa: ' . ($segnalazione->appalto?->impresa?->ragione_sociale ?? 'Nessuna'),
            'Testo: ' . Str::limit($segnalazione->testo_segnalazione, 300),
        ]);

        $this->telegram->sendMessage($user->telegram_chat_id, $message, $keyboard);
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $chatId = (string) data_get($callbackQuery, 'message.chat.id', '');
        $callbackId = (string) data_get($callbackQuery, 'id', '');
        $data = (string) data_get($callbackQuery, 'data', '');

        $user = User::where('telegram_chat_id', $chatId)->first();

        if (! $user) {
            $this->telegram->answerCallbackQuery($callbackId, 'Account Telegram non collegato.');
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

        $azione = $this->workflow->getAzioniDisponibili($segnalazione, $user)
            ->first(fn ($item) => (int) $item->id_azione === (int) $matches[2]);

        if (! $azione || $azione->flag_operatore || $azione->flag_appalto) {
            $this->telegram->answerCallbackQuery($callbackId, 'Questa azione richiede parametri aggiuntivi.');
            return;
        }

        $this->workflow->eseguiAzione($segnalazione, $azione->id_azione, $user);

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

        $disponibili = $this->workflow->getAzioniDisponibili($segnalazione, $user)
            ->filter(fn ($a) => ! $a->flag_operatore && ! $a->flag_appalto);

        $azione = $disponibili->first(fn ($a) => $a->statoTarget?->chiusura)
            ?? $disponibili->first(fn ($a) => preg_match('/chiudi|proponi/i', (string) $a->descrizione));

        if (! $azione) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Nessuna azione di chiusura disponibile per #' . $idSegnalazione . '.');
            return;
        }

        $this->workflow->eseguiAzione($segnalazione, $azione->id_azione, $user);
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

        $query = $segnalazione->note()->latest();
        if ($user->hasRole('impresa')) {
            $query->where('visibile_impresa', true);
        }
        $ultime = $query->limit(3)->get();

        if ($ultime->isEmpty()) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Nessuna nota per #' . $idSegnalazione . '.');
            return;
        }

        $lines = ['Ultime note su #' . $idSegnalazione . ':'];
        foreach ($ultime as $nota) {
            $lines[] = '— ' . Str::limit($nota->testo, 100);
        }

        $this->telegram->sendMessage($user->telegram_chat_id, implode("\n", $lines));
    }

    private function handlePrioritaCommand(User $user): void
    {
        $segnalazioni = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia'])
            ->aperte()
            ->orderByDesc('segnalazione_urgente')
            ->orderByDesc('livello_priorita')
            ->limit(10)
            ->get();

        if ($segnalazioni->isEmpty()) {
            $this->telegram->sendMessage($user->telegram_chat_id, 'Nessuna segnalazione aperta.');
            return;
        }

        $lines = ['Segnalazioni per priorità:'];
        foreach ($segnalazioni as $s) {
            $urgenza = $s->segnalazione_urgente ? ' 🚨' : '';
            $lines[] = sprintf(
                '[%s%s] #%d — %s',
                $s->label_priorita,
                $urgenza,
                $s->id_segnalazione,
                Str::limit($s->tipologia?->descrizione ?? $s->testo_segnalazione, 40)
            );
        }

        $this->telegram->sendMessage($user->telegram_chat_id, implode("\n", $lines));
    }

    private function buildHelpMessage(User $user): string
    {
        return "Comandi disponibili:\n/lista\n/apri <id>\n/chiudi <id>\n/note <id> [testo]\n/priorita";
    }

    private function buildUnlinkedMessage(): string
    {
        $botUsername = Impostazione::get('telegram_bot_username');
        $botLabel = $botUsername ? '@' . ltrim($botUsername, '@') : 'il bot configurato';

        return "Account non collegato. Genera un token dal tuo profilo ProntoPA e avvia {$botLabel} con /start <token>.";
    }
}