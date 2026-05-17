<?php

namespace App\Http\Controllers;

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

        $appaltiIds = $appaltiAttivi->pluck('id_appalto');

        $base = Segnalazione::whereIn('id_appalto', $appaltiIds)
            ->with(['stato', 'tipologia', 'plesso.istituto', 'appalto']);

        if ($dataDa) {
            $base->whereDate('data_segnalazione', '>=', $dataDa);
        }
        if ($dataA) {
            $base->whereDate('data_segnalazione', '<=', $dataA);
        }

        $segnalazioni = match ($tab) {
            'preventivo' => (clone $base)->aperte()
                ->whereHas('stato', fn ($q) => $q->where('in_carico', true))
                ->orderByDesc('data_segnalazione')
                ->paginate(20),
            'completate' => (clone $base)->whereNotNull('data_chiusura')
                ->orderByDesc('data_chiusura')
                ->paginate(20),
            default => (clone $base)->aperte()
                ->orderBy('livello_priorita', 'desc')
                ->orderByDesc('data_segnalazione')
                ->paginate(20),
        };

        $kpi = [
            'appalti_attivi'     => $appaltiAttivi->count(),
            'segnalazioni_aperte'=> (clone $base)->aperte()->count(),
            'importo_affidato'   => $appaltiAttivi->sum('importo_appalto'),
            'importo_liquidato'  => Segnalazione::whereIn('id_appalto', $appaltiIds)
                                        ->whereNotNull('data_chiusura')
                                        ->sum('importo_liquidato'),
        ];

        return view('imprese.dashboard', compact(
            'segnalazioni', 'appaltiAttivi', 'kpi', 'tab', 'dataDa', 'dataA'
        ));
    }
}
