<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiTriageController extends Controller
{
    public function applicaTriage(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('update', $segnalazione);

        $triage = $segnalazione->triage_suggerito;

        if (! is_array($triage) || empty($triage)) {
            return back()->withErrors(['triage' => 'Nessun suggerimento triage disponibile.']);
        }

        $aggiornamento = [];

        if (isset($triage['id_tipologia_segnalazione'])) {
            $aggiornamento['id_tipologia_segnalazione'] = (int) $triage['id_tipologia_segnalazione'];
        }
        if (isset($triage['id_specializzazione'])) {
            $aggiornamento['id_specializzazione'] = (int) $triage['id_specializzazione'];
        }
        if (isset($triage['livello_priorita'])) {
            $aggiornamento['livello_priorita'] = (int) $triage['livello_priorita'];
        }

        $aggiornamento['triage_suggerito'] = null;

        $segnalazione->update($aggiornamento);

        return redirect()
            ->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Triage AI applicato.');
    }
}
