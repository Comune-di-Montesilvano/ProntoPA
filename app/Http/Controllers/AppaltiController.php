<?php

namespace App\Http\Controllers;

use App\Models\Appalto;
use App\Models\GruppoSegnalazione;
use App\Models\Impresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppaltiController extends Controller
{
    public function index(): View
    {
        $appalti = Appalto::with(['impresa', 'gruppo'])
            ->orderByDesc('id_appalto')
            ->paginate(30);

        return view('appalti.index', compact('appalti'));
    }

    public function create(): View
    {
        $imprese = Impresa::orderBy('ragione_sociale')->get();
        $gruppi  = GruppoSegnalazione::orderBy('descrizione')->get();

        return view('appalti.create', compact('imprese', 'gruppi'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_gruppo'       => ['required', 'integer', 'exists:gruppi_segnalazioni,id_gruppo'],
            'descrizione'     => ['required', 'string', 'max:100'],
            'id_impresa'      => ['nullable', 'integer', 'exists:imprese,id_impresa'],
            'CIG'             => ['nullable', 'string', 'max:50'],
            'importo_appalto' => ['nullable', 'numeric', 'min:0'],
            'valido'          => ['boolean'],
        ]);

        Appalto::create($data);

        return redirect()->route('appalti.index')
            ->with('success', 'Appalto creato.');
    }

    public function edit(Appalto $appalto): View
    {
        $imprese = Impresa::orderBy('ragione_sociale')->get();
        $gruppi  = GruppoSegnalazione::orderBy('descrizione')->get();

        return view('appalti.edit', compact('appalto', 'imprese', 'gruppi'));
    }

    public function update(Request $request, Appalto $appalto): RedirectResponse
    {
        $data = $request->validate([
            'id_gruppo'       => ['required', 'integer', 'exists:gruppi_segnalazioni,id_gruppo'],
            'descrizione'     => ['required', 'string', 'max:100'],
            'id_impresa'      => ['nullable', 'integer', 'exists:imprese,id_impresa'],
            'CIG'             => ['nullable', 'string', 'max:50'],
            'importo_appalto' => ['nullable', 'numeric', 'min:0'],
            'valido'          => ['boolean'],
        ]);

        $appalto->update($data);

        return redirect()->route('appalti.index')
            ->with('success', 'Appalto aggiornato.');
    }

    public function destroy(Appalto $appalto): RedirectResponse
    {
        $appalto->delete();
        return redirect()->route('appalti.index')
            ->with('success', 'Appalto eliminato.');
    }
}
