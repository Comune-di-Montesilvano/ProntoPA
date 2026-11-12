<?php

namespace App\Http\Controllers\Admin\Regole;

use App\Http\Controllers\Controller;
use App\Jobs\RicalcolaScadenzeSla;
use App\Models\SlaConfigurazione;
use App\Services\Impostazioni\SalvaImpostazioni;
use App\Services\Sla\CalendarioUfficio;
use App\Support\Impostazioni\Sezioni;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScadenzeController extends Controller
{
    public function index(): View
    {
        $regole = SlaConfigurazione::with(['tipologia', 'specializzazione'])
            ->orderBy('livello_priorita')
            ->get();
        $anno = now()->year;
        $festivi = collect(CalendarioUfficio::daImpostazioni()->festivi($anno))
            ->map(fn ($g) => $g->format('d/m/Y'))
            ->implode(' · ');

        return view('admin.regole.scadenze', compact('regole', 'anno', 'festivi'));
    }

    public function orario(Request $request, SalvaImpostazioni $salva): RedirectResponse
    {
        $cambiate = $salva->salva('SCADENZE', $request->input('impostazioni', []), (array) $request->input('impostazioni_rimuovi', []));

        if ($cambiate !== []) {
            RicalcolaScadenzeSla::dispatch();

            return back()->with('success', 'Orario salvato: scadenze delle segnalazioni aperte in aggiornamento.');
        }

        return back()->with('success', 'Orario salvato.');
    }
}
