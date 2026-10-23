<?php

namespace App\Http\Controllers;

use App\Models\DelegaImpresa;
use App\Models\Impresa;
use App\Services\Deleghe\DelegaImpresaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Persone (SPID/CIE) che gestiscono una ditta, dalla scheda impresa. */
class DelegheImpresaController extends Controller
{
    public function __construct(private readonly DelegaImpresaService $deleghe) {}

    public function store(Request $request, Impresa $impresa): RedirectResponse
    {
        $data = $request->validate([
            'codice_fiscale' => ['required', 'string', 'max:30'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $this->deleghe->predelega($data['codice_fiscale'], $impresa, $data['motivo'] ?? null, $request->user());

        return back()->with('success', 'Persona delegata: potrà entrare con SPID o CIE.');
    }

    public function destroy(Request $request, Impresa $impresa, DelegaImpresa $delega): RedirectResponse
    {
        abort_unless($delega->id_impresa === $impresa->id_impresa, 404);

        $this->deleghe->revoca($delega, $request->user());

        return back()->with('success', 'Delega revocata.');
    }

    public function disattiva(Request $request, Impresa $impresa): RedirectResponse
    {
        $this->deleghe->disattivaImpresa($impresa, $request->user());

        return back()->with('success', 'Ditta disattivata: le persone delegate non hanno più accesso.');
    }

    public function riattiva(Impresa $impresa): RedirectResponse
    {
        $this->deleghe->riattivaImpresa($impresa);

        return back()->with('success', 'Ditta riattivata. Le deleghe revocate vanno ricreate.');
    }
}
