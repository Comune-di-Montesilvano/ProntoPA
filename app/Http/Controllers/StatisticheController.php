<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use App\Models\StatoSegnalazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
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

        // Tempo medio risoluzione (gg)
        $tempoMedioGg = round(
            Segnalazione::visibileA($user)
                ->whereNotNull('data_chiusura')
                ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, data_segnalazione, data_chiusura) / 24) as v')
                ->value('v') ?? 0
        );

        // SLA compliance %
        $totaleChiuse = Segnalazione::visibileA($user)->whereNotNull('data_chiusura')->count();
        $chiuseSenzaViolazione = Segnalazione::visibileA($user)->whereNotNull('data_chiusura')->where('sla_violato', false)->count();
        $slaCompliance = $totaleChiuse > 0 ? round($chiuseSenzaViolazione / $totaleChiuse * 100, 1) : null;

        // Carico operatori (top 10 per aperte)
        $caricoOperatori = Segnalazione::visibileA($user)
            ->aperte()
            ->whereNotNull('id_operatore_assegnato')
            ->select('id_operatore_assegnato', DB::raw('COUNT(*) as totale'))
            ->groupBy('id_operatore_assegnato')
            ->orderByDesc('totale')
            ->limit(10)
            ->with('operatore')
            ->get();

        $caricoLabel  = $caricoOperatori->map(fn ($r) => $r->operatore?->name ?? 'N/D')->toArray();
        $caricoTotali = $caricoOperatori->pluck('totale')->toArray();

        // Trend settimanale (8 settimane)
        $trendSettimanale = Segnalazione::visibileA($user)
            ->select(
                DB::raw('YEARWEEK(data_segnalazione, 1) as settimana'),
                DB::raw('COUNT(*) as totale')
            )
            ->where('data_segnalazione', '>=', now()->subWeeks(8))
            ->groupBy('settimana')
            ->orderBy('settimana')
            ->get();

        $trendLabel  = $trendSettimanale->map(fn ($r) => 'S' . substr((string) $r->settimana, 4))->toArray();
        $trendTotali = $trendSettimanale->pluck('totale')->toArray();

        return view('statistiche.index', compact(
            'kpi',
            'mesiLabel', 'mesiTotali',
            'tipologiaLabel', 'tipologiaTotali',
            'statoLabel', 'statoTotali',
            'tempoMedioGg', 'slaCompliance',
            'caricoLabel', 'caricoTotali',
            'trendLabel', 'trendTotali',
        ));
    }
}
