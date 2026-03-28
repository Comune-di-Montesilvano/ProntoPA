<?php

namespace App\Http\Controllers;

use App\Models\Provenienza;
use App\Models\Segnalazione;
use App\Models\TipologiaSegnalazione;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GestioneController extends Controller
{
    /**
     * Dashboard gestione con tab + ricerca
     */
    public function index(Request $request): View
    {
        $user = auth()->user();
        $tab  = $request->get('tab', 'aperte');
        $q    = trim($request->get('q', ''));
        $idTipologia   = $request->get('id_tipologia');
        $idProvenienza = $request->get('id_provenienza');

        $base = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia', 'operatore', 'provenienza']);

        // Applica filtri ricerca
        if ($q !== '') {
            $base->where(function ($query) use ($q) {
                $query->where('testo_segnalazione', 'like', "%{$q}%")
                      ->orWhere('segnalante', 'like', "%{$q}%")
                      ->orWhere('id_segnalazione', $q);
            });
        }
        if ($idTipologia) {
            $base->where('id_tipologia_segnalazione', $idTipologia);
        }
        if ($idProvenienza) {
            $base->where('id_provenienza', $idProvenienza);
        }

        $segnalazioni = match($tab) {
            'evidenza'    => (clone $base)->inEvidenza()
                                ->orderByDesc('data_segnalazione')->paginate(30),
            'in_carico'   => (clone $base)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('in_carico', true))
                                ->orderByDesc('data_segnalazione')->paginate(30),
            'in_gestione' => (clone $base)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('id_gestione', true))
                                ->orderByDesc('data_segnalazione')->paginate(30),
            'chiuse'      => (clone $base)->whereNotNull('data_chiusura')
                                ->orderByDesc('data_chiusura')->paginate(30),
            default       => (clone $base)->aperte()
                                ->orderByDesc('data_segnalazione')->paginate(30),
        };

        // Conteggi (senza filtro ricerca per non distorcere i badge)
        $baseCount = Segnalazione::visibileA($user);
        $conteggi  = [
            'evidenza'    => (clone $baseCount)->inEvidenza()->count(),
            'in_carico'   => (clone $baseCount)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('in_carico', true))->count(),
            'in_gestione' => (clone $baseCount)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('id_gestione', true))->count(),
            'aperte'      => (clone $baseCount)->aperte()->count(),
            'chiuse'      => (clone $baseCount)->whereNotNull('data_chiusura')->count(),
        ];

        $tipologie   = TipologiaSegnalazione::orderBy('descrizione')->get();
        $provenienze = Provenienza::orderBy('descrizione')->get();

        return view('gestione.dashboard', compact(
            'segnalazioni', 'tab', 'conteggi',
            'q', 'idTipologia', 'idProvenienza',
            'tipologie', 'provenienze'
        ));
    }

    public function stampaLista(Request $request): \Illuminate\View\View
    {
        $user = auth()->user();
        $tab  = $request->get('tab', 'aperte');
        $q    = trim($request->get('q', ''));
        $idTipologia   = $request->get('id_tipologia');
        $idProvenienza = $request->get('id_provenienza');

        $base = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia', 'operatore', 'provenienza', 'plesso.istituto', 'utente']);

        if ($q !== '') {
            $base->where(function ($query) use ($q) {
                $query->where('testo_segnalazione', 'like', "%{$q}%")
                      ->orWhere('segnalante', 'like', "%{$q}%")
                      ->orWhere('id_segnalazione', $q);
            });
        }
        if ($idTipologia)   { $base->where('id_tipologia_segnalazione', $idTipologia); }
        if ($idProvenienza) { $base->where('id_provenienza', $idProvenienza); }

        $segnalazioni = match($tab) {
            'evidenza'    => (clone $base)->inEvidenza()->orderByDesc('data_segnalazione')->get(),
            'in_carico'   => (clone $base)->aperte()->whereHas('stato', fn ($q) => $q->where('in_carico', true))->orderByDesc('data_segnalazione')->get(),
            'in_gestione' => (clone $base)->aperte()->whereHas('stato', fn ($q) => $q->where('id_gestione', true))->orderByDesc('data_segnalazione')->get(),
            'chiuse'      => (clone $base)->whereNotNull('data_chiusura')->orderByDesc('data_chiusura')->get(),
            default       => (clone $base)->aperte()->orderByDesc('data_segnalazione')->get(),
        };

        $tabLabels = [
            'aperte' => 'Aperte', 'in_carico' => 'In carico',
            'in_gestione' => 'In gestione', 'evidenza' => 'In evidenza', 'chiuse' => 'Chiuse',
        ];

        return view('gestione.print', compact('segnalazioni', 'tab', 'tabLabels', 'q'));
    }
}
