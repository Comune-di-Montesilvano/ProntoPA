<?php

namespace App\Http\Controllers;

use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\TipologiaSegnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppaltiController extends Controller
{
    public function index(): View
    {
        $appalti = Appalto::with(['impresa', 'tipologie'])
            ->orderByDesc('id_appalto')
            ->paginate(30);

        return view('appalti.index', compact('appalti'));
    }

    public function create(): View
    {
        $imprese = Impresa::orderBy('ragione_sociale')->get();
        $tipologie = TipologiaSegnalazione::orderBy('descrizione')->get();

        return view('appalti.create', compact('imprese', 'tipologie'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->valida($request);

        $this->salvaTipologie(Appalto::create($data), $request);

        return redirect()->route('appalti.index')
            ->with('success', 'Appalto creato.');
    }

    public function edit(Appalto $appalto): View
    {
        $imprese = Impresa::orderBy('ragione_sociale')->get();
        $tipologie = TipologiaSegnalazione::orderBy('descrizione')->get();

        return view('appalti.edit', compact('appalto', 'imprese', 'tipologie'));
    }

    public function update(Request $request, Appalto $appalto): RedirectResponse
    {
        $data = $this->valida($request);

        $appalto->update($data);
        $this->salvaTipologie($appalto, $request);

        return redirect()->route('appalti.index')
            ->with('success', 'Appalto aggiornato.');
    }

    public function destroy(Appalto $appalto): RedirectResponse
    {
        $appalto->delete();
        return redirect()->route('appalti.index')
            ->with('success', 'Appalto eliminato.');
    }

    /** @return array<string, mixed> */
    private function valida(Request $request): array
    {
        $data = $request->validate([
            'descrizione'     => ['required', 'string', 'max:100'],
            'id_impresa'      => ['nullable', 'integer', 'exists:imprese,id_impresa'],
            'CIG'             => ['nullable', 'string', 'max:50'],
            'importo_appalto' => ['nullable', 'numeric', 'min:0'],
            'valido'          => ['boolean'],
            'tutte_tipologie' => ['boolean'],
            'tipologie'       => ['array'],
            'tipologie.*'     => ['integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
        ]);
        $data['valido'] = $request->boolean('valido');
        $data['tutte_tipologie'] = $request->boolean('tutte_tipologie');
        unset($data['tipologie']);

        return $data;
    }

    /** Con "tutte" l'elenco esplicito non serve: lo svuotiamo (come per i gestori). */
    private function salvaTipologie(Appalto $appalto, Request $request): void
    {
        $appalto->tipologie()->sync($appalto->tutte_tipologie ? [] : array_map('intval', (array) $request->input('tipologie', [])));
    }
}
