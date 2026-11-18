<?php

namespace App\Services\Telegram;

use App\Enums\SegnalazioneStato;
use App\Models\Appalto;
use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use App\Services\Segnalazioni\FineLavoriService;
use App\Services\Segnalazioni\SmistaSquadra;
use App\Services\Segnalazioni\Sollecito;
use App\Services\TelegramBotService;
use App\Support\Segnalazioni\AppaltiPerTipologia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Azioni della scheda dal bot Telegram (1.0.14): stesse regole del web (policy + workflow), a pulsanti.
 * Quelle che chiedono un testo (motivo, fine lavori, nota) mettono il bot "in attesa" del prossimo messaggio.
 *
 * Pulsanti (callback_data, max 64 byte):
 *   me:ID              assegna a me
 *   asg:ID             scegli a chi assegnare (operaio, squadra, ditta)
 *   asl:ID:o|s|d:N     elenco da N
 *   ass:ID:o|s|d:X     assegna a X (utente, squadra, appalto)
 *   si:ID              motivi di chiusura senza intervento
 *   sim:ID:MOTIVO      chiudi senza intervento (altro → chiede il testo)
 *   sol:ID             sollecita chi ha il lavoro
 *   txt:ID:nota|fl|rim|rp   aspetta un testo: nota, fine lavori, rimanda al comune, rifiuta preventivo
 *   ann                annulla l'attesa
 */
class AzioniBot
{
    private const PER_PAGINA = 12;

    private const ETICHETTE_RAPIDE = [
        'riapri' => '🔄 Riapri',
        'riprendi' => '▶️ Riprendi',
        'accetta_preventivo' => '👍 Accetta preventivo',
    ];

    private const RICHIESTE_TESTO = [
        'nota' => 'Scrivi la nota per #%d e inviala.',
        'fl' => "Scrivi cosa è stato fatto su #%d e invialo: il lavoro risulterà finito.\nPrima puoi mandare una o più foto (anche senza didascalia).",
        'rim' => 'Scrivi perché rimandi #%d al comune e invialo.',
        'rp' => 'Scrivi perché rifiuti il preventivo di #%d e invialo.',
        'alt' => 'Scrivi il motivo della chiusura senza intervento di #%d e invialo.',
        'chi' => "Chiudi #%d: scrivi una nota finale e inviala, oppure premi «Chiudi ora».\nPrima puoi mandare foto del lavoro: 📎 → Fotocamera.",
        'prev' => "Preventivo per #%d: scrivi l'importo in euro e cosa comprende, ad esempio «1250,50 sostituzione caldaia».",
        'foto' => "Manda le foto per #%d: 📎 → Fotocamera (o Galleria), anche più di una, senza scrivere il numero.\nQuando hai finito premi «Fatto».",
    ];

    /** Mentre il bot aspetta questi testi, le foto senza didascalia vanno alla segnalazione in attesa. */
    private const ATTESE_CON_FOTO = ['fl', 'chi', 'foto'];

    public function __construct(
        private readonly TelegramBotService $telegram,
        private readonly SegnalazioneWorkflowService $workflow,
    ) {}

