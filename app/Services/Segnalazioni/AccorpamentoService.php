<?php

namespace App\Services\Segnalazioni;

use App\Enums\SegnalazioneStato;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\AccorpataNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Accorpa una segnalazione arrivata dopo a una già aperta sullo stesso guasto:
 * la nuova si chiude come "Accorpata" puntando alla vecchia, chi l'ha mandata diventa
 * aderente della vecchia, che ne esce sollecitata (nota col testo nuovo, avviso a chi ci lavora).
 */
class AccorpamentoService
{
    private const MAX_SALTI = 5;

    /** @return Segnalazione la destinazione effettiva (risalita alla madre se serve) */
    public function accorpa(Segnalazione $nuova, Segnalazione $destinazione, User $attore): Segnalazione
    {
        $destinazione = $this->destinazioneEffettiva($destinazione);

        $errore = match (true) {
            $destinazione->id_segnalazione === $nuova->id_segnalazione => 'Non puoi accorpare una segnalazione a sé stessa.',
            $destinazione->isChiusa() => 'La segnalazione scelta è già chiusa.',
            $nuova->isChiusa() => 'Questa segnalazione è già chiusa.',
            default => null,
        };
        if ($errore !== null) {
            throw ValidationException::withMessages(['id_destinazione' => $errore]);
        }

        DB::transaction(function () use ($nuova, $destinazione, $attore) {
            $nuova->allegati()->update(['id_segnalazione' => $destinazione->id_segnalazione]);
            $nuova->adesioni()->update(['id_segnalazione' => $destinazione->id_segnalazione]);

            $this->aggiungiAderente($nuova, $destinazione, $attore);

            $nuova->update([
                'id_stato_segnalazione' => SegnalazioneStato::DUPLICATA->value,
                'id_segnalazione_madre' => $destinazione->id_segnalazione,
                'data_chiusura' => now(),
            ]);
            StoricoStatoSegnalazione::create([
                'id_segnalazione' => $nuova->id_segnalazione,
                'id_stato_segnalazione' => SegnalazioneStato::DUPLICATA->value,
                'id_utente' => $attore->id,
                'id_utente_collegato' => 0,
                'id_appalto' => 0,
            ]);

            $quando = ($nuova->data_segnalazione ?? now())->format('d/m/Y');
            $destinazione->note()->create([
                'testo' => "Segnalata di nuovo da {$this->nomeSegnalante($nuova)} il {$quando}:\n{$nuova->testo_segnalazione}",
                'id_utente' => $attore->id,
                'visibile_web' => false,
                'visibile_impresa' => (bool) $destinazione->id_appalto,
            ]);
            $nuova->note()->create([
                'testo' => "Era già stata segnalata: segui gli aggiornamenti nella #{$destinazione->id_segnalazione}.",
                'id_utente' => $attore->id,
                'visibile_web' => true,
                'visibile_impresa' => false,
            ]);
        });

        $this->avvisa($nuova->fresh() ?? $nuova, $destinazione->fresh() ?? $destinazione, $attore);

        return $destinazione;
    }

    /** Una destinazione già accorpata rimanda alla sua madre (catena corta, mai cicli infiniti). */
    public function destinazioneEffettiva(Segnalazione $s): Segnalazione
    {
        for ($i = 0; $i < self::MAX_SALTI; $i++) {
            if ($s->statoEnum() !== SegnalazioneStato::DUPLICATA || ! $s->id_segnalazione_madre) {
                break;
            }
            $madre = Segnalazione::find($s->id_segnalazione_madre);
            if ($madre === null) {
                break;
            }
            $s = $madre;
        }

        return $s;
    }

    private function aggiungiAderente(Segnalazione $nuova, Segnalazione $destinazione, User $attore): void
    {
        $idUtente = (int) $nuova->id_utente_segnalazione;

        if ($idUtente > 0 && ($idUtente === (int) $destinazione->id_utente_segnalazione
            || $destinazione->adesioni()->where('id_utente', $idUtente)->exists())) {
            return;
        }

        // id_utente è obbligatorio: per chi ha segnalato senza account resta l'attore, con l'anagrafica.
        $destinazione->adesioni()->create([
            'id_utente' => $idUtente > 0 ? $idUtente : $attore->id,
            'segnalante' => $nuova->segnalante,
            'telefono' => $nuova->telefono,
            'email' => $nuova->email,
        ]);
    }

    private function nomeSegnalante(Segnalazione $s): string
    {
        if (filled($s->segnalante)) {
            return (string) $s->segnalante;
        }

        $utente = $s->id_utente_segnalazione ? User::find($s->id_utente_segnalazione) : null;

        return $utente !== null && $utente->name ? $utente->name : 'un altro segnalante';
    }

    private function avvisa(Segnalazione $nuova, Segnalazione $destinazione, User $attore): void
    {
        $lavoro = new AccorpataNotification($destinazione, $nuova, 'lavoro');
        $assegnatario = DestinatariAssegnatario::per($destinazione, $attore);

        if ($assegnatario['utenti']->isNotEmpty() || $assegnatario['email'] !== []) {
            Notification::send($assegnatario['utenti'], $lavoro);
            foreach ($assegnatario['email'] as $email) {
                Notification::route('mail', $email)->notify($lavoro);
            }
        } else {
            $comune = DestinatariComune::per($destinazione, $attore);
            if ($comune->isNotEmpty()) {
                Notification::send($comune, $lavoro);
            }
        }

        $segnalante = new AccorpataNotification($destinazione, $nuova, 'segnalante');
        $utente = $nuova->id_utente_segnalazione ? User::find($nuova->id_utente_segnalazione) : null;

        if ($utente !== null) {
            if ($utente->id !== $attore->id && $utente->attivo !== false) {
                $utente->notify($segnalante);
            }
        } elseif (filled($nuova->email)) {
            Notification::route('mail', (string) $nuova->email)->notify($segnalante);
        }
    }
}
