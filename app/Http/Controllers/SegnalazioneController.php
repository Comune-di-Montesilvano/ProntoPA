<?php

namespace App\Http\Controllers;

use App\Enums\SegnalazioneStato;
use App\Models\AllegatoSegnalazione;
use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\NotaSegnalazione;
use App\Models\Delega;
use App\Models\Plesso;
use App\Models\Provenienza;
use App\Models\Segnalazione;
use App\Models\Specializzazione;
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
use App\Models\Squadra;
use App\Support\Segnalazioni\Attivita;
use App\Support\Segnalazioni\CaricaAllegati;
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
            ->with(['tipologia', 'provenienza', 'plesso'])
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
        $user    = auth()->user();
        $profilo = $user->profilo;

        $tipologie   = TipologiaSegnalazione::with('gruppo')->orderBy('descrizione')->get();
        $provenienze = Provenienza::orderBy('descrizione')->get();

        if ($user->isSpid()) {
            $plessi = Plesso::with('istituto')
                            ->whereIn('id_plesso', Delega::plessiCopertiDa($user))
                            ->orderBy('nome')->get();
        } elseif ($profilo && $profilo->limita_istituto && $profilo->id_istituto) {
            $plessi = Plesso::with('istituto')
                            ->where('id_istituto', $profilo->id_istituto)
                            ->orderBy('nome')->get();
        } else {
            $plessi = Plesso::with('istituto')->orderBy('nome')->get();
        }

        $provenienza_default = $user->id_provenienza;
        $specializzazioni    = Specializzazione::orderBy('descrizione')->get();

        // Tempo previsto per tipologia (0 = regola valida per tutte) e priorità: il segnalante
        // non sceglie la specializzazione, contano solo le regole senza.
        $tempiPrevisti = SlaConfigurazione::whereNull('id_specializzazione')->get()
            ->groupBy(fn (SlaConfigurazione $r) => (int) $r->id_tipologia_segnalazione)
            ->map(fn ($regole) => $regole->mapWithKeys(fn (SlaConfigurazione $r) => [$r->livello_priorita => $r->descrizioneTempo()]));

        return view('segnalazioni.create', compact('tipologie', 'provenienze', 'plessi', 'provenienza_default', 'specializzazioni', 'tempiPrevisti'));
    }

    // ── Salva nuova segnalazione ───────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $maxSizeMb      = (int) Impostazione::get('allegati_max_size_mb', 10);
        $maxPerRequest  = (int) Impostazione::get('allegati_max_per_request', 5);
        $mimeConsentiti = array_filter(
            array_map('trim', explode(',', Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png')))
        );

        if (! $request->user()->puoScegliereProvenienza()) {
            $request->merge(['id_provenienza' => $request->user()->id_provenienza]);
        }

        $scuola = $request->user()->segnalaComeScuola();
        if ($scuola) {
            $request->merge(['latitudine' => null, 'longitudine' => null]);
        }
        $obbligatorioScuola = $scuola ? 'required' : 'nullable';

        $regolePlesso = [$obbligatorioScuola, 'integer', 'exists:plessi,id_plesso'];
        if ($request->user()->isSpid()) {
            // Scuole via SPID: si segnala solo su un plesso coperto da delega.
            $regolePlesso = ['required', 'integer', Rule::in(Delega::plessiCopertiDa($request->user())->pluck('id_plesso')->all())];
        }

        $data = $request->validate([
            'id_tipologia_segnalazione' => ['required', 'integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
            'testo_segnalazione'        => ['required', 'string', 'max:2000'],
            'id_plesso'                 => $regolePlesso,
            'id_provenienza'            => ['required', 'integer', 'exists:provenienze_segnalazioni,id_provenienza'],
            'latitudine'                => ['nullable', 'numeric'],
            'longitudine'               => ['nullable', 'numeric'],
            'livello_priorita'          => ['nullable', 'integer', 'between:1,4'],
            'id_specializzazione'       => ['nullable', 'integer', 'exists:db_specializzazioni,id_specializzazione'],
            'ubicazione_tipo'           => $scuola ? ['required', 'integer', 'between:1,4'] : ['nullable', 'integer', 'between:0,4'],
            'allegati'                  => ['nullable', 'array', 'max:' . $maxPerRequest],
            'allegati.*'                => [
                'file',
                'max:' . ($maxSizeMb * 1024),
                'mimetypes:' . implode(',', $mimeConsentiti),
            ],
            'segnalante_per_conto'      => ['nullable', 'string', 'max:255'],
            'telefono_per_conto'        => ['nullable', 'string', 'max:50'],
            'email_per_conto'           => ['nullable', 'email', 'max:255'],
            'salva_e_nuova'             => ['nullable', 'boolean'],
        ], [
            'id_plesso.required'           => 'Scegli il plesso.',
            'id_plesso.in'                 => 'Puoi segnalare solo per i plessi coperti dalle tue deleghe.',
            'ubicazione_tipo.required'     => 'Indica dove si trova il problema.',
            'ubicazione_tipo.between'      => 'Indica dove si trova il problema.',
        ]);

        $user = auth()->user();

        $perConto = $user->can('segnalazioni.per-conto');


        $segnalazione = Segnalazione::create(array_merge(
            Arr::except($data, ['allegati', 'segnalante_per_conto', 'telefono_per_conto', 'email_per_conto', 'salva_e_nuova']),
            [
                'id_utente_segnalazione' => $user->id,
                'id_stato_segnalazione'  => SegnalazioneStato::NUOVA->value,
                'id_plesso'              => $data['id_plesso'] ?? 0,
                'latitudine'             => $data['latitudine'] ?? 0,
                'longitudine'            => $data['longitudine'] ?? 0,
                'segnalante'             => $perConto && filled($data['segnalante_per_conto'] ?? null)
                                                ? $data['segnalante_per_conto'] : $user->name,
                'email'                  => $perConto && filled($data['email_per_conto'] ?? null)
                                                ? $data['email_per_conto'] : $user->email,
                'telefono'               => $perConto && filled($data['telefono_per_conto'] ?? null)
                                                ? $data['telefono_per_conto'] : $user->telefono,
                'livello_priorita'       => $data['livello_priorita'] ?? 2,
                'id_specializzazione'    => $data['id_specializzazione'] ?? null,
                'ubicazione_tipo'        => $data['ubicazione_tipo'] ?? null,
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
            GeneraTitoloSegnalazione::dispatch($segnalazione->id_segnalazione);
            SuggerisciTriageSegnalazione::dispatch($segnalazione->id_segnalazione);
            CalcolaEmbeddingSegnalazione::dispatch($segnalazione->id_segnalazione);
        }

        if ($perConto && $request->boolean('salva_e_nuova')) {
            return redirect()->route('segnalazioni.create')
                ->with('success', 'Segnalazione #' . $segnalazione->id_segnalazione . ' inviata. Inseriscine un\'altra.');
        }

        return redirect()->route('segnalazioni.index')
            ->with('success', 'Segnalazione inviata con successo.');
    }

    // ── Dettaglio ─────────────────────────────────────────────────────────────

    public function show(Segnalazione $segnalazione): View
    {
        $this->authorize('view', $segnalazione);

        $segnalazione->load([
            'stato', 'tipologia.gruppo', 'provenienza', 'plesso.istituto',
            'operatore', 'utente', 'appalto', 'specializzazione',
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

        $fotoPrima = $segnalazione->allegati->where('fase', 'prima');
        $fotoDopo = $segnalazione->allegati->where('fase', 'dopo');

        // Dati dei pannelli di assegnazione: servono solo alla lettura del comune.
        $operatori = collect();
        $squadre = collect();
        $appaltiValidi = collect();
        if ($lettura === 'gestore') {
            $operatori = User::where(fn ($q) => $q->where('gestore_segnalazioni', true)
                    ->orWhere('amministratore', true)
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['operaio', 'gestore', 'admin'])))
                ->where(fn ($q) => $q->whereNull('attivo')->orWhere('attivo', true))
                ->orderBy('name')
                ->get();
            if (Impostazione::get('squadre_enabled', false)) {
                $squadre = Squadra::where('attiva', true)->orderBy('nome')->get();
            }
            $appaltiValidi = Appalto::where('valido', true)->with('impresa')->orderBy('descrizione')->get();
            $gruppo = TipologiaSegnalazione::whereKey($segnalazione->id_tipologia_segnalazione)->value('id_gruppo');
            if ($gruppo && $appaltiValidi->contains('id_gruppo', $gruppo)) {
                $appaltiValidi = $appaltiValidi->where('id_gruppo', $gruppo)->values();
            }
        }

        return view('segnalazioni.show', compact(
            'segnalazione', 'lettura', 'prossimoPasso', 'attivita', 'azioniDisponibili',
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
            'id_operatore'              => ['nullable', 'integer', 'exists:users,id'],
            'id_appalto'                => ['nullable', 'integer', 'exists:appalti,id_appalto'],
            'id_squadra'                => ['nullable', 'integer', 'exists:squadre,id_squadra'],
            'nota'                      => ['nullable', 'string', 'max:2000'],
            'id_segnalazione_correlata' => ['nullable', 'integer', 'exists:segnalazioni,id_segnalazione'],
            'livello_priorita'          => ['nullable', 'integer', 'between:1,4'],
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

        if ($azione->codice === 'chiudi') {
            // Se ditta/operaio hanno già mandato il rapportino con le foto, il comune chiude e basta.
            $giaRendicontata = $segnalazione->haFineLavori();
            $obbligo = $giaRendicontata ? 'nullable' : 'required';
            $rules['ore_lavoro'] = [$obbligo, 'numeric', 'min:0'];
            $rules['materiali']  = [$obbligo, 'string', 'max:1000'];
            $rules['nota']       = [$obbligo, 'string', 'max:2000'];
            $rules += CaricaAllegati::regole(obbligatori: ! $giaRendicontata);
        }

        $data = $request->validate($rules, [
            ...CaricaAllegati::messaggi(),
            'allegati.required' => 'Devi caricare almeno una foto dell\'intervento per proporre la chiusura.',
            'allegati.min' => 'Devi caricare almeno una foto dell\'intervento per proporre la chiusura.',
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
            ...CaricaAllegati::regole(obbligatori: true),
        ], [
            ...CaricaAllegati::messaggi(),
            'allegati.required' => 'Serve almeno una foto del lavoro finito.',
            'allegati.min'      => 'Serve almeno una foto del lavoro finito.',
        ]);

        $servizio->comunica($segnalazione, auth()->user(), $data, $request->file('allegati'));

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Fine lavori comunicata al comune.');
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
        $testo = 'Sollecito: '.(trim((string) ($data['testo'] ?? '')) ?: 'aggiornare sullo stato del lavoro.');

        $segnalazione->note()->create([
            'testo'            => $testo,
            'id_utente'        => auth()->id(),
            'visibile_web'     => false,
            'visibile_impresa' => (bool) $segnalazione->id_appalto,
        ]);

        $destinatari = DestinatariAssegnatario::per($segnalazione, auth()->user());
        $notifica = new SollecitoNotification($segnalazione, $testo);
        if ($destinatari['utenti']->isNotEmpty()) {
            Notification::send($destinatari['utenti'], $notifica);
        }
        foreach ($destinatari['email'] as $email) {
            Notification::route('mail', $email)->notify($notifica);
        }

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
        ]);

        $notifica = new SollecitoNotification($segnalazione, $testo, dalSegnalante: true);
        $destinatari = DestinatariAssegnatario::per($segnalazione, $utente);
        if ($destinatari['utenti']->isEmpty() && $destinatari['email'] === []) {
            $destinatari['utenti'] = DestinatariComune::per($segnalazione, $utente);
        }
        if ($destinatari['utenti']->isNotEmpty()) {
            Notification::send($destinatari['utenti'], $notifica);
        }
        foreach ($destinatari['email'] as $email) {
            Notification::route('mail', $email)->notify($notifica);
        }

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Sollecito inviato.');
    }

    // ── Stampa scheda ────────────────────────────────────────────────────────

    public function stampa(Segnalazione $segnalazione): View
    {
        $this->authorize('view', $segnalazione);

        $segnalazione->load([
            'stato', 'tipologia.gruppo', 'provenienza', 'plesso.istituto',
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
        if (! $segnalazione->isChiusa()) {
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
            'latitudine'                => ['nullable', 'numeric'],
            'longitudine'               => ['nullable', 'numeric'],
            'testo'                     => ['nullable', 'string', 'max:2000'],
        ]);

        $simili = $this->dedup->trovaSimili(
            (int) $data['id_tipologia_segnalazione'],
            isset($data['id_plesso']) ? (int) $data['id_plesso'] : null,
            isset($data['latitudine']) ? (float) $data['latitudine'] : null,
            isset($data['longitudine']) ? (float) $data['longitudine'] : null,
            $data['testo'] ?? null,
        );

        $user = $request->user();

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
                $segnalazione->latitudine !== null ? (float) $segnalazione->latitudine : null,
                $segnalazione->longitudine !== null ? (float) $segnalazione->longitudine : null,
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
                    : $w->where('testo_segnalazione', 'like', '%'.$q.'%')->orWhere('titolo_generato', 'like', '%'.$q.'%'))
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
                : $w->where('testo_segnalazione', 'like', '%'.$q.'%')->orWhere('titolo_generato', 'like', '%'.$q.'%'));
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
                'titolo' => (string) ($s->titolo_generato ?: Str::limit((string) $s->testo_segnalazione, 80)),
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