    /**
     * Righe di pulsanti con le azioni che l'utente può fare ora sulla segnalazione.
     *
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function pulsanti(Segnalazione $s, User $user): array
    {
        $id = $s->id_segnalazione;
        $pulsanti = [];
        $disponibili = $this->disponibili($s, $user);

        if ($disponibili->has('prendi_in_carico') && ! $this->giaInCaricoA($s, $user)) {
            $pulsanti[] = $this->tasto('🙋 Assegna a me', "me:$id");
        }
        if ($disponibili->has('assegna_operatore') || $disponibili->has('assegna_impresa')) {
            $pulsanti[] = $this->tasto($s->statoEnum()->value >= 3 ? '👷 Riassegna…' : '👷 Assegna…', "asg:$id");
        }
        foreach (self::ETICHETTE_RAPIDE as $codice => $etichetta) {
            if ($disponibili->has($codice)) {
                $pulsanti[] = $this->tasto($etichetta, sprintf('azione:%d:%d', $id, $disponibili[$codice]->id_azione));
            }
        }
        if ($disponibili->has('chiudi')) {
            $pulsanti[] = $this->tasto('✅ Chiudi', "chi:$id");
        }
        if ($disponibili->has('presenta_preventivo')) {
            $pulsanti[] = $this->tasto('💶 Presenta preventivo', "txt:$id:prev");
        }
        if (app(SmistaSquadra::class)->squadraDiCapo($s, $user) !== null) {
            $pulsanti[] = $this->tasto('👥 Smista', "smi:$id");
        }
        if ($disponibili->has('rifiuta_preventivo')) {
            $pulsanti[] = $this->tasto('👎 Rifiuta preventivo', "txt:$id:rp");
        }
        if (Gate::forUser($user)->allows('comunicaFineLavori', $s) && ! $s->haFineLavori()) {
            $pulsanti[] = $this->tasto('🏁 Fine lavori', "txt:$id:fl");
        }
        if (Gate::forUser($user)->allows('rimanda', $s)) {
            $pulsanti[] = $this->tasto('↩️ Rimanda al comune', "txt:$id:rim");
        }
        if (Gate::forUser($user)->allows('sollecita', $s)) {
            $pulsanti[] = $this->tasto('🔔 Sollecita', "sol:$id");
        }
        if ($disponibili->has('annulla')) {
            $pulsanti[] = $this->tasto('🚫 Senza intervento…', "si:$id");
        }
        if (! $s->isChiusa()) {
            $pulsanti[] = $this->tasto('📷 Aggiungi foto', "txt:$id:foto");
            $pulsanti[] = $this->tasto('✍️ Scrivi nota', "txt:$id:nota");
        }

        return array_values(array_map('array_values', array_chunk($pulsanti, 2)));
    }

    /**
     * Pulsante premuto. null = non è un'azione di questa classe.
     *
     * @return array{risposta: string, apri: ?int}|null  testo per answerCallbackQuery, segnalazione da riaprire
     */
    public function gestisci(User $user, string $dati): ?array
    {
        if ($dati === 'ann') {
            Cache::forget($this->chiaveAttesa($user));

            return ['risposta' => 'Annullato.', 'apri' => null];
        }

        if (preg_match('/^(me|asg|si|sol|chi|chn|smi):(\d+)$/', $dati, $m) === 1
            || preg_match('/^(asl|ass):(\d+):([osd]):(\d+)$/', $dati, $m) === 1
            || preg_match('/^(sim):(\d+):([a-z_]+)$/', $dati, $m) === 1
            || preg_match('/^(sms):(\d+):(\d+)$/', $dati, $m) === 1
            || preg_match('/^(txt):(\d+):(nota|fl|rim|rp|prev|foto)$/', $dati, $m) === 1) {
            $s = Segnalazione::visibileA($user)->with('stato')->find((int) $m[2]);
            if (! $s) {
                return ['risposta' => 'Segnalazione non accessibile.', 'apri' => null];
            }

            try {
                return match ($m[1]) {
                    'me' => $this->esegui($s, $user, 'prendi_in_carico', []),
                    'asg' => $this->menuAssegna($s, $user),
                    'asl' => $this->elencoAssegna($s, $user, $m[3], (int) $m[4]),
                    'ass' => $this->assegna($s, $user, $m[3], (int) $m[4]),
                    'si' => $this->menuSenzaIntervento($s, $user),
                    'sim' => $this->senzaIntervento($s, $user, $m[3]),
                    'sol' => $this->sollecita($s, $user),
                    'txt' => $this->chiediTesto($s, $user, $m[3]),
                    'chi' => $this->chiediTesto($s, $user, 'chi'),
                    'chn' => $this->chiudi($s, $user, ''),
                    'smi' => $this->menuSmista($s, $user),
                    'sms' => $this->smista($s, $user, (int) $m[3]),
                };
            } catch (ValidationException $e) {
                return ['risposta' => Str::limit((string) collect($e->errors())->flatten()->first(), 190), 'apri' => null];
            }
        }

        return null;
    }

    /** Il bot aspetta un testo da questo utente? (per le foto senza didascalia durante la fine lavori) */
    public function attesa(User $user): ?array
    {
        $attesa = Cache::get($this->chiaveAttesa($user));

        return is_array($attesa) ? $attesa : null;
    }

