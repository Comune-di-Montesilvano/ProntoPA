<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use App\Models\StatoSegnalazione;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GestioneController extends Controller
{
    /**
     * Dashboard gestione con tab:
     * evidenza | in_carico | in_gestione | aperte | chiuse
     */
    public function index(Request $request): View
    {
        $user = auth()->user();
        $tab  = $request->get('tab', 'aperte');

        $base = Segnalazione::visibileA($user)->with(['stato', 'tipologia', 'operatore', 'provenienza']);

        $segnalazioni = match($tab) {
            'evidenza'     => (clone $base)->inEvidenza()
                                ->orderByDesc('data_segnalazione')->paginate(30),
            'in_carico'    => (clone $base)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('in_carico', true))
                                ->orderByDesc('data_segnalazione')->paginate(30),
            'in_gestione'  => (clone $base)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('id_gestione', true))
                                ->orderByDesc('data_segnalazione')->paginate(30),
            'chiuse'       => (clone $base)->whereNotNull('data_chiusura')
                                ->orderByDesc('data_chiusura')->paginate(30),
            default        => (clone $base)->aperte()
                                ->orderByDesc('data_segnalazione')->paginate(30),
        };

        // Conteggi per i badge dei tab
        $conteggi = [
            'evidenza'    => Segnalazione::visibileA($user)->inEvidenza()->count(),
            'in_carico'   => Segnalazione::visibileA($user)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('in_carico', true))->count(),
            'in_gestione' => Segnalazione::visibileA($user)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('id_gestione', true))->count(),
            'aperte'      => Segnalazione::visibileA($user)->aperte()->count(),
            'chiuse'      => Segnalazione::visibileA($user)->whereNotNull('data_chiusura')->count(),
        ];

        return view('gestione.dashboard', compact('segnalazioni', 'tab', 'conteggi'));
    }
}
