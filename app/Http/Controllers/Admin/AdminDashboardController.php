<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\Riquadro;
use App\Services\Admin\StatoSistema;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    /** Cosa va guardato per primo: problemi, poi attenzioni, poi il non verificabile, poi il resto. */
    private const PRIORITA = [
        Riquadro::PROBLEMA => 0,
        Riquadro::ATTENZIONE => 1,
        Riquadro::NON_VERIFICABILE => 2,
        Riquadro::OK => 3,
    ];

    public function index(StatoSistema $stato): View
    {
        $riquadri = collect($stato->riquadri())
            ->sortBy(fn (Riquadro $r) => self::PRIORITA[$r->stato])
            ->values()
            ->all();

        return view('admin.stato.index', [
            'riquadri' => $riquadri,
            'spente' => $stato->spente(),
        ]);
    }
}
