<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RicalcolaScadenzeSla;
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
        RicalcolaScadenzeSla::dispatch();

        return redirect()->route('admin.sla.index')->with('success', 'Regola SLA creata: scadenze delle segnalazioni aperte in aggiornamento.');
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
        RicalcolaScadenzeSla::dispatch();

        return redirect()->route('admin.sla.index')->with('success', 'Regola SLA aggiornata: scadenze delle segnalazioni aperte in aggiornamento.');
    }

    public function destroy(SlaConfigurazione $sla): RedirectResponse
    {
        $sla->delete();
        RicalcolaScadenzeSla::dispatch();

        return redirect()->route('admin.sla.index')->with('success', 'Regola SLA eliminata: scadenze delle segnalazioni aperte in aggiornamento.');
    }

    private function validate(Request $request): array
    {
        return $request->validate([
            'id_tipologia_segnalazione' => ['nullable', 'integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
            'id_specializzazione'       => ['nullable', 'integer', 'exists:db_specializzazioni,id_specializzazione'],
            'livello_priorita'          => ['required', 'integer', 'between:1,4'],
            'ore_target'                => ['required', 'integer', 'min:1'],
            'unita'                     => ['required', 'in:ore,giorni'],
            'ore_warning'               => ['required', 'integer', 'min:1'],
            'giorni_avviso_inattivita'        => ['nullable', 'integer', 'min:1', 'max:365'],
            'giorni_archiviazione_inattivita' => ['nullable', 'integer', 'min:1', 'max:365'],
            'descrizione'               => ['nullable', 'string', 'max:100'],
        ]);
    }
}