    /**
     * Foto senza didascalia mentre il bot aspetta (fine lavori, chiusura, «Aggiungi foto»):
     * segnalazione e fase da usare, null se non c'è un'attesa che le accetta.
     *
     * @return array{id: int, fase: string}|null
     */
    public function fotoInAttesa(User $user): ?array
    {
        $attesa = $this->attesa($user);
        if ($attesa === null || ! in_array($attesa['tipo'], self::ATTESE_CON_FOTO, true)) {
            return null;
        }
        $lavoro = $attesa['tipo'] !== 'foto' || $user->hasRole('impresa') || $user->hasRole('operaio');

        return ['id' => (int) $attesa['id'], 'fase' => $lavoro ? 'dopo' : 'prima'];
    }

    public function annullaAttesa(User $user): void
    {
        Cache::forget($this->chiaveAttesa($user));
    }

    /**
     * Messaggio scritto mentre il bot aspettava un testo. null = nessuna attesa.
     *
     * @return array{risposta: string, apri: ?int}|null
     */
    public function consumaTesto(User $user, string $testo): ?array
    {
        $attesa = $this->attesa($user);
        if ($attesa === null) {
            return null;
        }
        Cache::forget($this->chiaveAttesa($user));
        if ($attesa['tipo'] === 'foto') {
            return null; // finite le foto: il messaggio vale per quello che è
        }

        $s = Segnalazione::visibileA($user)->with('stato')->find((int) $attesa['id']);
        if (! $s) {
            return ['risposta' => 'Segnalazione non accessibile.', 'apri' => null];
        }
        $testo = Str::limit(trim($testo), 2000, '');

        try {
            return match ($attesa['tipo']) {
                'nota' => $this->nota($s, $user, $testo),
                'fl' => $this->fineLavori($s, $user, $testo),
                'rim' => $this->rimanda($s, $user, $testo),
                'rp' => $this->esegui($s, $user, 'rifiuta_preventivo', ['nota' => $testo]),
                'alt' => $this->esegui($s, $user, 'annulla', ['motivo' => 'altro', 'nota' => $testo]),
                'chi' => $this->chiudi($s, $user, $testo),
                'prev' => $this->preventivo($s, $user, $testo),
                default => ['risposta' => 'Annullato.', 'apri' => null],
            };
        } catch (ValidationException $e) {
            return ['risposta' => (string) collect($e->errors())->flatten()->first(), 'apri' => $s->id_segnalazione];
        }
    }

    // ── Azioni ───────────────────────────────────────────────────────────────

    /** @return array{risposta: string, apri: ?int} */
    private function esegui(Segnalazione $s, User $user, string $codice, array $parametri): array
    {
        $azione = $this->disponibili($s, $user)->get($codice);
        if (! $azione) {
            return ['risposta' => 'Azione non disponibile ora.', 'apri' => $s->id_segnalazione];
        }

        $this->workflow->eseguiAzione($s, $azione->id_azione, $user, $parametri);

        return ['risposta' => '✅ '.$azione->descrizione.': fatto.', 'apri' => $s->id_segnalazione];
    }

    /** @return array{risposta: string, apri: ?int} */
    private function menuAssegna(Segnalazione $s, User $user): array
    {
        $disponibili = $this->disponibili($s, $user);
        $id = $s->id_segnalazione;
        $righe = [];
        if ($disponibili->has('assegna_operatore')) {
            $righe[] = [$this->tasto('👤 Operaio', "asl:$id:o:0")];
            if (Impostazione::get('squadre_enabled', false) && Squadra::where('attiva', true)->exists()) {
                $righe[] = [$this->tasto('👥 Squadra', "asl:$id:s:0")];
            }
        }
        if ($disponibili->has('assegna_impresa')) {
            $righe[] = [$this->tasto('🏢 Ditta', "asl:$id:d:0")];
        }
        if ($righe === []) {
            return ['risposta' => 'Assegnazione non disponibile ora.', 'apri' => $id];
        }
        $righe[] = [$this->tasto('⬅️ Torna a #'.$id, "apri:$id")];

        $this->telegram->sendMessage($user->telegram_chat_id, 'A chi assegni #'.$id.'?', $righe);

        return ['risposta' => '', 'apri' => null];
    }

