<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PublicHomeController extends Controller
{
    public function index(): View
    {
        $stats = Cache::remember('public.home.statistics', now()->addMinutes(30), function (): array {
            $baseQuery = Segnalazione::query()->pubbliche();

            $perMese = (clone $baseQuery)
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

            $mesiLabel = [];
            $mesiTotali = [];
            foreach ($perMese as $record) {
                $mesiLabel[] = sprintf('%02d/%d', $record->mese, $record->anno);
                $mesiTotali[] = $record->totale;
            }

            $perTipologia = (clone $baseQuery)
                ->select('id_tipologia_segnalazione', DB::raw('COUNT(*) as totale'))
                ->groupBy('id_tipologia_segnalazione')
                ->orderByDesc('totale')
                ->limit(10)
                ->with('tipologia')
                ->get();

            $perStato = (clone $baseQuery)
                ->select('id_stato_segnalazione', DB::raw('COUNT(*) as totale'))
                ->groupBy('id_stato_segnalazione')
                ->with('stato')
                ->get();

            return [
                'kpi' => [
                    'totale' => (clone $baseQuery)->count(),
                    'aperte' => (clone $baseQuery)->aperte()->count(),
                    'chiuse' => (clone $baseQuery)->whereNotNull('data_chiusura')->count(),
                    'questo_mese' => (clone $baseQuery)
                        ->whereMonth('data_segnalazione', now()->month)
                        ->whereYear('data_segnalazione', now()->year)
                        ->count(),
                ],
                'mesiLabel' => $mesiLabel,
                'mesiTotali' => $mesiTotali,
                'tipologiaLabel' => $perTipologia->map(fn ($record) => $record->tipologia?->descrizione ?? 'N/D')->toArray(),
                'tipologiaTotali' => $perTipologia->pluck('totale')->toArray(),
                'statoLabel' => $perStato->map(fn ($record) => $record->stato?->descrizione ?? 'N/D')->toArray(),
                'statoTotali' => $perStato->pluck('totale')->toArray(),
            ];
        });

        return view('public.home', $stats);
    }
}