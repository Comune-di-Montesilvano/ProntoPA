<?php

namespace App\Http\Controllers;

use App\Models\Impresa;
use App\Models\Segnalazione;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function mensileGestore(Request $request): View
    {
        $mese = (int) $request->get('mese', now()->month);
        $anno = (int) $request->get('anno', now()->year);
        $user = auth()->user();

        $dal = Carbon::createFromDate($anno, $mese, 1)->startOfMonth();
        $al  = $dal->copy()->endOfMonth();

        $segnalazioni = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia', 'plesso.istituto', 'operatore', 'appalto.impresa'])
            ->whereBetween('data_segnalazione', [$dal, $al])
            ->orderByDesc('data_segnalazione')
            ->get();

        $kpi = [
            'totale'      => $segnalazioni->count(),
            'aperte'      => $segnalazioni->filter(fn ($s) => ! $s->isChiusa())->count(),
            'chiuse'      => $segnalazioni->filter(fn ($s) => $s->isChiusa())->count(),
            'sla_violato' => $segnalazioni->filter(fn ($s) => $s->sla_violato)->count(),
            'urgenti'     => $segnalazioni->filter(fn ($s) => $s->segnalazione_urgente)->count(),
        ];

        return view('reports.mensile-gestore', compact('segnalazioni', 'kpi', 'mese', 'anno', 'dal', 'al'));
    }

    public function riepilogoImpresa(Request $request): View
    {
        $user = auth()->user();

        if ($user->hasRole('impresa')) {
            $idImpresa = $user->id_impresa;
        } else {
            $this->authorize('viewAny', Segnalazione::class);
            $idImpresa = (int) $request->get('id_impresa');
        }

        $dataDa = $request->get('data_da', now()->startOfMonth()->toDateString());
        $dataA  = $request->get('data_a', now()->toDateString());

        $segnalazioni = Segnalazione::whereHas('appalto', fn ($q) => $q->where('id_impresa', $idImpresa))
            ->with(['stato', 'tipologia', 'plesso.istituto', 'appalto.impresa'])
            ->whereBetween('data_segnalazione', [$dataDa, $dataA])
            ->orderByDesc('data_segnalazione')
            ->get();

        $impresa         = $idImpresa ? Impresa::find($idImpresa) : null;
        $totaleAffidato  = $segnalazioni->sum(fn ($s) => $s->appalto?->importo_appalto ?? 0);
        $totaleLiquidato = $segnalazioni->sum('importo_liquidato');

        return view('reports.riepilogo-impresa', compact(
            'segnalazioni', 'impresa', 'dataDa', 'dataA', 'totaleAffidato', 'totaleLiquidato'
        ));
    }
}
