<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OperaioDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();
        $tab  = $request->get('tab', 'assegnate');

        $segnalazioni = match ($tab) {
            'chiuse' => Segnalazione::where('id_operatore_assegnato', $user->id)
                ->whereNotNull('data_chiusura')
                ->whereDate('data_chiusura', '>=', now()->subMonth())
                ->with(['stato', 'tipologia', 'plesso'])
                ->orderByDesc('data_chiusura')
                ->paginate(15),
            default => Segnalazione::where('id_operatore_assegnato', $user->id)
                ->aperte()
                ->with(['stato', 'tipologia', 'plesso'])
                ->orderBy('livello_priorita', 'desc')
                ->orderBy('data_segnalazione')
                ->paginate(15),
        };

        $kpi = $this->calcolaKpi($user->id);

        return view('operaio.dashboard', compact('segnalazioni', 'tab', 'kpi'));
    }

    public function mappa(): JsonResponse
    {
        $user = auth()->user();

        $punti = Segnalazione::where('id_operatore_assegnato', $user->id)
            ->aperte()
            ->where('latitudine', '!=', 0)
            ->where('longitudine', '!=', 0)
            ->with(['tipologia', 'stato'])
            ->get()
            ->map(fn ($s) => [
                'id'        => $s->id_segnalazione,
                'lat'       => $s->latitudine,
                'lng'       => $s->longitudine,
                'titolo'    => $s->tipologia?->descrizione ?? 'Segnalazione',
                'priorita'  => $s->label_priorita,
                'stato'     => $s->stato?->descrizione,
                'url'       => route('segnalazioni.show', $s->id_segnalazione),
            ]);

        return response()->json($punti);
    }

    public function statistiche(): View
    {
        $user = auth()->user();

        $perMese = Segnalazione::where('id_operatore_assegnato', $user->id)
            ->whereNotNull('data_chiusura')
            ->where('data_chiusura', '>=', now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(data_chiusura, '%Y-%m') as mese, COUNT(*) as totale")
            ->groupBy('mese')
            ->orderBy('mese')
            ->get();

        $perTipologia = Segnalazione::where('id_operatore_assegnato', $user->id)
            ->whereNotNull('data_chiusura')
            ->with('tipologia')
            ->selectRaw('id_tipologia_segnalazione, COUNT(*) as totale')
            ->groupBy('id_tipologia_segnalazione')
            ->orderByDesc('totale')
            ->limit(8)
            ->get();

        $tempoMedio = Segnalazione::where('id_operatore_assegnato', $user->id)
            ->whereNotNull('data_chiusura')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, data_segnalazione, data_chiusura) / 24) as giorni_medi')
            ->value('giorni_medi');

        $kpi = $this->calcolaKpi($user->id);

        return view('operaio.statistiche', compact('perMese', 'perTipologia', 'tempoMedio', 'kpi'));
    }

    private function calcolaKpi(int $userId): array
    {
        return [
            'aperte'         => Segnalazione::where('id_operatore_assegnato', $userId)->aperte()->count(),
            'chiuse_mese'    => Segnalazione::where('id_operatore_assegnato', $userId)
                                    ->whereNotNull('data_chiusura')
                                    ->whereDate('data_chiusura', '>=', now()->startOfMonth())
                                    ->count(),
            'tempo_medio_gg' => round(
                Segnalazione::where('id_operatore_assegnato', $userId)
                    ->whereNotNull('data_chiusura')
                    ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, data_segnalazione, data_chiusura) / 24) as v')
                    ->value('v') ?? 0
            ),
        ];
    }
}
