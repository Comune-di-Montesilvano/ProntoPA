<?php

namespace App\Services\Segnalazioni;

use App\Enums\SegnalazioneStato;
use App\Jobs\InviaWebhookOutbound;
use App\Models\NotaSegnalazione;
use App\Models\RichiestaInformazioni;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\RichiestaInformazioniNotification;
use App\Notifications\RispostaInformazioniNotification;
use App\Services\Sla\CalendarioUfficio;
use App\Services\SlaService;
use App\Support\Segnalazioni\LetturaScheda;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Il comune chiede informazioni a chi ha segnalato (spec 1.0.2, E): la segnalazione va in
 * "In attesa di risposta" (11) con l'assegnatario invariato e la scadenza ferma; la prima
 * nota o il primo allegato di chi ha segnalato la riporta dov'era.
 */
class RichiesteInformazioni
{
    public function __construct(private readonly SlaService $sla) {}

    public function chiedi(Segnalazione $s, User $gestore, string $testo, int $giorni, bool $chiudiSenzaRisposta): RichiestaInformazioni
    {
        $richiesta = DB::transaction(function () use ($s, $gestore, $testo, $giorni, $chiudiSenzaRisposta) {
            $s = Segnalazione::whereKey($s->id_segnalazione)->lockForUpdate()->firstOrFail();
            $calendario = CalendarioUfficio::daImpostazioni();
            $termine = $calendario->aggiungiGiorniLavorativi(now(), max(1, min(30, $giorni))) ?? now()->addDays($giorni);

            $richiesta = RichiestaInformazioni::create([
                'id_segnalazione' => $s->id_segnalazione,
                'id_utente' => $gestore->id,
                'testo' => $testo,
                'stato_precedente' => $s->statoEnum()->value,
                'termine_at' => $termine,
                'chiudi_senza_risposta' => $chiudiSenzaRisposta,
            ]);

            $s->update(['id_stato_segnalazione' => SegnalazioneStato::ATTESA_RISPOSTA->value]);
            $this->storico($s, SegnalazioneStato::ATTESA_RISPOSTA, $gestore->id);
            $s->note()->create([
                'testo' => $testo,
                'id_utente' => $gestore->id,
                'visibile_web' => true,
                'visibile_impresa' => (bool) $s->id_appalto,
                'origine' => NotaSegnalazione::ORIGINE_RICHIESTA,
            ]);

            return $richiesta;
        });

        $fresca = $s->fresh();
        $autore = User::find((int) $fresca->id_utente_segnalazione);
        if ($autore && $autore->email) {
            $autore->notify(new RichiestaInformazioniNotification($fresca, $testo, $richiesta->termine_at));
        }
        InviaWebhookOutbound::dispatch($fresca);

        return $richiesta;
    }

    /**
     * Nota o allegato di qualcuno che legge come "segnalante" e vede la segnalazione (autore o aderente):
     * conclude la richiesta aperta. Restituisce false se non c'era nulla da concludere.
     */
    public function registraRisposta(Segnalazione $s, User $autore, ?NotaSegnalazione $nota = null): bool
    {
        if (LetturaScheda::per($autore) !== 'segnalante' || ! $autore->can('view', $s)) {
            return false;
        }

        $richiesta = DB::transaction(function () use ($s, $autore, $nota) {
            $richiesta = RichiestaInformazioni::aperte()->where('id_segnalazione', $s->id_segnalazione)->lockForUpdate()->first();
            if (! $richiesta) {
                return null;
            }
            $this->riprendi($richiesta, RichiestaInformazioni::ESITO_RISPOSTA, $autore->id, $nota?->id_nota);

            return $richiesta;
        });
        if (! $richiesta) {
            return false;
        }

        $fresca = $s->fresh();
        $destinatari = collect([User::find($richiesta->id_utente)])->filter(fn (?User $u) => $u && $u->attivo !== false && $u->email);
        if ($destinatari->isEmpty()) {
            $destinatari = DestinatariComune::per($fresca);
        }
        $operatore = $fresca->id_operatore_assegnato ? User::find($fresca->id_operatore_assegnato) : null;
        if ($operatore && $operatore->attivo !== false && $operatore->email) {
            $destinatari->push($operatore);
        }
        $destinatari = $destinatari->unique('id')->values();
        if ($destinatari->isNotEmpty()) {
            Notification::send($destinatari, new RispostaInformazioniNotification($fresca, (string) $autore->name, $nota?->testo));
        }
        InviaWebhookOutbound::dispatch($fresca);

        return true;
    }

