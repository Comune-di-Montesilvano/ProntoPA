<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Impostazione;
use App\Models\StatoSegnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImpostazioniController extends Controller
{
    public function index(): View
    {
        $impostazioni = Impostazione::orderBy('gruppo')->orderBy('chiave')->get()
            ->groupBy('gruppo');

        $statiSegnalazioni = StatoSegnalazione::orderBy('id_stato')->get();

        return view('admin.impostazioni', compact('impostazioni', 'statiSegnalazioni'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'impostazioni'          => ['required', 'array'],
            'impostazioni.*'        => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($data['impostazioni'] as $chiave => $valore) {
            // Cast specifici per certi campi
            if ($chiave === 'publication_auto_state_id' && $valore !== '' && $valore !== null) {
                $valore = (int) $valore;
            }

            Impostazione::set($chiave, $valore);
        }

        return back()->with('success', 'Impostazioni salvate.');
    }
}