    /** @return array{risposta: string, apri: ?int} */
    private function elencoAssegna(Segnalazione $s, User $user, string $tipo, int $da): array
    {
        $id = $s->id_segnalazione;
        $candidati = $this->candidati($s, $tipo);
        $pagina = $candidati->slice($da, self::PER_PAGINA);
        if ($pagina->isEmpty()) {
            return ['risposta' => 'Nessuno disponibile.', 'apri' => null];
        }

        $righe = $pagina->map(fn (array $c) => [$this->tasto(Str::limit($c['nome'], 60), "ass:$id:$tipo:{$c['id']}")])->values()->all();
        if ($candidati->count() > $da + self::PER_PAGINA) {
            $righe[] = [$this->tasto('Altri ▶', "asl:$id:$tipo:".($da + self::PER_PAGINA))];
        }
        $righe[] = [$this->tasto('⬅️ Indietro', "asg:$id")];

        $titolo = ['o' => 'Scegli l\'operaio', 's' => 'Scegli la squadra', 'd' => 'Scegli la ditta (✓ = copre la tipologia)'][$tipo];
        $this->telegram->sendMessage($user->telegram_chat_id, $titolo.' per #'.$id.':', $righe);

        return ['risposta' => '', 'apri' => null];
    }

    /** @return array{risposta: string, apri: ?int} */
    private function assegna(Segnalazione $s, User $user, string $tipo, int $chi): array
    {
        if (! $this->candidati($s, $tipo)->contains('id', $chi)) {
            return ['risposta' => 'Scelta non valida.', 'apri' => null];
        }

        return match ($tipo) {
            'o' => $this->esegui($s, $user, 'assegna_operatore', ['id_operatore' => $chi]),
            's' => $this->esegui($s, $user, 'assegna_operatore', ['id_squadra' => $chi]),
            'd' => $this->esegui($s, $user, 'assegna_impresa', ['id_appalto' => $chi]),
            default => ['risposta' => 'Scelta non valida.', 'apri' => null],
        };
    }

    /**
     * Stessi elenchi del pannello Assegna della scheda: operai prima, ditte che coprono la tipologia prima.
     *
     * @return Collection<int, array{id: int, nome: string}>
     */
    private function candidati(Segnalazione $s, string $tipo): Collection
    {
        return match ($tipo) {
            'o' => User::assegnabili()->with('roles')->orderBy('name')->get()
                ->sortBy(fn (User $u) => $u->hasRole('operaio') ? 0 : 1, SORT_REGULAR)
                ->map(fn (User $u) => ['id' => (int) $u->id, 'nome' => (string) $u->name])->values(),
            's' => Impostazione::get('squadre_enabled', false)
                ? Squadra::where('attiva', true)->orderBy('nome')->get()
                    ->map(fn (Squadra $q) => ['id' => (int) $q->id_squadra, 'nome' => (string) $q->nome])->values()
                : collect(),
            'd' => (function () use ($s) {
                $ordinati = AppaltiPerTipologia::ordina(
                    Appalto::where('valido', true)->with(['impresa', 'tipologie'])->orderBy('descrizione')->get(),
                    [(int) $s->id_tipologia_segnalazione],
                );

                return $ordinati['coprono']->map(fn (Appalto $a) => ['id' => (int) $a->id_appalto, 'nome' => '✓ '.$this->nomeAppalto($a)])
                    ->concat($ordinati['altri']->map(fn (Appalto $a) => ['id' => (int) $a->id_appalto, 'nome' => $this->nomeAppalto($a)]))
                    ->values();
            })(),
            default => collect(),
        };
    }

    private function nomeAppalto(Appalto $a): string
    {
        return ($a->impresa->ragione_sociale ?? '—').' · '.$a->descrizione;
    }

    /** @return array{risposta: string, apri: ?int} */
    private function menuSenzaIntervento(Segnalazione $s, User $user): array
    {
        if (! $this->disponibili($s, $user)->has('annulla')) {
            return ['risposta' => 'Azione non disponibile ora.', 'apri' => $s->id_segnalazione];
        }
        $id = $s->id_segnalazione;
        $righe = collect(SegnalazioneWorkflowService::MOTIVI_MANUALI)
            ->map(fn (string $etichetta, string $codice) => [$this->tasto($etichetta, "sim:$id:$codice")])
            ->values()->all();
        $righe[] = [$this->tasto('⬅️ Torna a #'.$id, "apri:$id")];

        $this->telegram->sendMessage($user->telegram_chat_id, 'Perché chiudi #'.$id.' senza intervento? Chi ha segnalato vedrà il motivo.', $righe);

        return ['risposta' => '', 'apri' => null];
    }

