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
            ->with(['stato', 'tipologia'])
            ->orderByDesc('data_segnalazione')
            ->paginate(20);

        return view('segnalatore.dashboard', compact('segnalazioni'));
    }
}
