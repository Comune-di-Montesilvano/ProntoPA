<?php

namespace App\Http\Controllers;

use App\Models\AdesioneSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdesioniSegnalazioniController extends Controller
{
    public function store(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('adhere', $segnalazione);

        if (! Impostazione::get('adesioni_enabled', false)) {
            throw ValidationException::withMessages([
                'adesione' => 'Le adesioni non sono abilitate.',
            ]);
        }

        if ($segnalazione->isChiusa()) {
            throw ValidationException::withMessages([
                'adesione' => 'Impossibile aderire a una segnalazione chiusa.',
            ]);
        }

        $user = $request->user();

        // Una adesione per persona (indice unico a DB).
        if ($segnalazione->adesioni()->where('id_utente', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'adesione' => 'Hai già aderito a questa segnalazione.',
            ]);
        }

        AdesioneSegnalazione::create([
            'id_segnalazione' => $segnalazione->id_segnalazione,
            'id_utente'       => $user->id,
        ]);

        $this->escalaPriorita($segnalazione);

        return redirect()
            ->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Adesione registrata: riceverai notizia alla risoluzione.');
    }

    private function escalaPriorita(Segnalazione $segnalazione): void
    {
        $soglia = max(1, (int) Impostazione::get('adesioni_soglia_priorita', 3));
        $totale = $segnalazione->adesioni()->count();

        if ($totale % $soglia !== 0) {
            return;
        }

        $attuale = (int) ($segnalazione->livello_priorita ?? 2);
        if ($attuale >= 4) {
            return;
        }

        $segnalazione->update(['livello_priorita' => $attuale + 1]);

        $segnalazione->note()->create([
            'testo'            => 'Priorità aumentata a livello ' . ($attuale + 1) . ' dopo ' . $totale . ' ' . ($totale === 1 ? 'adesione' : 'adesioni') . '.',
            'id_utente'        => 0,
            'visibile_web'     => false,
            'visibile_impresa' => false,
        ]);
    }
}