    /** @return array{risposta: string, apri: ?int} */
    private function senzaIntervento(Segnalazione $s, User $user, string $motivo): array
    {
        if (! array_key_exists($motivo, SegnalazioneWorkflowService::MOTIVI_MANUALI)) {
            return ['risposta' => 'Motivo non valido.', 'apri' => null];
        }
        if ($motivo === 'altro') {
            return $this->chiediTesto($s, $user, 'alt');
        }

        return $this->esegui($s, $user, 'annulla', ['motivo' => $motivo]);
    }

    /** @return array{risposta: string, apri: ?int} */
    private function sollecita(Segnalazione $s, User $user): array
    {
        if (Gate::forUser($user)->denies('sollecita', $s)) {
            return ['risposta' => 'Sollecito non disponibile ora.', 'apri' => null];
        }
        app(Sollecito::class)->invia($s, $user);

        return ['risposta' => '🔔 Sollecito inviato.', 'apri' => null];
    }

    /** @return array{risposta: string, apri: ?int} */
    private function chiediTesto(Segnalazione $s, User $user, string $tipo): array
    {
        $ammesso = match ($tipo) {
            'nota' => ! $s->isChiusa(),
            'fl' => Gate::forUser($user)->allows('comunicaFineLavori', $s) && ! $s->haFineLavori(),
            'rim' => Gate::forUser($user)->allows('rimanda', $s),
            'rp' => $this->disponibili($s, $user)->has('rifiuta_preventivo'),
            'alt' => $this->disponibili($s, $user)->has('annulla'),
            'chi' => $this->disponibili($s, $user)->has('chiudi'),
            'prev' => $this->disponibili($s, $user)->has('presenta_preventivo'),
            'foto' => ! $s->isChiusa(),
            default => false,
        };
        if (! $ammesso) {
            return ['risposta' => 'Azione non disponibile ora.', 'apri' => null];
        }

        Cache::put($this->chiaveAttesa($user), ['tipo' => $tipo, 'id' => $s->id_segnalazione], now()->addMinutes(30));
        $id = $s->id_segnalazione;
        $pulsanti = match ($tipo) {
            'chi' => [[$this->tasto('✅ Chiudi ora', "chn:$id"), $this->tasto('✖️ Annulla', 'ann')]],
            'foto' => [[$this->tasto('✅ Fatto', "apri:$id")]],
            default => [[$this->tasto('✖️ Annulla', 'ann')]],
        };
        $this->telegram->sendMessage($user->telegram_chat_id, sprintf(self::RICHIESTE_TESTO[$tipo], $id), $pulsanti);

        return ['risposta' => '', 'apri' => null];
    }

    /** Chiusura del comune: nota facoltativa (le foto sono già arrivate durante l'attesa). */
    private function chiudi(Segnalazione $s, User $user, string $nota): array
    {
        Cache::forget($this->chiaveAttesa($user));

        return $this->esegui($s, $user, 'chiudi', $nota !== '' ? ['nota' => $nota] : []);
    }

    /** Preventivo della ditta: importo in testa («1.250,50 …» o «1250.50 …»), il resto è la descrizione. */
    private function preventivo(Segnalazione $s, User $user, string $testo): array
    {
        if (preg_match('/^\s*(?:€\s*)?(\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)\s*(?:€|euro)?\s*(.*)$/isu', $testo, $m) !== 1) {
            Cache::put($this->chiaveAttesa($user), ['tipo' => 'prev', 'id' => $s->id_segnalazione], now()->addMinutes(30));
            $this->telegram->sendMessage($user->telegram_chat_id, 'Manca l\'importo: scrivilo all\'inizio, ad esempio «1250,50 sostituzione caldaia».',
                [[$this->tasto('✖️ Annulla', 'ann')]]);

            return ['risposta' => '', 'apri' => null];
        }
        // «1250.50» (un punto e 1-2 decimali) è decimale; altrimenti il punto separa le migliaia («1.250,50», «1.250»).
        $importo = preg_match('/^\d+\.\d{1,2}$/', $m[1]) === 1
            ? (float) $m[1]
            : (float) str_replace(',', '.', str_replace('.', '', $m[1]));
        $descrizione = trim($m[2]);

        return $this->esegui($s, $user, 'presenta_preventivo', array_filter([
            'importo_preventivo' => $importo,
            'nota' => $descrizione !== '' ? 'Preventivo: '.$descrizione : null,
        ], fn ($v) => $v !== null));
    }