    /** "Non serve più": la segnalazione torna dov'era, nota interna. */
    public function ritira(Segnalazione $s, User $gestore): void
    {
        DB::transaction(function () use ($s, $gestore) {
            $richiesta = RichiestaInformazioni::aperte()->where('id_segnalazione', $s->id_segnalazione)->lockForUpdate()->first();
            if (! $richiesta) {
                return;
            }
            $this->riprendi($richiesta, RichiestaInformazioni::ESITO_RITIRATA, $gestore->id);
            $s->note()->create([
                'testo' => 'Richiesta di informazioni ritirata.',
                'id_utente' => $gestore->id,
                'visibile_web' => false,
                'visibile_impresa' => false,
            ]);
        });

        InviaWebhookOutbound::dispatch($s->fresh());
    }

    /**
     * Giro giornaliero: promemoria a 2 giorni lavorativi dal termine (una volta), poi a termine passato
     * chiusura senza intervento (con la spunta) o ritorno al gestore. Una richiesta per transazione:
     * un errore su una non ferma le altre.
     *
     * @return array{promemoria: int, chiuse: int, tornate: int}
     */
    public function controlla(?CarbonInterface $ora = null): array
    {
        $ora = Carbon::instance($ora ?? now());
        $calendario = CalendarioUfficio::daImpostazioni();
        $esito = ['promemoria' => 0, 'chiuse' => 0, 'tornate' => 0];

        foreach (RichiestaInformazioni::aperte()->orderBy('id')->get() as $r) {
            try {
                if ($r->termine_at->lte($ora)) {
                    if ($this->scaduta($r)) {
                        $esito[$r->chiudi_senza_risposta ? 'chiuse' : 'tornate']++;
                    }
                } elseif ($r->promemoria_at === null && $this->giorniLavorativiRimasti($calendario, $ora, $r->termine_at) <= 2) {
                    $this->promemoria($r);
                    $esito['promemoria']++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $esito;
    }

    /** Giorni lavorativi dopo oggi fino al giorno del termine compreso. */
    private function giorniLavorativiRimasti(CalendarioUfficio $calendario, Carbon $ora, CarbonInterface $termine): int
    {
        $n = 0;
        for ($g = $ora->copy()->startOfDay()->addDay(); $g->lte($termine); $g->addDay()) {
            $n += $calendario->fascia($g->copy()) !== null ? 1 : 0;
        }

        return $n;
    }

    private function promemoria(RichiestaInformazioni $r): void
    {
        $r->update(['promemoria_at' => now()]);
        $s = $r->segnalazione;
        $autore = $s ? User::find((int) $s->id_utente_segnalazione) : null;
        if ($autore && $autore->email && $autore->attivo !== false) {
            $autore->notify(new RichiestaInformazioniNotification($s, $r->testo, $r->termine_at, promemoria: true));
        }
    }

    /** Termine passato senza risposta. Restituisce false se nel frattempo era già conclusa. */
    private function scaduta(RichiestaInformazioni $r): bool
    {
        $fatto = DB::transaction(function () use ($r) {
            $r = RichiestaInformazioni::aperte()->whereKey($r->id)->lockForUpdate()->first();
            if (! $r) {
                return false;
            }
            if (! $r->chiudi_senza_risposta) {
                $this->riprendi($r, RichiestaInformazioni::ESITO_CHIUSA, 0);

                return true;
            }

            $r->update(['conclusa_at' => now(), 'esito' => RichiestaInformazioni::ESITO_CHIUSA]);
            $s = Segnalazione::whereKey($r->id_segnalazione)->lockForUpdate()->firstOrFail();
            $s->update(['id_stato_segnalazione' => SegnalazioneStato::ANNULLATA->value, 'data_chiusura' => now()]);
            $this->storico($s, SegnalazioneStato::ANNULLATA, 0);
            $s->note()->create([
                'testo' => 'Chiusa senza intervento: '.\App\Services\SegnalazioneWorkflowService::MOTIVI_SENZA_INTERVENTO['nessuna_risposta'].'.',
                'id_utente' => 0,
                'visibile_web' => true,
                'visibile_impresa' => (bool) $s->id_appalto,
                'origine' => NotaSegnalazione::ORIGINE_SENZA_INTERVENTO,
            ]);

            return true;
        });
        if (! $fatto) {
            return false;
        }

        $s = Segnalazione::find($r->id_segnalazione);
        if ($r->chiudi_senza_risposta) {
            $autore = User::find((int) $s->id_utente_segnalazione);
            $azione = \App\Models\Azione::where('codice', 'annulla')->first();
            if ($autore && $autore->attivo !== false && $azione) {
                $autore->notify(new \App\Notifications\SegnalazioneChiusaNotification($s, $azione, new User(['name' => 'Sistema'])));
            }
        } else {
            $gestore = User::find($r->id_utente);
            $destinatari = $gestore && $gestore->attivo !== false && $gestore->email ? collect([$gestore]) : DestinatariComune::per($s);
            if ($destinatari->isNotEmpty()) {
                Notification::send($destinatari, new RispostaInformazioniNotification($s, 'Sistema', null, senzaRisposta: true));
            }
        }
        InviaWebhookOutbound::dispatch($s);

        return true;
    }

    /**
     * Conclude la richiesta, riporta la segnalazione allo stato di prima e sposta la scadenza
     * delle ore lavorative passate in attesa. Da chiamare dentro una transazione.
     */
    public function riprendi(RichiestaInformazioni $r, string $esito, int $idAutore, ?int $idNota = null): void
    {
        $r->update(['conclusa_at' => now(), 'esito' => $esito, 'id_nota_risposta' => $idNota]);

        $s = Segnalazione::whereKey($r->id_segnalazione)->lockForUpdate()->firstOrFail();
        $stato = SegnalazioneStato::tryFrom($r->stato_precedente) ?? SegnalazioneStato::IN_CARICO;
        $s->id_stato_segnalazione = $stato->value;

        $calendario = CalendarioUfficio::daImpostazioni();
        if ($s->scadenza_manuale && $s->data_scadenza_sla) {
            // Data fissata a mano: spostata una volta, adesso, delle sole ore di questa attesa.
            $ore = $r->created_at ? $calendario->oreLavorativeTra($r->created_at, $r->conclusa_at) : 0.0;
            $nuova = $ore > 0 ? $calendario->aggiungiOreLavorative($s->data_scadenza_sla, $ore) : null;
            if ($nuova) {
                $s->data_scadenza_sla = $nuova;
                $s->note()->create([
                    'testo' => 'Scadenza spostata al '.$nuova->format('d/m/Y').': ferma in attesa di una risposta.',
                    'id_utente' => 0,
                    'visibile_web' => false,
                    'visibile_impresa' => false,
                    'origine' => NotaSegnalazione::ORIGINE_SCADENZA,
                ]);
            }
        } elseif ($scadenza = $this->sla->calcolaScadenza($s)) {
            $s->data_scadenza_sla = $scadenza;
        }
        if ($s->data_scadenza_sla && $s->data_scadenza_sla->isFuture()) {
            $s->sla_violato = false;
            $s->sla_warning_inviato = false;
        }
        $s->save();

        $this->storico($s, $stato, $idAutore);
    }

    private function storico(Segnalazione $s, SegnalazioneStato $stato, int $idAutore): void
    {
        // Collegato e appalto a 0: l'assegnatario resta quello di prima (StoricoOrdinato).
        StoricoStatoSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione,
            'id_stato_segnalazione' => $stato->value,
            'id_utente' => $idAutore,
            'id_utente_collegato' => 0,
            'id_appalto' => 0,
        ]);
    }
}
