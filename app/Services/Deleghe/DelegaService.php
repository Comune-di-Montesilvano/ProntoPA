<?php

namespace App\Services\Deleghe;

use App\Models\Delega;
use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use App\Notifications\Deleghe\AvvisoDelegheAdmin;
use App\Notifications\Deleghe\RichiestaDelegaNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Ciclo di vita delle deleghe scuola: richiesta, decisione della segreteria,
 * pre-delega e interventi admin, rinnovo annuale, scadenze. Ogni transizione
 * finisce in deleghe_storico.
 */
class DelegaService
{
    private const DEFAULT = [
        'deleghe_max_pendenti' => 3,
        'deleghe_giorni_stop_rifiuto' => 30,
        'deleghe_giorni_scadenza_richiesta' => 30,
        'deleghe_email_giorno_istituto' => 10,
        'deleghe_mesi_validita' => 12,
        'deleghe_giorni_avviso_delegato' => 7,
    ];

    public function regola(string $chiave): int
    {
        return max(1, (int) Impostazione::get($chiave, self::DEFAULT[$chiave]));
    }

    /**
     * @param list<int> $idPlessi vuoto = tutto l'istituto
     * @return array{gruppo: string, inviata: bool, esclusi: int}
     */
    public function richiedi(User $user, Istituto $istituto, array $idPlessi): array
    {
        if ($user->isBloccato()) {
            throw new RichiestaDelegaRifiutata("Non puoi richiedere deleghe. Contatta l'ente.");
        }

        if (blank($istituto->email)) {
            $this->avvisaAdmin('scuola senza email', "La scuola {$istituto->descrizione} non ha un indirizzo email: le richieste di delega non possono partire. Configurala da Admin → Anagrafe MIUR o Organizzazioni.");
            throw new RichiestaDelegaRifiutata("La scuola non è ancora configurata: abbiamo avvisato l'ente.");
        }

        $pendenti = Delega::where('user_id', $user->id)->where('stato', Delega::RICHIESTA);

        $stessa = (clone $pendenti)->where('id_istituto', $istituto->id_istituto)->oldest()->first();
        if ($stessa !== null) {
            throw new RichiestaDelegaRifiutata('Hai già una richiesta in attesa per questa scuola, inviata il '.$stessa->created_at?->format('d/m/Y').'.');
        }

        if ((clone $pendenti)->distinct()->count('gruppo_richiesta') >= $this->regola('deleghe_max_pendenti')) {
            throw new RichiestaDelegaRifiutata('Hai già troppe richieste in attesa: aspetta la risposta delle segreterie prima di inviarne altre.');
        }

        $giorniStop = $this->regola('deleghe_giorni_stop_rifiuto');
        $rifiuto = Delega::where('user_id', $user->id)
            ->where('id_istituto', $istituto->id_istituto)
            ->where('stato', Delega::RIFIUTATA)
            ->where('decisa_at', '>=', now()->subDays($giorniStop))
            ->latest('decisa_at')
            ->first();
        if ($rifiuto !== null) {
            throw new RichiestaDelegaRifiutata('La segreteria ha rifiutato una tua richiesta recente: potrai ripresentarla dal '.$rifiuto->decisa_at?->copy()->addDays($giorniStop)->format('d/m/Y').'.');
        }

        $idPlessi = $this->plessiDellIstituto($istituto, $idPlessi);

        if (Delega::attive()->where('user_id', $user->id)->where('id_istituto', $istituto->id_istituto)->whereNull('id_plesso')->exists()) {
            throw new RichiestaDelegaRifiutata("Hai già una delega attiva per tutto l'istituto.");
        }

        $esclusi = 0;
        if ($idPlessi !== []) {
            $miei = Delega::plessiCopertiDa($user)->pluck('id_plesso')->map(fn ($id) => (int) $id)->all();
            $restanti = array_values(array_diff($idPlessi, $miei));
            $esclusi = count($idPlessi) - count($restanti);
            if ($restanti === []) {
                throw new RichiestaDelegaRifiutata('Hai già una delega attiva per i plessi selezionati.');
            }
            $idPlessi = $restanti;
        }

        $gruppo = (string) Str::uuid();

        DB::transaction(function () use ($user, $istituto, $idPlessi, $gruppo) {
            foreach ($idPlessi === [] ? [null] : $idPlessi as $idPlesso) {
                Delega::create([
                    'user_id' => $user->id,
                    'codice_fiscale' => (string) $user->codice_fiscale,
                    'id_istituto' => $istituto->id_istituto,
                    'id_plesso' => $idPlesso,
                    'gruppo_richiesta' => $gruppo,
                    'stato' => Delega::RICHIESTA,
                    'email_destinatario' => $istituto->email,
                ])->registra('richiesta', 'utente', $user);
            }
        });

        // Un plesso senza nessun delegato è una scuola che non può segnalare:
        // la richiesta parte subito, senza tetto.
        $inviata = $this->scoperto($istituto, $idPlessi)
            || $this->inviateOggi($istituto) < $this->regola('deleghe_email_giorno_istituto');

        if ($inviata) {
            $this->invia($gruppo);
        } else {
            $this->avvisaAdmin('superato il tetto giornaliero', "{$istituto->descrizione}: superato il tetto giornaliero di richieste di delega; la richiesta di {$user->name} partirà domattina.");
        }

        return ['gruppo' => $gruppo, 'inviata' => $inviata, 'esclusi' => $esclusi];
    }

