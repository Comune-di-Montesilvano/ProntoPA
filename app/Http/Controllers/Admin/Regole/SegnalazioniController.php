<?php

namespace App\Http\Controllers\Admin\Regole;

use App\Http\Controllers\Controller;
use App\Services\Impostazioni\SalvaImpostazioni;
use App\Services\PubblicazioneAutomatica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SegnalazioniController extends Controller
{
    public function index(): View
    {
        return view('admin.regole.segnalazioni');
    }

    public function update(Request $request, SalvaImpostazioni $salva): RedirectResponse
    {
        $salva->salva('SEGNALAZIONI', $request->input('impostazioni', []));

        return back()->with('success', 'Regole delle segnalazioni salvate.');
    }

    public function pubblicaEsistenti(PubblicazioneAutomatica $pubblicazione): RedirectResponse
    {
        $count = $pubblicazione->applicaAEsistenti();

        if ($count === null) {
            return back()->with('error', 'Pubblicazione automatica non attiva: impostala su "Sì", scegli lo stato e salva.');
        }

        return back()->with('success', "Regola applicata: {$count} segnalazioni pubblicate.");
    }
}
