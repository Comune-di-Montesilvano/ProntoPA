<?php

namespace App\Http\Controllers;

use App\Models\Azione;
use App\Models\NotaSegnalazione;
use App\Models\Plesso;
use App\Models\Provenienza;
use App\Models\Segnalazione;
use App\Models\StatoSegnalazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SegnalazioneController extends Controller
{
    public function __construct(
        private readonly SegnalazioneWorkflowService $workflow
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
        $tipologie  = TipologiaSegnalazione::with('gruppo')->orderBy('descrizione')->get();
        $provenienze = Provenienza::orderBy('descrizione')->get();
        $plessi     = Plesso::with('istituto')->orderBy('nome')->get();

        return view('segnalazioni.create', compact('tipologie', 'provenienze', 'plessi'));
    }

    // ── Salva nuova segnalazione ───────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_tipologia_segnalazione' => ['required', 'integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
            'testo_segnalazione'        => ['required', 'string', 'max:2000'],
            'id_plesso'                 => ['nullable', 'integer', 'exists:plessi,id_plesso'],
            'id_provenienza'            => ['required', 'integer', 'exists:provenienze_segnalazioni,id_provenienza'],
            'segnalante'                => ['nullable', 'string', 'max:100'],
            'email'                     => ['nullable', 'email', 'max:100'],
            'telefono'                  => ['nullable', 'string', 'max:50'],
            'latitudine'                => ['nullable', 'numeric'],
            'longitudine'               => ['nullable', 'numeric'],
        ]);

        // Stato iniziale
        $statoIniziale = StatoSegnalazione::where('iniziale', true)->first();

        Segnalazione::create(array_merge($data, [
            'id_utente_segnalazione' => auth()->id(),
            'id_stato_segnalazione'  => $statoIniziale?->id_stato ?? 1,
            'id_plesso'              => $data['id_plesso'] ?? 0,
            'latitudine'             => $data['latitudine'] ?? 0,
            'longitudine'            => $data['longitudine'] ?? 0,
        ]));

        return redirect()->route('segnalazioni.index')
            ->with('success', 'Segnalazione inviata con successo.');
    }

    // ── Dettaglio ─────────────────────────────────────────────────────────────

    public function show(Segnalazione $segnalazione): View
    {
        $this->authorize('view', $segnalazione);

        $segnalazione->load([
            'stato', 'tipologia.gruppo', 'provenienza', 'plesso.istituto',
            'operatore', 'utente', 'appalto',
            'note.autore',
            'storicoStati.stato', 'storicoStati.utente',
        ]);

        $azioniDisponibili = $this->workflow->getAzioniDisponibili($segnalazione, auth()->user());
        $operatori = User::where('gestore_segnalazioni', true)
            ->orWhere('amministratore', true)
            ->orderBy('name')
            ->get();

        return view('segnalazioni.show', compact('segnalazione', 'azioniDisponibili', 'operatori'));
    }

    // ── Esegui azione workflow ────────────────────────────────────────────────

    public function eseguiAzione(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('update', $segnalazione);

        $data = $request->validate([
            'id_azione'    => ['required', 'integer', 'exists:db_azioni,id_azione'],
            'id_operatore' => ['nullable', 'integer', 'exists:users,id'],
            'id_appalto'   => ['nullable', 'integer', 'exists:appalti,id_appalto'],
            'nota'         => ['nullable', 'string', 'max:2000'],
        ]);

        $this->workflow->eseguiAzione(
            $segnalazione,
            (int) $data['id_azione'],
            auth()->user(),
            $data
        );

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

        $segnalazione->note()->create([
            'testo'            => $data['testo'],
            'id_utente'        => auth()->id(),
            'visibile_web'     => $data['visibile_web'] ?? false,
            'visibile_impresa' => $data['visibile_impresa'] ?? false,
        ]);

        return redirect()->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Nota aggiunta.')
            ->withFragment('note');
    }

    // ── Toggle evidenza ───────────────────────────────────────────────────────

    public function toggleEvidenza(Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('update', $segnalazione);

        $this->workflow->setEvidenza($segnalazione, ! $segnalazione->flag_evidenza);

        return back()->with('success', $segnalazione->flag_evidenza ? 'Rimossa da evidenza.' : 'Aggiunta in evidenza.');
    }
}
