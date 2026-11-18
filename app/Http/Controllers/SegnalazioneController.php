<?php

namespace App\Http\Controllers;

use App\Enums\SegnalazioneStato;
use App\Models\AdesioneSegnalazione;
use App\Models\AllegatoSegnalazione;
use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\NotaSegnalazione;
use App\Models\Delega;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\StoricoStatoSegnalazione;
use App\Notifications\NotaDaEsternoNotification;
use App\Notifications\NuovaNotaSegnalazione;
use App\Notifications\SollecitoNotification;
use App\Services\Segnalazioni\AccorpamentoService;
use App\Services\Segnalazioni\DestinatariAssegnatario;
use App\Services\Segnalazioni\DestinatariComune;
use App\Services\Segnalazioni\FineLavoriService;
use App\Models\Appalto;
use App\Support\Segnalazioni\SediSegnalabili;
use App\Support\Segnalazioni\AppaltiPerTipologia;
use App\Models\Squadra;
use App\Support\Segnalazioni\Attivita;
use App\Support\Segnalazioni\CaricaAllegati;
use App\Support\Segnalazioni\Giorni;
use Illuminate\Validation\ValidationException;
use App\Support\Segnalazioni\ProssimoPasso;
use App\Support\Segnalazioni\LetturaScheda;
use App\Jobs\CalcolaEmbeddingSegnalazione;
use App\Jobs\GeneraTitoloSegnalazione;
use App\Jobs\SuggerisciTriageSegnalazione;
use App\Services\DedupService;
use App\Services\SlaService;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class SegnalazioneController extends Controller
{
    public function __construct(
        private readonly SegnalazioneWorkflowService $workflow,
        private readonly SlaService $sla,
        private readonly DedupService $dedup,
    ) {}

    // ── Lista (segnalatore: solo proprie) ─────────────────────────────────────

    public function index(Request $request): View
    {
        $user = auth()->user();
        $segnalazioni = Segnalazione::visibileA($user)
            ->with(['tipologia', 'plesso'])
            ->withMax('storicoStati', 'data_registrazione')
            ->aperte()
            ->orderByDesc('data_segnalazione')
            ->paginate(20);
        $seguite = Segnalazione::idSeguiteDa($user);

        return view('segnalazioni.index', compact('segnalazioni', 'seguite'));
    }

    // ── Form creazione ────────────────────────────────────────────────────────

    public function create(): View
    {
        $user = auth()->user();

        $tipologie = TipologiaSegnalazione::attive()->orderBy('descrizione')->get();
        $plessi    = SediSegnalabili::per($user);

        // Tempo previsto per tipologia (0 = regola valida per tutte) e priorità.
        $tempiPrevisti = SlaConfigurazione::all()
            ->groupBy(fn (SlaConfigurazione $r) => (int) $r->id_tipologia_segnalazione)
            ->map(fn ($regole) => $regole->mapWithKeys(fn (SlaConfigurazione $r) => [$r->livello_priorita => $r->descrizioneTempo()]));

        return view('segnalazioni.create', compact('tipologie', 'plessi', 'tempiPrevisti'));
    }

    // ── Salva nuova segnalazione ───────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $maxSizeMb      = (int) Impostazione::get('allegati_max_size_mb', 10);
        $maxPerRequest  = (int) Impostazione::get('allegati_max_per_request', 5);
        $mimeConsentiti = array_filter(
            array_map('trim', explode(',', Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png')))
        );

        // 1.0.0: la sede è obbligatoria per tutti (scuola: sedi delle deleghe; enti attivi).
        $regolePlesso = ['required', 'integer', Rule::in(SediSegnalabili::per($request->user())->pluck('id_plesso')->all())];

        $data = $request->validate([
            'id_tipologia_segnalazione' => ['required', 'integer', Rule::exists('tipologie_segnalazioni', 'id_tipologia_segnalazione')->where('attiva', true)],
            'titolo'                    => ['required', 'string', 'max:100'],
            'testo_segnalazione'        => ['required', 'string', 'max:2000'],
            'id_plesso'                 => $regolePlesso,
            'livello_priorita'          => ['nullable', 'integer', 'between:1,4'],
            'ubicazione_tipo'           => ['nullable', 'integer', 'between:0,4'],
            'allegati'                  => ['nullable', 'array', 'max:' . $maxPerRequest],
            'allegati.*'                => [
                'file',
                'max:' . ($maxSizeMb * 1024),
                'mimetypes:' . implode(',', $mimeConsentiti),
            ],
        ], [
            'id_plesso.required'           => 'Scegli la sede.',
            'id_plesso.in'                 => 'Scegli una delle sedi in elenco.',
            'id_tipologia_segnalazione.required' => 'Scegli la tipologia.',
            'id_tipologia_segnalazione.exists'   => 'Scegli una delle tipologie in elenco.',
            'titolo.required'              => 'Scrivi un titolo breve.',
            'titolo.max'                   => 'Il titolo può avere al massimo 100 caratteri.',
        ]);

        $user = auth()->user();

        // Già aperta per la stessa sede e tipologia: prima si propone di scrivere lì (1.0.2).
        // Chi ha un problema diverso lo dichiara con `diversa_da`; la nuova resta collegata.
        $esistente = $this->dedup->apertaPerSedeETipologia($user, (int) $data['id_plesso'], (int) $data['id_tipologia_segnalazione']);
        if ($esistente !== null && (int) $request->input('diversa_da') !== $esistente->id_segnalazione) {
            throw ValidationException::withMessages([
                'esistente' => "C'è già la segnalazione #{$esistente->id_segnalazione} aperta per questa sede e tipologia.",
            ]);
        }
        $collegata = $esistente
            ?? $this->dedup->apertaPerSedeETipologia($user, (int) $data['id_plesso'], (int) $data['id_tipologia_segnalazione'], ancheConPreventivo: true);
        if ($collegata !== null) {
            $data['id_segnalazione_correlata'] = $collegata->id_segnalazione;
        }

        $segnalazione = Segnalazione::create(array_merge(
            Arr::except($data, ['allegati']),
            [
                'id_utente_segnalazione' => $user->id,
                'id_stato_segnalazione'  => SegnalazioneStato::NUOVA->value,
                'livello_priorita'       => $data['livello_priorita'] ?? 2,
                'ubicazione_tipo'        => $data['ubicazione_tipo'] ?? 0,
            ]
        ));

        // Calcola scadenza SLA se configurata
        $scadenzaSla = $this->sla->calcolaScadenza($segnalazione);
        if ($scadenzaSla) {
            $segnalazione->update(['data_scadenza_sla' => $scadenzaSla]);
        }

        // Allegati opzionali
        if ($request->hasFile('allegati')) {
            $disk = Impostazione::get('allegati_storage_disk', 'local');

            foreach ($request->file('allegati') as $file) {
                $ext      = $file->getClientOriginalExtension();
                $filename = Str::uuid() . ($ext ? '.' . $ext : '');
                $path     = $file->storeAs(
                    'allegati/' . $segnalazione->id_segnalazione,
                    $filename,
                    $disk
                );
                AllegatoSegnalazione::create([
                    'id_segnalazione'     => $segnalazione->id_segnalazione,
                    'percorso'            => $path,
                    'tipo'                => $file->getMimeType(),
                    'nome_originale'      => $file->getClientOriginalName(),
                    'dimensione'          => $file->getSize(),
                    'id_utente_creazione' => $user->id,
                ]);
            }
        }

        if (Impostazione::get('ai_enabled', false)) {
            if (blank($segnalazione->titolo)) {
                GeneraTitoloSegnalazione::dispatch($segnalazione->id_segnalazione);
            }
            SuggerisciTriageSegnalazione::dispatch($segnalazione->id_segnalazione);
            CalcolaEmbeddingSegnalazione::dispatch($segnalazione->id_segnalazione);
        }

        return redirect()->route('segnalazioni.index')
            ->with('success', 'Segnalazione inviata con successo.');
    }

    // ── Dettaglio ─────────────────────────────────────────────────────────────

    /**
     * Anteprima in sola lettura per gli elenchi (1.0.6): frammento HTML senza layout, stessi filtri
     * della scheda (note e storia da Attivita, nome di chi ha segnalato solo al comune).
     */
    public function anteprima(Segnalazione $segnalazione): View
    {
        $this->authorize('view', $segnalazione);

        $segnalazione->load(['tipologia', 'plesso', 'utente', 'operatore', 'squadraAssegnata', 'appalto.impresa', 'allegati']);
        $utente = auth()->user();

        return view('segnalazioni._anteprima', [
            'segnalazione' => $segnalazione,
            'lettura' => LetturaScheda::per($utente),
            'ultima' => Attivita::per($segnalazione, $utente)->first(),
        ]);
    }

    public function show(Segnalazione $segnalazione): View
    {
        $this->authorize('view', $segnalazione);

        $segnalazione->load([
            'stato', 'tipologia', 'plesso.istituto',
            'operatore', 'utente', 'appalto',
            'squadraAssegnata',
            'segnalazioneMadre', 'duplicati', 'segnalazioneCorrelata', 'correlate',
            'note' => fn ($q) => $q->visibiliA(auth()->user()),
            'note.autore',
            'storicoStati.stato', 'storicoStati.utente',
            'allegati.utenteCreazione',
            'adesioni.utente',
        ]);

        $utente = auth()->user();
        $lettura = LetturaScheda::per($utente);
        $azioniDisponibili = $utente->can('update', $segnalazione)
            ? $this->workflow->getAzioniDisponibili($segnalazione, $utente)
            : collect();
        $prossimoPasso = ProssimoPasso::per($segnalazione, $utente);
        $attivita = Attivita::per($segnalazione, $utente);
        $fatti = $lettura === 'gestore' ? \App\Support\Segnalazioni\Fatti::per($segnalazione) : null;
        $cronogramma = \App\Support\Segnalazioni\Cronogramma::per($segnalazione, $utente);
        $storia = \App\Support\Segnalazioni\Storia::giorni($attivita);

        $fotoPrima = $segnalazione->allegati->where('fase', 'prima');
        $fotoDopo = $segnalazione->allegati->where('fase', 'dopo');

        // Dati dei pannelli di assegnazione: servono solo alla lettura del comune.
        $operatori = collect();
        $squadre = collect();
        $appaltiValidi = collect();
        if ($lettura === 'gestore') {
            $operatori = User::assegnabili()->orderBy('name')->get();
            if (Impostazione::get('squadre_enabled', false)) {
                $squadre = Squadra::where('attiva', true)->orderBy('nome')->get();
            }
            // Prima le ditte che coprono la tipologia, poi le altre (1.0.0).
            $ordinati = AppaltiPerTipologia::ordina(
                Appalto::where('valido', true)->with(['impresa', 'tipologie'])->orderBy('descrizione')->get(),
                [(int) $segnalazione->id_tipologia_segnalazione],
            );
            $appaltiValidi = $ordinati['coprono']->each(fn ($a) => $a->setAttribute('copre', true))
                ->concat($ordinati['altri']->each(fn ($a) => $a->setAttribute('copre', false)));
        }

        return view('segnalazioni.show', compact(
            'segnalazione', 'lettura', 'prossimoPasso', 'attivita', 'storia', 'cronogramma', 'fatti', 'azioniDisponibili',
            'operatori', 'squadre', 'appaltiValidi', 'fotoPrima', 'fotoDopo',
        ));
    }

    // ── Esegui azione workflow ────────────────────────────────────────────────

    public function eseguiAzione(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('update', $segnalazione);

        $azioneId = (int) $request->input('id_azione');
        $azione = Azione::findOrFail($azioneId);

        $rules = [
            'id_azione'                 => ['required', 'integer', 'exists:db_azioni,id_azione'],
            'id_operatore'              => ['nullable', ...\App\Support\Segnalazioni\RegoleAssegnazione::operatore()],
            'id_appalto'                => ['nullable', 'integer', 'exists:appalti,id_appalto'],
            'id_squadra'                => ['nullable', ...\App\Support\Segnalazioni\RegoleAssegnazione::squadra()],
            'nota'                      => ['nullable', 'string', 'max:2000'],
            'id_segnalazione_correlata' => ['nullable', 'integer', 'exists:segnalazioni,id_segnalazione'],
            'livello_priorita'          => ['nullable', 'integer', 'between:1,4'],
            'data_scadenza'             => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];

        // Il pannello Assegna fissa anche la priorità: permesso controllato prima,
        // dopo l'assegnazione un gestore non supervisore potrebbe non averlo più.
        $cambiaPriorita = $request->filled('livello_priorita');
        if ($cambiaPriorita) {
            $this->authorize('modificaPriorita', $segnalazione);
        }

        if ($azione->flag_preventivo) {
            $rules['importo_preventivo'] = ['required', 'numeric', 'min:0'];
        }

        // Chiudere non chiede nulla: nota e foto facoltative (spec 2026-11-13 §1).
        if ($azione->codice === 'chiudi') {
            $rules += CaricaAllegati::regole(obbligatori: false);
        }

        if (in_array($azione->codice, ['sospendi', 'rifiuta_preventivo'], true)) {
            $rules['nota'] = ['required', 'string', 'max:2000'];
        }

        if ($azione->codice === 'annulla') {
            $rules['motivo'] = ['required', Rule::in(array_keys(SegnalazioneWorkflowService::MOTIVI_MANUALI))];
            $rules['nota'] = ['required_if:motivo,altro', 'nullable', 'string', 'max:2000'];
        }

        $data = $request->validate($rules, [
            ...CaricaAllegati::messaggi(),
            'motivo.required' => 'Indica perché si chiude senza intervento.',
            'nota.required_if' => 'Scrivi il motivo.',
            'nota.required' => 'Scrivi il motivo.',
            'data_scadenza.after_or_equal' => 'La nuova scadenza non può essere nel passato.',
        ]);

        $this->workflow->eseguiAzione(
            $segnalazione,
            $azioneId,
            auth()->user(),
            $data
        );

        if ($cambiaPriorita) {
            $this->applicaPriorita($segnalazione->refresh(), (int) $data['livello_priorita']);
        }

        if ($request->hasFile('allegati') && $azione->codice === 'chiudi') {
            CaricaAllegati::salva($segnalazione, $request->file('allegati'), auth()->user(), 'dopo');
        }

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Azione eseguita.');
    }

    // ── Fine lavori (ditta, operaio) ──────────────────────────────────────────

    public function fineLavori(Request $request, Segnalazione $segnalazione, FineLavoriService $servizio): RedirectResponse
    {
        $this->authorize('comunicaFineLavori', $segnalazione);

        $data = $request->validate([
            'ore_lavoro' => ['nullable', 'numeric', 'min:0'],
            'materiali'  => ['nullable', 'string', 'max:1000'],
            'nota'       => ['required', 'string', 'max:2000'],
            ...CaricaAllegati::regole(obbligatori: false),
        ], CaricaAllegati::messaggi());

        $chiusa = $servizio->comunica($segnalazione, auth()->user(), $data, $request->file('allegati') ?? []);

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', $chiusa ? 'Lavoro chiuso. Grazie!' : 'Fine lavori comunicata al comune.');
    }

    // ── Rimanda al gestore (operaio, caposquadra, ditta) ──────────────────────

    public function rimanda(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('rimanda', $segnalazione);

        $data = $request->validate(['nota' => ['required', 'string', 'max:2000']], [
            'nota.required' => 'Scrivi perché lo rimandi al comune.',
        ]);

        $this->workflow->rimandaAlGestore($segnalazione, $request->user(), trim($data['nota']));

        return redirect()->route('dashboard')
            ->with('success', "Segnalazione #{$segnalazione->id_segnalazione} rimandata al comune.");
    }

    public function chiediInformazioni(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('chiediInformazioni', $segnalazione);

        $data = $request->validate([
            'testo' => ['required', 'string', 'max:2000'],
            'giorni' => ['required', 'integer', 'min:1', 'max:30'],
            'chiudi_senza_risposta' => ['nullable', 'boolean'],
        ], [
            'testo.required' => 'Scrivi cosa ti serve sapere.',
            'giorni.*' => 'Indica da 1 a 30 giorni lavorativi.',
        ]);

        app(\App\Services\Segnalazioni\RichiesteInformazioni::class)->chiedi(
            $segnalazione, $request->user(), trim($data['testo']), (int) $data['giorni'], (bool) ($data['chiudi_senza_risposta'] ?? false),
        );

        return redirect()->route('segnalazioni.show', $segnalazione)->with('success', 'Richiesta inviata a chi ha segnalato.');
    }

    public function ritiraInformazioni(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('ritiraInformazioni', $segnalazione);

        app(\App\Services\Segnalazioni\RichiesteInformazioni::class)->ritira($segnalazione, $request->user());

        return redirect()->route('segnalazioni.show', $segnalazione)->with('success', 'Richiesta ritirata: la segnalazione è tornata dov\'era.');
    }

    // ── Aggiungi nota ─────────────────────────────────────────────────────────

    public function aggiungiNota(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('view', $segnalazione);

        $data = $request->validate([
            'testo'            => ['required', 'string', 'max:2000'],
            'visibile_web'     => ['boolean'],
            'visibile_impresa' => ['boolean'],
            ...CaricaAllegati::regole(),
        ], CaricaAllegati::messaggi());

        // Solo il comune decide la visibilità: la ditta parla al comune (e ai suoi),
        // il segnalante al comune, l'operaio lascia note interne.
        $utente = auth()->user();
        [$web, $impresa] = match (true) {
            $utente->hasRole('impresa')                  => [false, true],
            LetturaScheda::per($utente) === 'segnalante' => [true, false],
            LetturaScheda::per($utente) === 'lavoro'     => [false, false],
            default => [(bool) ($data['visibile_web'] ?? false), (bool) ($data['visibile_impresa'] ?? false)],
        };

        $nota = $segnalazione->note()->create([
            'testo'            => $data['testo'],
            'id_utente'        => $utente->id,
            'visibile_web'     => $web,
            'visibile_impresa' => $impresa,
        ]);

        if ($request->hasFile('allegati')) {
            CaricaAllegati::salva($segnalazione, $request->file('allegati'), $utente);
        }

        $this->notificaNota($segnalazione, $nota);

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Nota aggiunta.')
            ->withFragment('note');
    }

    // ── Sollecito a chi ha il lavoro ──────────────────────────────────────────

    public function sollecita(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('sollecita', $segnalazione);

        $data = $request->validate(['testo' => ['nullable', 'string', 'max:2000']]);
        app(\App\Services\Segnalazioni\Sollecito::class)->invia($segnalazione, $request->user(), (string) ($data['testo'] ?? ''));

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Sollecito inviato.');
    }

    /** Chi ha aperto sollecita chi ha il lavoro (o il comune, se non è ancora affidata). */
    public function sollecitoSegnalante(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('sollecitaComeSegnalante', $segnalazione);

        $data = $request->validate(['testo' => ['nullable', 'string', 'max:2000']]);
        $testo = 'Sollecito: '.(trim((string) ($data['testo'] ?? '')) ?: "il problema c'è ancora, chiedo un aggiornamento.");
        $utente = $request->user();

        $segnalazione->note()->create([
            'testo'            => $testo,
            'id_utente'        => $utente->id,
            'visibile_web'     => true,
            'visibile_impresa' => (bool) $segnalazione->id_appalto,
            'origine'          => \App\Models\NotaSegnalazione::ORIGINE_SOLLECITO,
        ]);

        $this->avvisaChiLaSegue($segnalazione, $utente, new SollecitoNotification($segnalazione, $testo, dalSegnalante: true));

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Sollecito inviato.');
    }

    /**
     * Dal form di nuova segnalazione, sulla già aperta per la stessa sede e tipologia:
     * senza testo né foto è un sollecito, altrimenti un'integrazione. Chi non l'ha aperta
     * ne diventa aderente (la segue e conta in "segnalata N volte").
     */
    public function aggiornamento(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('aggiornaDalForm', $segnalazione);

        $data = $request->validate(['testo' => ['nullable', 'string', 'max:2000']] + CaricaAllegati::regole(), CaricaAllegati::messaggi());
        $utente = $request->user();
        $testo = trim((string) ($data['testo'] ?? ''));
        $foto = $request->file('allegati', []);

        if ($segnalazione->richiestaAperta()->exists() && LetturaScheda::per($utente) === 'segnalante') {
            return $this->rispostaInformazioni($segnalazione, $utente, $testo, $foto);
        }

        $sollecito = $testo === '' && $foto === [];
        $nota = DB::transaction(function () use ($segnalazione, $utente, $testo, $foto, $sollecito) {
            if ((int) $segnalazione->id_utente_segnalazione !== $utente->id) {
                AdesioneSegnalazione::firstOrCreate(['id_segnalazione' => $segnalazione->id_segnalazione, 'id_utente' => $utente->id]);
            }
            if ($foto !== []) {
                CaricaAllegati::salva($segnalazione, $foto, $utente);
            }

            return $segnalazione->note()->create([
                'testo'            => $sollecito ? "Sollecito: il problema c'è ancora, chiedo un aggiornamento." : ($testo !== '' ? $testo : 'Ha aggiunto delle foto.'),
                'id_utente'        => $utente->id,
                'visibile_web'     => true,
                'visibile_impresa' => (bool) $segnalazione->id_appalto,
                'origine'          => $sollecito ? NotaSegnalazione::ORIGINE_SOLLECITO : NotaSegnalazione::ORIGINE_INTEGRAZIONE,
            ]);
        });

        $this->avvisaChiLaSegue($segnalazione, $utente, new SollecitoNotification($segnalazione, $nota->testo, dalSegnalante: true));

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', "Inviato alla #{$segnalazione->id_segnalazione}.");
    }

    /**
     * Risposta alla richiesta di informazioni dal riquadro: messaggio normale (non conta come
     * ripetizione né avvisa come sollecito), scritto prima delle foto così la richiesta lo aggancia.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $foto
     */
    private function rispostaInformazioni(Segnalazione $segnalazione, User $utente, string $testo, array $foto): RedirectResponse
    {
        if ($testo === '' && $foto === []) {
            return back()->withErrors(['testo' => 'Scrivi la risposta o aggiungi una foto.'])->withInput();
        }

        DB::transaction(function () use ($segnalazione, $utente, $testo, $foto) {
            if ((int) $segnalazione->id_utente_segnalazione !== $utente->id) {
                AdesioneSegnalazione::firstOrCreate(['id_segnalazione' => $segnalazione->id_segnalazione, 'id_utente' => $utente->id]);
            }
            $segnalazione->note()->create([
                'testo'            => $testo !== '' ? $testo : 'Ha aggiunto delle foto.',
                'id_utente'        => $utente->id,
                'visibile_web'     => true,
                'visibile_impresa' => (bool) $segnalazione->id_appalto,
            ]);
            if ($foto !== []) {
                CaricaAllegati::salva($segnalazione, $foto, $utente);
            }
        });

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Risposta inviata al comune.');
    }

    /** Chi ha il lavoro (delegati ditta, operaio, caposquadra) o, se nessuno, il comune. */
    private function avvisaChiLaSegue(Segnalazione $segnalazione, User $mittente, SollecitoNotification $notifica): void
    {
        $destinatari = DestinatariAssegnatario::per($segnalazione, $mittente);
        if ($destinatari['utenti']->isEmpty() && $destinatari['email'] === []) {
            $destinatari['utenti'] = DestinatariComune::per($segnalazione, $mittente);
        }
        if ($destinatari['utenti']->isNotEmpty()) {
            Notification::send($destinatari['utenti'], $notifica);
        }
        foreach ($destinatari['email'] as $email) {
            Notification::route('mail', $email)->notify($notifica);
        }
    }

    // ── Stampa scheda ────────────────────────────────────────────────────────

    public function stampa(Segnalazione $segnalazione): View
    {
        $this->authorize('view', $segnalazione);

        $segnalazione->load([
            'stato', 'tipologia', 'plesso.istituto',
            'operatore', 'utente', 'appalto.impresa',
            'note' => fn ($q) => $q->visibiliA(auth()->user()),
            'storicoStati.stato', 'storicoStati.utente',
        ]);

        return view('segnalazioni.print', compact('segnalazione'));
    }

    // ── Toggle evidenza ───────────────────────────────────────────────────────

    public function toggleEvidenza(Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('update', $segnalazione);

        $this->workflow->setEvidenza($segnalazione, ! $segnalazione->flag_evidenza);

        return back()->with('success', $segnalazione->flag_evidenza ? 'Rimossa da evidenza.' : 'Aggiunta in evidenza.');
    }

    // ── Priorità (decisa dal comune) ──────────────────────────────────────────

    /** Il comune corregge il titolo (stessa regola della priorità: non ditte né segnalanti). */
    public function aggiornaTitolo(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('modificaPriorita', $segnalazione);

        $data = $request->validate([
            'titolo' => ['required', 'string', 'max:100'],
        ], [
            'titolo.required' => 'Scrivi un titolo breve.',
            'titolo.max' => 'Il titolo può avere al massimo 100 caratteri.',
        ]);

        $segnalazione->update(['titolo' => trim($data['titolo'])]);

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Titolo aggiornato.');
    }

    /**
     * Tipologia sbagliata (1.0.4): la corregge il comune. Scadenza ricalcolata con la regola della
     * nuova tipologia (salvo scadenza a mano); l'observer avvisa i gestori della nuova tipologia.
     */
    public function aggiornaTipologia(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('modificaPriorita', $segnalazione);

        $data = $request->validate([
            'id_tipologia_segnalazione' => ['required', 'integer', \Illuminate\Validation\Rule::exists('tipologie_segnalazioni', 'id_tipologia_segnalazione')->where('attiva', true)],
        ], [
            'id_tipologia_segnalazione.exists' => 'Scegli una delle tipologie attive.',
        ]);

        $prima = $segnalazione->tipologia?->nomeLeggibile() ?? '—';
        $segnalazione->id_tipologia_segnalazione = (int) $data['id_tipologia_segnalazione'];
        if (! $segnalazione->isDirty('id_tipologia_segnalazione')) {
            return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione);
        }

        if (! $segnalazione->isChiusa() && ! $segnalazione->scadenza_manuale) {
            $scadenza = $this->sla->calcolaScadenza($segnalazione);
            if ($scadenza) {
                $segnalazione->data_scadenza_sla = $scadenza;
                $segnalazione->sla_violato = $scadenza->isPast();
            }
        }
        $segnalazione->save();
        $dopo = $segnalazione->load('tipologia')->tipologia?->nomeLeggibile() ?? '—';

        // Traccia interna, come per la priorità.
        $segnalazione->note()->create([
            'testo'            => "Tipologia cambiata: {$prima} → {$dopo}.",
            'id_utente'        => auth()->id(),
            'visibile_web'     => false,
            'visibile_impresa' => false,
        ]);

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', "Tipologia cambiata in {$dopo}.");
    }

    /**
     * Sede sbagliata (1.0.6): il comune la corregge, ma solo tra le sedi dello stesso istituto
     * (chi segnala sceglie un plesso e scrive di un altro). Nota interna come per la tipologia.
     */
    public function aggiornaSede(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('modificaPriorita', $segnalazione);

        $istituto = $segnalazione->plesso->id_istituto ?? -1;
        $data = $request->validate([
            'id_plesso' => ['required', 'integer', \Illuminate\Validation\Rule::exists('plessi', 'id_plesso')->where('id_istituto', $istituto)],
        ], [
            'id_plesso.exists' => 'Scegli una delle sedi dello stesso istituto.',
        ]);

        $prima = $segnalazione->plesso?->nomeLeggibile() ?? '—';
        $segnalazione->id_plesso = (int) $data['id_plesso'];
        if (! $segnalazione->isDirty('id_plesso')) {
            return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione);
        }
        $segnalazione->save();
        $dopo = $segnalazione->load('plesso')->plesso?->nomeLeggibile() ?? '—';

        $segnalazione->note()->create([
            'testo'            => "Sede cambiata: {$prima} → {$dopo}.",
            'id_utente'        => auth()->id(),
            'visibile_web'     => false,
            'visibile_impresa' => false,
        ]);

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', "Sede cambiata in {$dopo}.");
    }

    /** Toglie la scadenza fissata a mano: torna quella calcolata dalla regola (spec §3). */
    /** "Scadenza…": si rimanda con un motivo, che la scuola vede sempre (e riceve per email). */
    public function aggiornaScadenza(Request $request, Segnalazione $segnalazione, \App\Services\Segnalazioni\ScadenzaManuale $scadenze): RedirectResponse
    {
        $this->authorize('modificaPriorita', $segnalazione);
        abort_if($segnalazione->isChiusa(), 422, 'Segnalazione chiusa.');

        $dati = $request->validate([
            'data_scadenza' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'motivo' => ['required', 'string', 'max:1000'],
        ], [
            'data_scadenza.required' => 'Scegli la nuova scadenza.',
            'data_scadenza.after_or_equal' => 'La nuova scadenza non può essere nel passato.',
            'motivo.required' => 'Scrivi il motivo: lo vede anche chi ha segnalato.',
        ]);

        $nota = $scadenze->imposta($segnalazione, \Illuminate\Support\Carbon::parse($dati['data_scadenza']), $request->user(), $dati['motivo']);
        $this->notificaNota($segnalazione, $nota);

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Scadenza spostata al '.\Illuminate\Support\Carbon::parse($dati['data_scadenza'])->format('d/m/Y').'.');
    }

    public function scadenzaAutomatica(Segnalazione $segnalazione, \App\Services\Segnalazioni\ScadenzaManuale $scadenze): RedirectResponse
    {
        $this->authorize('modificaPriorita', $segnalazione);

        $scadenze->rimuovi($segnalazione);

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Scadenza di nuovo calcolata in automatico.');
    }

    public function aggiornaPriorita(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('modificaPriorita', $segnalazione);

        $data = $request->validate([
            'livello_priorita' => ['required', 'integer', 'between:1,4'],
        ]);

        if (! $this->applicaPriorita($segnalazione, (int) $data['livello_priorita'])) {
            return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione);
        }

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Priorità aggiornata.');
    }

    /** Cambia la priorità, ricalcola la scadenza SLA e lascia la traccia interna. False se non cambia nulla. */
    private function applicaPriorita(Segnalazione $segnalazione, int $livello): bool
    {
        $prima = $segnalazione->label_priorita;

        $segnalazione->livello_priorita = $livello;

        if (! $segnalazione->isDirty()) {
            return false;
        }

        // La scadenza SLA dipende dalla priorità: va ricalcolata sulle aperte.
        // Scadenza fissata a mano: la priorità cambia solo l'etichetta.
        if (! $segnalazione->isChiusa() && ! $segnalazione->scadenza_manuale) {
            $scadenza = $this->sla->calcolaScadenza($segnalazione);
            if ($scadenza) {
                $segnalazione->data_scadenza_sla = $scadenza;
                $segnalazione->sla_violato = $scadenza->isPast();
            }
        }

        $segnalazione->save();

        $dopo = $segnalazione->label_priorita;

        // Traccia interna: chi ha cambiato cosa (non visibile a ditta né sul web).
        $segnalazione->note()->create([
            'testo'            => "Priorità modificata: {$prima} → {$dopo}.",
            'id_utente'        => auth()->id(),
            'visibile_web'     => false,
            'visibile_impresa' => false,
        ]);

        return true;
    }

    // ── Toggle riservata ──────────────────────────────────────────────────────

    public function toggleRiservata(Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('update', $segnalazione);

        $segnalazione->update([
            'flag_riservata' => ! $segnalazione->flag_riservata,
        ]);

        Cache::forget('public.home.statistics');

        return back()->with('success', $segnalazione->flag_riservata ? 'Segnalazione esclusa dalle statistiche pubbliche.' : 'Segnalazione ora visibile nelle statistiche pubbliche.');
    }

    // ── Segnalazioni simili (anti-duplicato) ──────────────────────────────────

    public function simili(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_tipologia_segnalazione' => ['required', 'integer'],
            'id_plesso'                 => ['nullable', 'integer'],
            'testo'                     => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        $simili = $this->dedup->trovaSimili(
            (int) $data['id_tipologia_segnalazione'],
            isset($data['id_plesso']) ? (int) $data['id_plesso'] : null,
            $data['testo'] ?? null,
        );
        // Quella su cui scrivere ha il suo riquadro (segnalazioni.esistente): non ripeterla qui.
        $esistente = isset($data['id_plesso'])
            ? $this->dedup->apertaPerSedeETipologia($user, (int) $data['id_plesso'], (int) $data['id_tipologia_segnalazione'])
            : null;
        if ($esistente !== null) {
            $simili = $simili->reject(fn (Segnalazione $s) => $s->id_segnalazione === $esistente->id_segnalazione);
        }

        return response()->json($simili->map(function (Segnalazione $s) use ($user) {
            $puoVedere = $user->can('view', $s);

            return [
                'id'        => $s->id_segnalazione,
                'testo'     => $puoVedere ? Str::limit($s->testo_segnalazione, 120) : null,
                'stato'     => $s->stato?->descrizione,
                'data'      => $s->data_segnalazione?->format('d/m/Y'),
                'foto_url'  => ($puoVedere && $s->allegati->first())
                    ? route('segnalazioni.allegati.download', [$s, $s->allegati->first()])
                    : null,
                'adesioni'  => $s->adesioni->count(),
            ];
        })->values());
    }

    /** Segnalazione già aperta per sede e tipologia, per il riquadro "Esiste già" del form. */
    public function esistente(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_tipologia_segnalazione' => ['required', 'integer'],
            'id_plesso'                 => ['required', 'integer'],
        ]);
        $user = $request->user();

        $s = $this->dedup->apertaPerSedeETipologia($user, (int) $data['id_plesso'], (int) $data['id_tipologia_segnalazione']);
        if ($s === null) {
            return response()->json(['esistente' => null]);
        }

        // Ultima risposta dell'ufficio a chi ha segnalato (mai il nome di chi l'ha scritta).
        $risposta = $s->note()
            ->where('visibile_web', true)
            ->whereIn('id_utente', User::role(['admin', 'gestore', 'operaio', 'impresa'])->select('users.id'))
            ->reorder('data', 'desc')->first();

        return response()->json(['esistente' => [
            'id'              => $s->id_segnalazione,
            'titolo'          => $s->titoloLeggibile(),
            'stato'           => $s->statoEnum()->fraseSegnalante()['titolo'],
            'aperta_da'       => Giorni::fa($s->data_segnalazione),
            'tua'             => (int) $s->id_utente_segnalazione === $user->id,
            'ultima_risposta' => $risposta ? [
                'quando' => Giorni::breve($risposta->data),
                'testo'  => Str::limit($risposta->testo, 120),
            ] : null,
        ]]);
    }

    // ── Accorpa a una segnalazione già aperta sullo stesso guasto ─────────────

    public function accorpa(Request $request, Segnalazione $segnalazione, AccorpamentoService $servizio): RedirectResponse
    {
        $this->authorize('accorpa', $segnalazione);

        $data = $request->validate([
            'id_destinazione' => ['required', 'integer', 'exists:segnalazioni,id_segnalazione'],
        ]);

        $destinazione = $servizio->destinazioneEffettiva(Segnalazione::findOrFail($data['id_destinazione']));
        // Chi accorpa deve poter gestire anche la segnalazione che riceve.
        $this->authorize('modificaPriorita', $destinazione);

        $destinazione = $servizio->accorpa($segnalazione, $destinazione, $request->user());

        return redirect()
            ->route('segnalazioni.show', $destinazione->id_segnalazione)
            ->with('success', "Segnalazione #{$segnalazione->id_segnalazione} accorpata alla #{$destinazione->id_segnalazione}.");
    }

    /** Candidati per l'accorpamento: simili per luogo e tipologia, o ricerca per numero/testo. */
    public function candidatiAccorpa(Request $request, Segnalazione $segnalazione, DedupService $dedup): JsonResponse
    {
        $this->authorize('accorpa', $segnalazione);

        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            $candidati = $dedup->trovaSimili(
                (int) $segnalazione->id_tipologia_segnalazione,
                $segnalazione->id_plesso ? (int) $segnalazione->id_plesso : null,
                $segnalazione->testo_segnalazione,
                9,
                soloSeAttivo: false,
            );
            // I simili sono cercati su tutte le aperte: si propone solo ciò che chi accorpa può vedere.
            $visibili = Segnalazione::visibileA($request->user())
                ->whereKey($candidati->pluck('id_segnalazione'))->pluck('id_segnalazione')->all();
            $candidati = $candidati->whereIn('id_segnalazione', $visibili);
        } else {
            $candidati = Segnalazione::visibileA($request->user())
                ->aperte()
                ->where(fn ($w) => ctype_digit($q)
                    ? $w->where('id_segnalazione', (int) $q)
                    : $w->where('testo_segnalazione', 'like', '%'.$q.'%')->orWhere('titolo', 'like', '%'.$q.'%'))
                ->with(['stato', 'plesso'])
                ->orderByDesc('data_segnalazione')
                ->limit(10)
                ->get();
        }

        return response()->json(self::candidatiJson($candidati, $segnalazione));
    }

    /** Candidati per "Collega a precedente": riparazioni chiuse negli ultimi 24 mesi, stessa sede e tipologia. */
    public function candidatiCollega(Request $request, Segnalazione $segnalazione): JsonResponse
    {
        $this->authorize('update', $segnalazione);

        $q = trim((string) $request->query('q', ''));
        $query = Segnalazione::visibileA($request->user())
            ->whereNotNull('data_chiusura')
            ->where('id_stato_segnalazione', SegnalazioneStato::COMPLETATA->value)
            ->with(['stato', 'plesso']);

        if ($q === '') {
            $query->where('id_tipologia_segnalazione', $segnalazione->id_tipologia_segnalazione)
                ->where('data_chiusura', '>=', now()->subMonths(24));
            if ($segnalazione->id_plesso) {
                $query->where('id_plesso', $segnalazione->id_plesso);
            }
        } else {
            $query->where(fn ($w) => ctype_digit($q)
                ? $w->where('id_segnalazione', (int) $q)
                : $w->where('testo_segnalazione', 'like', '%'.$q.'%')->orWhere('titolo', 'like', '%'.$q.'%'));
        }

        return response()->json(self::candidatiJson($query->orderByDesc('data_chiusura')->limit(9)->get(), $segnalazione));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Segnalazione>  $candidati
     * @return list<array{id: int, titolo: string, sede: ?string, stato: string, data: ?string}>
     */
    private static function candidatiJson(\Illuminate\Support\Collection $candidati, Segnalazione $esclusa): array
    {
        return $candidati
            ->reject(fn (Segnalazione $s) => $s->id_segnalazione === $esclusa->id_segnalazione)
            ->take(8)
            ->map(fn (Segnalazione $s) => [
                'id'     => $s->id_segnalazione,
                'titolo' => $s->titoloLeggibile(80),
                'sede'   => $s->plesso?->etichetta(),
                'stato'  => $s->statoEnum()->etichetta(),
                'data'   => $s->data_segnalazione?->format('d/m/Y'),
            ])
            ->values()
            ->all();
    }

    private function notificaNota(Segnalazione $segnalazione, NotaSegnalazione $nota): void
    {
        // Niente uscita anticipata sulle note interne: quelle dell'operaio vanno comunque al comune.
        $notification = new NuovaNotaSegnalazione($segnalazione, $nota);
        $attoreId = auth()->id();

        if ($nota->visibile_impresa && $segnalazione->id_appalto) {
            $appalto = $segnalazione->appalto()->with('impresa')->first();
            if ($appalto?->id_impresa) {
                $destinatari = User::role('impresa')
                    ->where('id_impresa', $appalto->id_impresa)
                    ->where('id', '!=', $attoreId)
                    ->get()
                    ->filter(fn (User $u) => $u->attivo !== false);

                if ($destinatari->isNotEmpty()) {
                    Notification::send($destinatari, $notification);
                }
            }
        }

        if ($nota->visibile_web && $segnalazione->id_utente_segnalazione && $segnalazione->id_utente_segnalazione !== $attoreId) {
            $segnalatore = User::find($segnalazione->id_utente_segnalazione);
            $segnalatore?->notify($notification);
        }

        $attore = auth()->user();
        if ($attore && LetturaScheda::per($attore) !== 'gestore') {
            $mittente = $attore->hasRole('impresa')
                ? ($attore->impresa->ragione_sociale ?? $attore->name)
                : $attore->name;
            $comune = DestinatariComune::per($segnalazione, $attore);
            if ($comune->isNotEmpty()) {
                Notification::send($comune, new NotaDaEsternoNotification($segnalazione, $nota, (string) $mittente));
            }
        }
    }

}