    /**
     * Genera un nuovo token (i link precedenti smettono di valere) e scrive
     * alla segreteria, all'email corrente dell'istituto. Restituisce il link.
     */
    public function invia(string $gruppo, bool $reinvio = false): string
    {
        $righe = Delega::where('gruppo_richiesta', $gruppo)
            ->where('stato', Delega::RICHIESTA)
            ->with(['user', 'istituto', 'plesso'])
            ->get();

        $prima = $righe->first();
        if ($prima === null || $prima->user === null) {
            throw new RichiestaDelegaRifiutata('La richiesta non è più in attesa.');
        }

        $email = (string) $prima->istituto?->email;
        if ($email === '') {
            throw new RichiestaDelegaRifiutata('La scuola non ha un indirizzo email configurato.');
        }

        $token = Str::random(64);
        $scadenza = now()->addDays($this->regola('deleghe_giorni_scadenza_richiesta'));

        Delega::whereIn('id', $righe->pluck('id'))->update([
            'token_hash' => hash('sha256', $token),
            'token_scadenza_at' => $scadenza,
            'richiesta_inviata_at' => now(),
            'email_destinatario' => $email,
        ]);
        $righe->each(fn (Delega $d) => $d->registra($reinvio ? 'reinviata' : 'inviata', 'sistema'));

        $url = URL::temporarySignedRoute('deleghe.decidi', $scadenza, ['token' => $token]);

        Notification::route('mail', $email)->notify(
            new RichiestaDelegaNotification($prima->user, $prima->istituto, $this->nomiPlessi($righe), $url, $scadenza)
        );

        return $url;
    }

    /** Richieste rimaste in coda per il tetto giornaliero (lanciato da deleghe:scadenze). */
    public function inviaInCoda(): int
    {
        $inviate = 0;

        $inCoda = Delega::where('stato', Delega::RICHIESTA)
            ->whereNull('richiesta_inviata_at')
            ->with('istituto')
            ->oldest()
            ->get()
            ->unique('gruppo_richiesta');

        foreach ($inCoda as $delega) {
            if ($delega->istituto === null || blank($delega->istituto->email)
                || $this->inviateOggi($delega->istituto) >= $this->regola('deleghe_email_giorno_istituto')) {
                continue;
            }
            $this->invia($delega->gruppo_richiesta);
            $inviate++;
        }

        return $inviate;
    }

    public function avvisaAdmin(string $oggetto, string $testo): void
    {
        Notification::send(
            User::where('amministratore', true)->where('attivo', true)->get(),
            new AvvisoDelegheAdmin($oggetto, $testo)
        );
    }

    /**
     * @param list<int> $idPlessi
     * @return list<int>
     */
    private function plessiDellIstituto(Istituto $istituto, array $idPlessi): array
    {
        $idPlessi = array_values(array_unique(array_map('intval', $idPlessi)));

        $validi = Plesso::where('id_istituto', $istituto->id_istituto)->whereIn('id_plesso', $idPlessi)->count();
        if ($validi !== count($idPlessi)) {
            throw new RichiestaDelegaRifiutata('Plesso non valido per questa scuola.');
        }

        return $idPlessi;
    }

    /** @param list<int> $idPlessi vuoto = tutto l'istituto */
    private function scoperto(Istituto $istituto, array $idPlessi): bool
    {
        $attive = fn () => Delega::attive()->where('id_istituto', $istituto->id_istituto);

        if ($attive()->whereNull('id_plesso')->exists()) {
            return false;
        }

        $obiettivo = $idPlessi !== []
            ? $idPlessi
            : Plesso::where('id_istituto', $istituto->id_istituto)->pluck('id_plesso')->map(fn ($id) => (int) $id)->all();
        $coperti = $attive()->whereNotNull('id_plesso')->pluck('id_plesso')->map(fn ($id) => (int) $id)->all();

        return array_diff($obiettivo, $coperti) !== [];
    }

    private function inviateOggi(Istituto $istituto): int
    {
        return Delega::where('id_istituto', $istituto->id_istituto)
            ->where('richiesta_inviata_at', '>=', today())
            ->distinct()
            ->count('gruppo_richiesta');
    }

    /**
     * @param Collection<int, Delega> $righe
     * @return list<string> vuoto = tutto l'istituto
     */
    private function nomiPlessi(Collection $righe): array
    {
        if ($righe->contains(fn (Delega $d) => $d->id_plesso === null)) {
            return [];
        }

        return $righe->map(fn (Delega $d) => (string) $d->plesso?->nome)->values()->all();
    }
}
