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

        $data = $request->validate([
            'segnalante' => ['nullable', 'string', 'max:255'],
            'telefono'   => ['nullable', 'string', 'max:50'],
            'email'      => ['nullable', 'email', 'max:255'],
        ]);

        $perConto = $user->can('segnalazioni.per-conto') && filled($data['segnalante'] ?? null);

        // Un utente "in proprio" aderisce una sola volta; l'URP può
        // registrare più chiamanti distinti sulla stessa segnalazione,
        // ma non lo stesso chiamante due volte.
        if (! $perConto) {
            $giaAderente = $segnalazione->adesioni()
                ->where('id_utente', $user->id)
                ->whereNull('segnalante')
                ->exists();

            if ($giaAderente) {
                throw ValidationException::withMessages([
                    'adesione' => 'Hai già aderito a questa segnalazione.',
                ]);
            }
        }

        if ($perConto) {
            $stessoChiamante = $segnalazione->adesioni()
                ->where('segnalante', $data['segnalante'])
                ->exists();

            if ($stessoChiamante) {
                throw ValidationException::withMessages([
                    'adesione' => 'Questo chiamante ha già un\'adesione su questa segnalazione.',
                ]);
            }
        }

        AdesioneSegnalazione::create([
            'id_segnalazione' => $segnalazione->id_segnalazione,
            'id_utente'       => $user->id,
            'segnalante'      => $perConto ? $data['segnalante'] : null,
            'telefono'        => $perConto ? ($data['telefono'] ?? null) : null,
            'email'           => $perConto ? ($data['email'] ?? null) : null,
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
