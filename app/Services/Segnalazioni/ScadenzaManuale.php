<?php

namespace App\Services\Segnalazioni;

use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Sla\CalendarioUfficio;
use App\Services\SlaService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Scadenza fissata a mano dal gestore: vale fino alla chiusura dell'ufficio del giorno scelto e nessun
 * ricalcolo automatico (priorità, regole SLA, transizioni) la tocca finché non la si rimuove.
 */
class ScadenzaManuale
{
    public function __construct(private readonly SlaService $sla) {}

    public function imposta(Segnalazione $s, CarbonInterface $giorno, User $autore): void
    {
        $tz = config('app.timezone');
        $giorno = Carbon::parse($giorno->format('Y-m-d'), $tz);

        if ($giorno->lt(Carbon::now($tz)->startOfDay())) {
            throw ValidationException::withMessages([
                'data_scadenza' => 'La nuova scadenza non può essere nel passato.',
            ]);
        }

        $calendario = CalendarioUfficio::daImpostazioni();
        $scadenza = $calendario->fascia($giorno)[1]
            ?? $calendario->aggiungiGiorniLavorativi($giorno, 1)
            ?? $giorno->copy()->endOfDay();

        $s->forceFill([
            'data_scadenza_sla' => $scadenza,
            'scadenza_manuale' => true,
            'sla_violato' => false,
            'sla_warning_inviato' => false,
        ])->save();

        $s->note()->create([
            'testo' => 'Scadenza spostata al '.$scadenza->format('d/m/Y').'.',
            'id_utente' => $autore->id,
            'visibile_web' => false,
            'visibile_impresa' => true,
        ]);
    }

    public function rimuovi(Segnalazione $s): void
    {
        $s->scadenza_manuale = false;
        $scadenza = $this->sla->calcolaScadenza($s);
        if ($scadenza) {
            $s->data_scadenza_sla = $scadenza;
            // La segna (e avvisa) sla:check, come per ogni scadenza appena superata.
            $s->sla_violato = false;
            $s->sla_warning_inviato = false;
        }
        $s->save();
    }
}
