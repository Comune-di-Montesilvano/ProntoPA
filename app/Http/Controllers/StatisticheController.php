<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use App\Models\StatoSegnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StatisticheController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        // Segnalazioni per mese (ultimi 12 mesi)
        if (DB::getDriverName() === 'sqlite') {
            $perMese = Segnalazione::visibileA($user)
                ->select(
                    DB::raw("strftime('%Y', data_segnalazione) as anno"),
                    DB::raw("strftime('%m', data_segnalazione) as mese"),
                    DB::raw('COUNT(*) as totale')
                )
                ->where('data_segnalazione', '>=', now()->subYear())
                ->groupBy('anno', 'mese')
                ->orderBy('anno')
                ->orderBy('mese')
                ->get();
        } else {
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
        }

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
        if (DB::getDriverName() === 'sqlite') {
            $tempoMedioGg = round(
                Segnalazione::visibileA($user)
                    ->whereNotNull('data_chiusura')
                    ->selectRaw('AVG((JULIANDAY(data_chiusura) - JULIANDAY(data_segnalazione))) as v')
                    ->value('v') ?? 0
            );
        } else {
            $tempoMedioGg = round(
                Segnalazione::visibileA($user)
                    ->whereNotNull('data_chiusura')
                    ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, data_segnalazione, data_chiusura) / 24) as v')
                    ->value('v') ?? 0
            );
        }

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
        if (DB::getDriverName() === 'sqlite') {
            $trendSettimanale = Segnalazione::visibileA($user)
                ->select(
                    DB::raw("strftime('%Y%W', data_segnalazione) as settimana"),
                    DB::raw('COUNT(*) as totale')
                )
                ->where('data_segnalazione', '>=', now()->subWeeks(8))
                ->groupBy('settimana')
                ->orderBy('settimana')
                ->get();

            $trendLabel  = $trendSettimanale->map(fn ($r) => 'S' . substr((string) $r->settimana, 4))->toArray();
        } else {
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
        }
        $trendTotali = $trendSettimanale->pluck('totale')->toArray();

        // KPI tempi medi permanenza per stato (PHP collection, compat SQLite)
        $kpiTransizioni = Cache::remember('statistiche.kpi_transizioni', now()->addHours(2), function () {
            $storicoAll = StoricoStatoSegnalazione::query()
                ->orderBy('id_segnalazione')
                ->orderBy('data_registrazione')
                ->get()
                ->groupBy('id_segnalazione');

            $accumulator = [];

            foreach ($storicoAll as $records) {
                $records = $records->values();
                for ($i = 0; $i < $records->count() - 1; $i++) {
                    $curr = $records[$i];
                    $next = $records[$i + 1];
                    $ore  = $curr->data_registrazione->diffInHours($next->data_registrazione);
                    $id   = $curr->id_stato_segnalazione;
                    $accumulator[$id][] = $ore;
                }
            }

            $stati = StatoSegnalazione::all()->keyBy('id_stato');

            return collect($accumulator)
                ->map(function (array $ore, int $idStato) use ($stati): array {
                    return [
                        'id_stato'    => $idStato,
                        'descrizione' => $stati->get($idStato)?->descrizione ?? "Stato {$idStato}",
                        'ore_medie'   => round(array_sum($ore) / count($ore), 1),
                        'gg_medi'     => round(array_sum($ore) / count($ore) / 24, 1),
                        'campioni'    => count($ore),
                    ];
                })
                ->sortBy('id_stato')
                ->values();
        });

        return view('statistiche.index', compact(
            'kpi',
            'mesiLabel', 'mesiTotali',
            'tipologiaLabel', 'tipologiaTotali',
            'statoLabel', 'statoTotali',
            'tempoMedioGg', 'slaCompliance',
            'caricoLabel', 'caricoTotali',
            'trendLabel', 'trendTotali',
            'kpiTransizioni',
        ));
    }
}
