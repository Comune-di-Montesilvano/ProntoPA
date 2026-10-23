<?php

namespace App\Http\Controllers;

use App\Models\Impresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImpreseCRUDController extends Controller
{
    public function index(): View
    {
        $imprese = Impresa::withCount(['deleghe as deleghe_attive_count' => fn ($q) => $q->where('stato', \App\Models\DelegaImpresa::ATTIVA)])
            ->orderBy('ragione_sociale')->paginate(30);
        return view('imprese.index', compact('imprese'));
    }

    public function create(): View
    {
        return view('imprese.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ragione_sociale' => ['required', 'string', 'max:100'],
            'partita_iva'     => ['nullable', 'string', 'max:20'],
            'referente'       => ['nullable', 'string', 'max:50'],
            'email'           => ['nullable', 'email', 'max:100'],
            'cellulare'       => ['nullable', 'string', 'max:20'],
            'note'            => ['nullable', 'string', 'max:2000'],
        ]);

        Impresa::create($data);

        return redirect()->route('imprese.index')
            ->with('success', 'Impresa creata.');
    }

    public function edit(Impresa $impresa): View
    {
        return view('imprese.edit', compact('impresa'));
    }

    public function update(Request $request, Impresa $impresa): RedirectResponse
    {
        $data = $request->validate([
            'ragione_sociale' => ['required', 'string', 'max:100'],
            'partita_iva'     => ['nullable', 'string', 'max:20'],
            'referente'       => ['nullable', 'string', 'max:50'],
            'email'           => ['nullable', 'email', 'max:100'],
            'cellulare'       => ['nullable', 'string', 'max:20'],
            'note'            => ['nullable', 'string', 'max:2000'],
        ]);

        $impresa->update($data);

        return redirect()->route('imprese.index')
            ->with('success', 'Impresa aggiornata.');
    }

    public function destroy(Impresa $impresa): RedirectResponse
    {
        // Le deleghe sono lo storico di chi ha avuto accesso: si disattiva, non si elimina.
        if ($impresa->deleghe()->exists()) {
            return back()->with('error', 'La ditta ha persone delegate (anche revocate): disattivala invece di eliminarla.');
        }

        $impresa->delete();
        return redirect()->route('imprese.index')
            ->with('success', 'Impresa eliminata.');
    }
}
