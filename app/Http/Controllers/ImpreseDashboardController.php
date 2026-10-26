<?php

namespace App\Http\Controllers;

use App\Enums\SegnalazioneStato;
use App\Models\Appalto;
use App\Models\Segnalazione;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImpreseDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user    = auth()->user();
        $idImpresa = $user->id_impresa;

        $tab    = $request->get('tab', 'lavorazione');
        $dataDa = $request->get('data_da');
        $dataA  = $request->get('data_a');

        $appaltiAttivi = Appalto::where('id_impresa', $idImpresa)
            ->where('valido', true)
            ->with('gruppo')
            ->get();

        // Tutti i lavori della ditta, anche su appalti scaduti (stessa visibilità della scheda)
        $base = Segnalazione::visibileA($user)
            ->with(['tipologia', 'plesso.istituto', 'appalto']);

        if ($dataDa) {
            $base->whereDate('data_segnalazione', '>=', $dataDa);
        }
        if ($dataA) {
            $base->whereDate('data_segnalazione', '<=', $dataA);
        }

        $segnalazioni = match ($tab) {
            'preventivo' => (clone $base)->aperte()
                ->where('id_stato_segnalazione', SegnalazioneStato::PREVENTIVO_IN_ATTESA->value)
                ->ordinaUrgenti()
                ->paginate(20),
            'completate' => (clone $base)->whereNotNull('data_chiusura')
                ->orderByDesc('data_chiusura')
                ->paginate(20),
            default => (clone $base)->aperte()
                ->ordinaUrgenti()
                ->paginate(20),
        };

        $conteggi = [
            'lavorazione' => (clone $base)->aperte()->count(),
            'preventivo'  => (clone $base)->aperte()->where('id_stato_segnalazione', SegnalazioneStato::PREVENTIVO_IN_ATTESA->value)->count(),
            'completate'  => (clone $base)->whereNotNull('data_chiusura')->count(),
        ];

        $kpi = [
            'appalti_attivi'     => $appaltiAttivi->count(),
            'segnalazioni_aperte'=> $conteggi['lavorazione'],
            'importo_affidato'   => $appaltiAttivi->sum('importo_appalto'),
            'importo_liquidato'  => (clone $base)->whereNotNull('data_chiusura')->sum('importo_liquidato'),
        ];

        return view('imprese.dashboard', compact(
            'segnalazioni', 'appaltiAttivi', 'kpi', 'conteggi', 'tab', 'dataDa', 'dataA'
        ));
    }
}