    /** @return array{risposta: string, apri: ?int} */
    private function menuSmista(Segnalazione $s, User $user): array
    {
        $servizio = app(SmistaSquadra::class);
        $squadra = $servizio->squadraDiCapo($s, $user);
        if ($squadra === null) {
            return ['risposta' => 'Solo il caposquadra può smistare.', 'apri' => null];
        }
        $id = $s->id_segnalazione;
        $righe = $servizio->membri($squadra)
            ->map(fn (User $u) => [$this->tasto(((int) $u->id === (int) $s->id_operatore_assegnato ? '✓ ' : '').$u->name, "sms:$id:{$u->id}")])
            ->values()->all();
        $righe[] = [$this->tasto('⬅️ Torna a #'.$id, "apri:$id")];
        $this->telegram->sendMessage($user->telegram_chat_id, 'A chi della squadra '.$squadra->nome.' passi #'.$id.'?', $righe);

        return ['risposta' => '', 'apri' => null];
    }

    /** @return array{risposta: string, apri: ?int} */
    private function smista(Segnalazione $s, User $user, int $idMembro): array
    {
        $servizio = app(SmistaSquadra::class);
        $squadra = $servizio->squadraDiCapo($s, $user);
        if ($squadra === null) {
            return ['risposta' => 'Solo il caposquadra può smistare.', 'apri' => null];
        }
        $servizio->smista($s, $squadra, $user, $idMembro);

        return ['risposta' => '👥 Lavoro passato.', 'apri' => $s->id_segnalazione];
    }

    /** @return array{risposta: string, apri: ?int} */
    private function nota(Segnalazione $s, User $user, string $testo): array
    {
        if ($testo === '' || $s->isChiusa()) {
            return ['risposta' => 'Nota non salvata.', 'apri' => $s->id_segnalazione];
        }
        $s->note()->create([
            'testo' => $testo,
            'id_utente' => $user->id,
            'visibile_web' => false,
            'visibile_impresa' => $user->hasRole('impresa'),
        ]);

        return ['risposta' => '📝 Nota aggiunta a #'.$s->id_segnalazione.'.', 'apri' => $s->id_segnalazione];
    }

    /** @return array{risposta: string, apri: ?int} */
    private function fineLavori(Segnalazione $s, User $user, string $testo): array
    {
        if ($testo === '' || Gate::forUser($user)->denies('comunicaFineLavori', $s) || $s->haFineLavori()) {
            return ['risposta' => 'Fine lavori non disponibile ora.', 'apri' => $s->id_segnalazione];
        }
        $chiusa = app(FineLavoriService::class)->comunica($s, $user, ['nota' => $testo], []);

        return ['risposta' => $chiusa ? '🏁 Lavoro chiuso. Grazie!' : '🏁 Fine lavori comunicata al comune.', 'apri' => $s->id_segnalazione];
    }

    /** @return array{risposta: string, apri: ?int} */
    private function rimanda(Segnalazione $s, User $user, string $testo): array
    {
        if ($testo === '' || Gate::forUser($user)->denies('rimanda', $s)) {
            return ['risposta' => 'Non è possibile rimandarla ora.', 'apri' => $s->id_segnalazione];
        }
        $this->workflow->rimandaAlGestore($s, $user, $testo);

        return ['risposta' => '↩️ #'.$s->id_segnalazione.' rimandata al comune.', 'apri' => null];
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /**
     * Azioni del workflow disponibili, per codice. Solo per chi può aggiornarla (come la scheda).
     *
     * @return Collection<string, Azione>
     */
    private function disponibili(Segnalazione $s, User $user): Collection
    {
        if (Gate::forUser($user)->denies('update', $s)) {
            return collect();
        }

        return $this->workflow->getAzioniDisponibili($s, $user)->keyBy('codice');
    }

    private function giaInCaricoA(Segnalazione $s, User $user): bool
    {
        return $s->statoEnum() === SegnalazioneStato::IN_CARICO && (int) $s->id_operatore_assegnato === (int) $user->id;
    }

    /** @return array{text: string, callback_data: string} */
    private function tasto(string $testo, string $dati): array
    {
        return ['text' => $testo, 'callback_data' => $dati];
    }

    private function chiaveAttesa(User $user): string
    {
        return 'telegram:attesa:'.$user->id;
    }
}
