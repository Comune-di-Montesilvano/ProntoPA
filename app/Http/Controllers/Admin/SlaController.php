<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlaConfigurazione;
use App\Models\Specializzazione;
use App\Models\TipologiaSegnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SlaController extends Controller
{
    public function index(): View
    {
        $regole = SlaConfigurazione::with(['tipologia', 'specializzazione'])
            ->orderBy('livello_priorita')
            ->get();

        return view('admin.sla.index', compact('regole'));
    }

    public function create(): View
    {
        return view('admin.sla.form', [
            'regola'          => new SlaConfigurazione(),
            'tipologie'       => TipologiaSegnalazione::orderBy('descrizione')->get(),
            'specializzazioni'=> Specializzazione::orderBy('descrizione')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validate($request);
        SlaConfigurazione::create($data);

        return redirect()->route('admin.sla.index')->with('success', 'Regola SLA creata.');
    }

    public function edit(SlaConfigurazione $sla): View
    {
        return view('admin.sla.form', [
            'regola'          => $sla,
            'tipologie'       => TipologiaSegnalazione::orderBy('descrizione')->get(),
            'specializzazioni'=> Specializzazione::orderBy('descrizione')->get(),
        ]);
    }

    public function update(Request $request, SlaConfigurazione $sla): RedirectResponse
    {
        $sla->update($this->validate($request));

        return redirect()->route('admin.sla.index')->with('success', 'Regola SLA aggiornata.');
    }

    public function destroy(SlaConfigurazione $sla): RedirectResponse
    {
        $sla->delete();

        return redirect()->route('admin.sla.index')->with('success', 'Regola SLA eliminata.');
    }

    private function validate(Request $request): array
    {
        return $request->validate([
            'id_tipologia_segnalazione' => ['nullable', 'integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
            'id_specializzazione'       => ['nullable', 'integer', 'exists:db_specializzazioni,id_specializzazione'],
            'livello_priorita'          => ['required', 'integer', 'between:1,4'],
            'ore_target'                => ['required', 'integer', 'min:1'],
            'ore_warning'               => ['required', 'integer', 'min:1'],
            'descrizione'               => ['nullable', 'string', 'max:100'],
        ]);
    }
}
