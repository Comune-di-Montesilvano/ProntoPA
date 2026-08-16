<?php

namespace App\Http\Controllers;

use App\Enums\SegnalazioneStato;
use App\Models\AllegatoSegnalazione;
use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\NotaSegnalazione;
use App\Models\Plesso;
use App\Models\Provenienza;
use App\Models\Segnalazione;
use App\Models\Specializzazione;
use App\Models\StoricoStatoSegnalazione;
use App\Notifications\NuovaNotaSegnalazione;
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
            ->with(['stato', 'tipologia', 'provenienza'])
            ->aperte()
            ->orderByDesc('data_segnalazione')
            ->paginate(20);

        return view('segnalazioni.index', compact('segnalazioni'));
    }

    // ── Form creazione ────────────────────────────────────────────────────────

    public function create(): View
    {
        $user    = auth()->user();
        $profilo = $user->profilo;

        $tipologie   = TipologiaSegnalazione::with('gruppo')->orderBy('descrizione')->get();
        $provenienze = Provenienza::orderBy('descrizione')->get();

        if ($profilo && $profilo->limita_istituto && $profilo->id_istituto) {
            $plessi = Plesso::with('istituto')
                            ->where('id_istituto', $profilo->id_istituto)
                            ->orderBy('nome')->get();
        } else {
            $plessi = Plesso::with('istituto')->orderBy('nome')->get();
        }

        $provenienza_default = $user->id_provenienza;
        $specializzazioni    = Specializzazione::orderBy('descrizione')->get();

        return view('segnalazioni.create', compact('tipologie', 'provenienze', 'plessi', 'provenienza_default', 'specializzazioni'));
    }

    // ── Salva nuova segnalazione ───────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $maxSizeMb      = (int) Impostazione::get('allegati_max_size_mb', 10);
        $maxPerRequest  = (int) Impostazione::get('allegati_max_per_request', 5);
        $mimeConsentiti = array_filter(
            array_map('trim', explode(',', Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png')))
        );

        $data = $request->validate([
            'id_tipologia_segnalazione' => ['required', 'integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
            'testo_segnalazione'        => ['required', 'string', 'max:2000'],
            'id_plesso'                 => ['nullable', 'integer', 'exists:plessi,id_plesso'],
            'id_provenienza'            => ['required', 'integer', 'exists:provenienze_segnalazioni,id_provenienza'],
            'latitudine'                => ['nullable', 'numeric'],
            'longitudine'               => ['nullable', 'numeric'],
            'segnalazione_urgente'      => ['nullable', 'boolean'],
            'livello_priorita'          => ['nullable', 'integer', 'between:1,4'],
            'id_specializzazione'       => ['nullable', 'integer', 'exists:db_specializzazioni,id_specializzazione'],
            'ubicazione_tipo'           => ['nullable', 'integer', 'between:0,4'],
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
                'segnalazione_urgente'   => (bool) ($data['segnalazione_urgente'] ?? false),
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
            'note.autore',
            'storicoStati.stato', 'storicoStati.utente',
            'allegati.utenteCreazione',
            'adesioni.utente',
        ]);

        $azioniDisponibili = $this->workflow->getAzioniDisponibili($segnalazione, auth()->user());
        $operatori = User::where('gestore_segnalazioni', true)
            ->orWhere('amministratore', true)
            ->orderBy('name')
            ->get();

        $fotoPrima = $segnalazione->allegati->where('fase', 'prima');
        $fotoDopo = $segnalazione->allegati->where('fase', 'dopo');

        return view('segnalazioni.show', compact('segnalazione', 'azioniDisponibili', 'operatori', 'fotoPrima', 'fotoDopo'));
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
            'id_segnalazione_madre'     => ['nullable', 'integer', 'exists:segnalazioni,id_segnalazione'],
            'id_segnalazione_correlata' => ['nullable', 'integer', 'exists:segnalazioni,id_segnalazione'],
        ];

        if ($azione->flag_preventivo) {
            $rules['importo_preventivo'] = ['required', 'numeric', 'min:0'];
        }

        if ($azione->codice === 'chiudi') {
            $rules['ore_lavoro'] = ['required', 'numeric', 'min:0'];
            $rules['materiali']  = ['required', 'string', 'max:1000'];
            $rules['nota']       = ['required', 'string', 'max:2000'];

            $maxSizeMb      = (int) Impostazione::get('allegati_max_size_mb', 10);
            $maxPerRequest  = (int) Impostazione::get('allegati_max_per_request', 5);
            $mimeConsentiti = array_filter(
                array_map('trim', explode(',', Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png')))
            );

            $rules['allegati']   = ['required', 'array', 'min:1', 'max:' . $maxPerRequest];
            $rules['allegati.*'] = [
                'file',
                'max:' . ($maxSizeMb * 1024),
                'mimetypes:' . implode(',', $mimeConsentiti),
            ];
        }

        $data = $request->validate($rules, [
            'allegati.required' => 'Devi caricare almeno una foto dell\'intervento per proporre la chiusura.',
            'allegati.min' => 'Devi caricare almeno una foto dell\'intervento per proporre la chiusura.',
        ]);

        $this->workflow->eseguiAzione(
            $segnalazione,
            $azioneId,
            auth()->user(),
            $data
        );

        if ($request->hasFile('allegati') && $azione->codice === 'chiudi') {
            $disk = Impostazione::get('allegati_storage_disk', 'local');
            $user = auth()->user();

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
                    'fase'                => 'dopo',
                ]);
            }
        }

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Azione eseguita.');
    }

    // ── Aggiungi nota ─────────────────────────────────────────────────────────

    public function aggiungiNota(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('view', $segnalazione);

        $data = $request->validate([
            'testo'            => ['required', 'string', 'max:2000'],
            'visibile_web'     => ['boolean'],
            'visibile_impresa' => ['boolean'],
        ]);

        $nota = $segnalazione->note()->create([
            'testo'            => $data['testo'],
            'id_utente'        => auth()->id(),
            'visibile_web'     => $data['visibile_web'] ?? false,
            'visibile_impresa' => $data['visibile_impresa'] ?? false,
        ]);

        $this->notificaNota($segnalazione, $nota);

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Nota aggiunta.')
            ->withFragment('note');
    }

    // ── Stampa scheda ────────────────────────────────────────────────────────

    public function stampa(Segnalazione $segnalazione): View
    {
        $this->authorize('view', $segnalazione);

        $segnalazione->load([
            'stato', 'tipologia.gruppo', 'provenienza', 'plesso.istituto',
            'operatore', 'utente', 'appalto.impresa',
            'note',
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

    // ── Unisci duplicato a un'altra segnalazione ──────────────────────────────

    public function unisci(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('update', $segnalazione);

        $data = $request->validate([
            'id_destinazione' => ['required', 'integer', 'exists:segnalazioni,id_segnalazione'],
        ]);

        $destinazione = Segnalazione::findOrFail($data['id_destinazione']);

        // Il gestore deve poter operare anche sulla destinazione, non solo sul duplicato
        $this->authorize('update', $destinazione);

        if ($destinazione->id_segnalazione === $segnalazione->id_segnalazione) {
            return back()->withErrors(['id_destinazione' => 'Impossibile unire una segnalazione a sé stessa.']);
        }
        if ($destinazione->isChiusa()) {
            return back()->withErrors(['id_destinazione' => 'La segnalazione di destinazione è chiusa.']);
        }
        if ($segnalazione->isChiusa()) {
            return back()->withErrors(['id_destinazione' => 'La segnalazione corrente è già chiusa.']);
        }

        $user = $request->user();

        DB::transaction(function () use ($segnalazione, $destinazione, $user) {
            // 1. Allegati del duplicato → destinazione
            $segnalazione->allegati()->update([
                'id_segnalazione' => $destinazione->id_segnalazione,
            ]);

            // 2. Adesioni del duplicato → destinazione
            $segnalazione->adesioni()->update([
                'id_segnalazione' => $destinazione->id_segnalazione,
            ]);

            // 3. Il segnalante del duplicato diventa adesione della destinazione
            $destinazione->adesioni()->create([
                'id_utente'  => $segnalazione->id_utente_segnalazione ?: $user->id,
                'segnalante' => $segnalazione->segnalante,
                'telefono'   => $segnalazione->telefono,
                'email'      => $segnalazione->email,
            ]);

            // 4. Chiudi il duplicato come Duplicata
            $segnalazione->update([
                'id_stato_segnalazione' => SegnalazioneStato::DUPLICATA->value,
                'data_chiusura'         => now(),
            ]);

            StoricoStatoSegnalazione::create([
                'id_segnalazione'       => $segnalazione->id_segnalazione,
                'id_stato_segnalazione' => SegnalazioneStato::DUPLICATA->value,
                'id_utente'             => $user->id,
                'id_utente_collegato'   => 0,
                'id_appalto'            => 0,
            ]);

            // 5. Note di tracciamento su entrambe
            $segnalazione->note()->create([
                'testo'            => "Unita alla segnalazione #{$destinazione->id_segnalazione} come duplicato.",
                'id_utente'        => $user->id,
                'visibile_web'     => false,
                'visibile_impresa' => false,
            ]);
            $destinazione->note()->create([
                'testo'            => "Assorbita la segnalazione duplicata #{$segnalazione->id_segnalazione}.",
                'id_utente'        => $user->id,
                'visibile_web'     => false,
                'visibile_impresa' => false,
            ]);
        });

        return redirect()
            ->route('segnalazioni.show', $destinazione->id_segnalazione)
            ->with('success', "Segnalazione #{$segnalazione->id_segnalazione} unita alla #{$destinazione->id_segnalazione}.");
    }

    private function notificaNota(Segnalazione $segnalazione, NotaSegnalazione $nota): void
    {
        if (! $nota->visibile_impresa && ! $nota->visibile_web) {
            return;
        }

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
    }

}
