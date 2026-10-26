<?php

namespace App\Services\Segnalazioni;

use App\Enums\SegnalazioneStato;
use App\Jobs\InviaWebhookOutbound;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\ArchiviataInattivitaNotification;
use App\Notifications\InattivitaNotification;
use App\Notifications\RiepilogoInattivitaNotification;
use App\Services\Sla\CalendarioUfficio;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Segnalazioni ferme: superata la soglia della regola SLA si avvisano chi ha aperto, chi ha in
 * carico e i supervisori; se dopo l'avviso nessuno fa nulla, la segnalazione si archivia.
 * Vale anche quando è affidata a ditta o operaio: affidarla serve a sgravare il comune.
 */
class Inattivita
{
    private CalendarioUfficio $calendario;

    public function __construct()
    {
        $this->calendario = CalendarioUfficio::daImpostazioni();
    }

    /** @return array{avvisate: list<Segnalazione>, archiviate: list<Segnalazione>} */
    public function esegui(): array
    {
        $avvisate = [];
        $archiviate = [];

        Segnalazione::aperte()
            ->where('id_stato_segnalazione', '!=', SegnalazioneStato::SOSPESA->value)
            ->chunkById(200, function ($segnalazioni) use (&$avvisate, &$archiviate) {
                foreach ($segnalazioni as $s) {
                    match ($this->valuta($s)) {
                        'avvisata'   => $avvisate[] = $s,
                        'archiviata' => $archiviate[] = $s,
                        default      => null,
                    };
                }
            }, 'id_segnalazione');

        if ($avvisate || $archiviate) {
            Notification::send(self::supervisori(), new RiepilogoInattivitaNotification($avvisate, $archiviate));
        }

        return ['avvisate' => $avvisate, 'archiviate' => $archiviate];
    }

    /** Data in cui verrà archiviata, se l'avviso è partito e la regola prevede l'archiviazione. */
    public function dataArchiviazione(Segnalazione $s): ?Carbon
    {
        $giorni = self::regola($s)?->giorni_archiviazione_inattivita;
        if (! $s->avviso_inattivita_at || ! $giorni || $s->isChiusa()) {
            return null;
        }

        return $this->calendario->aggiungiGiorniLavorativi($s->avviso_inattivita_at, $giorni);
    }

    private function valuta(Segnalazione $s): ?string
    {
        $regola = self::regola($s);
        if (! $regola?->giorni_avviso_inattivita) {
            return null;
        }
        $ultima = $s->ultimaAttivita();

        if ($s->avviso_inattivita_at && $ultima->gt($s->avviso_inattivita_at)) {
            $s->avviso_inattivita_at = null;
            $s->saveQuietly();
        }

        if (! $s->avviso_inattivita_at) {
            $soglia = $this->calendario->aggiungiGiorniLavorativi($ultima, $regola->giorni_avviso_inattivita);
            if (! $soglia || $soglia->isFuture()) {
                return null;
            }
            $s->avviso_inattivita_at = now();
            $s->saveQuietly();
            $this->notifica($s, new InattivitaNotification($s, $this->dataArchiviazione($s), $ultima));

            return 'avvisata';
        }

        $archiviazione = $this->dataArchiviazione($s);
        if (! $archiviazione || $archiviazione->isFuture()) {
            return null;
        }
        // I destinatari si calcolano prima: dopo l'archiviazione lo stato non dice più chi l'aveva.
        $destinatari = $this->destinatari($s);
        $this->archivia($s);
        $this->invia($destinatari, new ArchiviataInattivitaNotification($s));

        return 'archiviata';
    }

    private function archivia(Segnalazione $s): void
    {
        $s->update([
            'id_stato_segnalazione' => SegnalazioneStato::ARCHIVIATA->value,
            'data_chiusura'         => now(),
            'sla_violato'           => false,
        ]);
        // Autore 0 = sistema (come id_utente_collegato).
        StoricoStatoSegnalazione::create([
            'id_segnalazione'       => $s->id_segnalazione,
            'id_stato_segnalazione' => SegnalazioneStato::ARCHIVIATA->value,
            'id_utente'             => 0,
            'id_utente_collegato'   => 0,
            'id_appalto'            => 0,
        ]);
        $s->note()->create([
            'testo'            => 'Archiviata per inattività: nessun aggiornamento dopo l\'avviso. Si può riaprire se il problema c\'è ancora.',
            'id_utente'        => 0,
            'visibile_web'     => true,
            'visibile_impresa' => (bool) $s->id_appalto,
        ]);
        InviaWebhookOutbound::dispatch($s->fresh());
    }

    private function notifica(Segnalazione $s, BaseNotification $notifica): void
    {
        $this->invia($this->destinatari($s), $notifica);
    }

    /** @param  array{utenti: Collection<int, User>, email: list<string>}  $destinatari */
    private function invia(array $destinatari, BaseNotification $notifica): void
    {
        if ($destinatari['utenti']->isNotEmpty()) {
            Notification::send($destinatari['utenti'], $notifica);
        }
        foreach ($destinatari['email'] as $email) {
            Notification::route('mail', $email)->notify($notifica);
        }
    }

    /**
     * Chi ha aperto e chi ha in carico: ditta, operaio o caposquadra; prima dell'affidamento il
     * gestore assegnato (i supervisori hanno il riepilogo).
     *
     * @return array{utenti: Collection<int, User>, email: list<string>}
     */
    private function destinatari(Segnalazione $s): array
    {
        $carico = DestinatariAssegnatario::per($s);
        $utenti = $carico['utenti'];

        if ($utenti->isEmpty() && $carico['email'] === [] && $s->id_operatore_assegnato) {
            $gestore = User::find($s->id_operatore_assegnato);
            if ($gestore && $gestore->attivo !== false && $gestore->hasAnyRole(['gestore', 'admin'])) {
                $utenti = collect([$gestore]);
            }
        }

        $autore = $s->id_utente_segnalazione ? User::find($s->id_utente_segnalazione) : null;
        if ($autore && $autore->attivo !== false) {
            $utenti = $utenti->push($autore);
        }

        return ['utenti' => $utenti->unique('id')->values(), 'email' => $carico['email']];
    }

    /** @return Collection<int, User> */
    private static function supervisori(): Collection
    {
        return User::role('gestore')->where('supervisore_segnalazioni', true)->get()
            ->filter(fn (User $u) => $u->attivo !== false)->values();
    }

    private static function regola(Segnalazione $s): ?SlaConfigurazione
    {
        return SlaConfigurazione::applicabileA(
            $s->id_tipologia_segnalazione,
            $s->id_specializzazione,
            $s->livello_priorita ?? 2,
        );
    }
}
