<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use App\Models\StatoSegnalazione;
use App\Models\TipologiaSegnalazione;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StatisticheController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        // Segnalazioni per mese (ultimi 12 mesi)
        $perMese = Segnalazione::visibileA($user)
            ->select(
                DB::raw('YEAR(data_segnalazione) as anno'),
                DB::raw('MONTH(data_segnalazione) as mese'),
                DB::raw('COUNT(*) as totale')
            )
            ->where('data_segnalazione', '>=', now()->subYear())
            ->groupBy('anno', 'mese')
            ->orderBy('anno')
            ->orderBy('mese')
            ->get();

        // Formatta in etichette mese/anno con totali
        $mesiLabel  = [];
        $mesiTotali = [];
        foreach ($perMese as $r) {
            $mesiLabel[]  = sprintf('%02d/%d', $r->mese, $r->anno);
            $mesiTotali[] = $r->totale;
        }

        // Per tipologia (top 10)
        $perTipologia = Segnalazione::visibileA($user)
            ->select('id_tipologia_segnalazione', DB::raw('COUNT(*) as totale'))
            ->groupBy('id_tipologia_segnalazione')
            ->orderByDesc('totale')
            ->limit(10)
            ->with('tipologia')
            ->get();

        $tipologiaLabel  = $perTipologia->map(fn($r) => $r->tipologia?->descrizione ?? 'N/D')->toArray();
        $tipologiaTotali = $perTipologia->pluck('totale')->toArray();

        // Per stato
        $perStato = Segnalazione::visibileA($user)
            ->select('id_stato_segnalazione', DB::raw('COUNT(*) as totale'))
            ->groupBy('id_stato_segnalazione')
            ->with('stato')
            ->get();

        $statoLabel  = $perStato->map(fn($r) => $r->stato?->descrizione ?? 'N/D')->toArray();
        $statoTotali = $perStato->pluck('totale')->toArray();

        // KPI generali
        $kpi = [
            'totale'     => Segnalazione::visibileA($user)->count(),
            'aperte'     => Segnalazione::visibileA($user)->aperte()->count(),
            'chiuse'     => Segnalazione::visibileA($user)->whereNotNull('data_chiusura')->count(),
            'evidenza'   => Segnalazione::visibileA($user)->inEvidenza()->count(),
            'questo_mese'=> Segnalazione::visibileA($user)
                ->whereMonth('data_segnalazione', now()->month)
                ->whereYear('data_segnalazione', now()->year)
                ->count(),
        ];

        return view('statistiche.index', compact(
            'kpi',
            'mesiLabel', 'mesiTotali',
            'tipologiaLabel', 'tipologiaTotali',
            'statoLabel', 'statoTotali',
        ));
    }
}
