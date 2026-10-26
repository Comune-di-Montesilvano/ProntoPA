<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use Illuminate\View\View;

class SegnalatoreDashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $segnalazioni = Segnalazione::visibileA($user)
            ->with(['tipologia', 'plesso'])
            ->withMax('storicoStati', 'data_registrazione')
            ->orderByDesc('data_segnalazione')
            ->paginate(20);
        $seguite = Segnalazione::idSeguiteDa($user);

        return view('segnalatore.dashboard', compact('segnalazioni', 'seguite'));
    }
}
