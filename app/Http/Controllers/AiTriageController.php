<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use App\Models\TipologiaSegnalazione;
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

        // Suggerimenti vecchi possono avere id_specializzazione: ignorato (1.0.0).
        if (isset($triage['id_tipologia_segnalazione'])
            && TipologiaSegnalazione::attive()->whereKey((int) $triage['id_tipologia_segnalazione'])->exists()) {
            $aggiornamento['id_tipologia_segnalazione'] = (int) $triage['id_tipologia_segnalazione'];
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
