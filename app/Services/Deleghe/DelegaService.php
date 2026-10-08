<?php

namespace App\Services\Deleghe;

use App\Models\Delega;
use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use App\Notifications\Deleghe\AvvisoDelegheAdmin;
use App\Notifications\Deleghe\EsitoDelegaNotification;
use App\Notifications\Deleghe\RichiestaDelegaNotification;
use App\Notifications\Deleghe\RinnovoDelegheNotification;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Carbon;
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
            throw new RichiestaDelegaRifiutata('La segreteria ha rifiutato una tua richiesta recente: potrai ripresentarla dal '.Carbon::parse($rifiuto->decisa_at)->addDays($giorniStop)->format('d/m/Y').'.');
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

    /** @return Collection<int, Delega> */
    public function daToken(string $token): Collection
    {
        return Delega::where('token_hash', hash('sha256', $token))
            ->with(['user', 'istituto', 'plesso'])
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, Delega> $righe */
    public function approva(Collection $righe, string $via, ?User $da = null, ?string $ip = null, ?string $motivo = null): void
    {
        $approvate = DB::transaction(function () use ($righe, $via, $da, $ip, $motivo) {
            $validaFino = now()->addMonthsNoOverflow($this->regola('deleghe_mesi_validita'));
            $approvate = collect();

            foreach ($righe as $delega) {
                $delega = Delega::whereKey($delega->id)->lockForUpdate()->first();
                if ($delega === null || $delega->stato !== Delega::RICHIESTA) {
                    continue;
                }
                $delega->update([
                    'stato' => Delega::ATTIVA,
                    'valida_fino_at' => $validaFino,
                    'decisa_at' => now(),
                    'decisa_via' => $via,
                    'decisa_da' => $da?->id,
                    'motivo' => $motivo,
                ]);
                $delega->registra('approvata', $via, $da, $ip);
                if ($delega->id_plesso === null) {
                    $this->assorbi($delega);
                }
                $approvate->push($delega);
            }

            return $approvate;
        });

        $prima = $approvate->first();
        if ($prima !== null) {
            $this->notificaDelegato($prima->user, new EsitoDelegaNotification('approvata', $prima->istituto));
        }
    }

    /** @param Collection<int, Delega> $righe */
    public function rifiuta(Collection $righe, ?string $ip, bool $blocca): void
    {
        $rifiutate = DB::transaction(function () use ($righe, $ip, $blocca) {
            $rifiutate = collect();

            foreach ($righe as $delega) {
                $delega = Delega::whereKey($delega->id)->lockForUpdate()->first();
                if ($delega === null || $delega->stato !== Delega::RICHIESTA) {
                    continue;
                }
                $delega->update(['stato' => Delega::RIFIUTATA, 'decisa_at' => now(), 'decisa_via' => 'segreteria']);
                $delega->registra('rifiutata', 'segreteria', null, $ip);
                $rifiutate->push($delega);
            }

            $prima = $rifiutate->first();
            if ($blocca && $prima?->user !== null) {
                $prima->user->forceFill([
                    'bloccato_at' => now(),
                    'motivo_blocco' => "La segreteria di {$prima->istituto?->descrizione} ha indicato di non conoscere questa persona",
                ])->save();

                Delega::where('user_id', $prima->user_id)->where('stato', Delega::RICHIESTA)->get()
                    ->each(function (Delega $d) {
                        $d->update(['stato' => Delega::RIFIUTATA, 'decisa_at' => now(), 'decisa_via' => 'sistema', 'motivo' => 'richiedente bloccato']);
                        $d->registra('bloccata', 'sistema');
                    });
            }

            return $rifiutate;
        });

        $prima = $rifiutate->first();
        if ($prima === null) {
            return;
        }

        $this->notificaDelegato($prima->user, new EsitoDelegaNotification('rifiutata', $prima->istituto));

        if ($blocca && $prima->user !== null) {
            $this->avvisaAdmin('persona bloccata da una segreteria', "La segreteria di {$prima->istituto?->descrizione} ha indicato di non conoscere {$prima->user->name} (codice fiscale {$prima->user->codice_fiscale}): utente bloccato, richieste in attesa chiuse. Lo sblocco si fa da Admin → Deleghe.");
        }
    }

    public function notificaDelegato(?User $user, BaseNotification $notifica): void
    {
        if ($user !== null && $user->hasVerifiedEmail()) {
            $user->notify($notifica);
        }
    }

    /** Una delega sull'istituto intero rende superflue quelle sui singoli plessi. */
    private function assorbi(Delega $intera): void
    {
        Delega::where('codice_fiscale', $intera->codice_fiscale)
            ->where('id_istituto', $intera->id_istituto)
            ->whereNotNull('id_plesso')
            ->where('stato', Delega::ATTIVA)
            ->get()
            ->each(function (Delega $d) {
                $d->update(['stato' => Delega::REVOCATA, 'decisa_at' => now(), 'decisa_via' => 'sistema', 'motivo' => 'assorbita da delega istituto']);
                $d->registra('revocata', 'sistema');
            });
    }

    public function revoca(Delega $delega, string $via, ?User $da = null, ?string $motivo = null, ?string $ip = null): void
    {
        if (! in_array($delega->stato, [Delega::ATTIVA, Delega::RICHIESTA], true)) {
            return;
        }

        $delega->update([
            'stato' => Delega::REVOCATA,
            'decisa_at' => now(),
            'decisa_via' => $via,
            'decisa_da' => $da?->id,
            'motivo' => $motivo,
        ]);
        $delega->registra('revocata', $via, $da, $ip);

        if ($via !== 'utente') {
            $this->notificaDelegato($delega->user, new EsitoDelegaNotification('revocata', $delega->istituto));
        }
    }

    /** Pre-deleghe admin per questo codice fiscale, dopo la verifica dell'email. */
    public function agganciaPredeleghe(User $user): int
    {
        if (! $user->isSpid() || ! $user->hasVerifiedEmail() || blank($user->codice_fiscale)) {
            return 0;
        }

        $pre = Delega::whereNull('user_id')
            ->where('codice_fiscale', $user->codice_fiscale)
            ->where('stato', Delega::ATTIVA)
            ->get();

        $pre->each(function (Delega $d) use ($user) {
            $d->update(['user_id' => $user->id]);
            $d->registra('agganciata', 'sistema', $user);
        });

        return $pre->count();
    }

    /** Una email per scuola con le deleghe che scadono entro la fine del mese successivo. */
    public function inviaRinnovi(): int
    {
        $inviate = 0;

        $perIstituto = Delega::attive()
            ->whereNotNull('user_id')
            ->whereNull('rinnovo_inviato_at')
            ->where('valida_fino_at', '<=', now()->addMonthNoOverflow()->endOfMonth())
            ->with(['user', 'istituto'])
            ->get()
            ->groupBy('id_istituto');

        foreach ($perIstituto as $righe) {
            $istituto = $righe->first()->istituto;
            if ($istituto === null || blank($istituto->email)) {
                continue;
            }

            $token = Str::random(64);
            /** @var \Illuminate\Support\Carbon $scadenza */
            $scadenza = $righe->max('valida_fino_at');

            Delega::whereIn('id', $righe->pluck('id'))->update([
                'token_hash' => hash('sha256', $token),
                'token_scadenza_at' => $scadenza,
                'rinnovo_inviato_at' => now(),
                'avviso_inviato_at' => null,
            ]);

            $url = URL::temporarySignedRoute('deleghe.rinnovo', $scadenza, ['token' => $token]);
            $persone = $righe->map(fn (Delega $d) => (string) $d->user?->name)->unique()->values()->all();

            Notification::route('mail', (string) $istituto->email)
                ->notify(new RinnovoDelegheNotification($istituto, $persone, $url, $scadenza));
            $inviate++;
        }

        return $inviate;
    }

    public function confermaRinnovo(Delega $delega, ?string $ip): void
    {
        if ($delega->stato !== Delega::ATTIVA || $delega->rinnovo_inviato_at === null) {
            return;
        }

        $delega->update([
            'valida_fino_at' => now()->addMonthsNoOverflow($this->regola('deleghe_mesi_validita')),
            'rinnovo_inviato_at' => null,
            'avviso_inviato_at' => null,
        ]);
        $delega->registra('rinnovata', 'segreteria', null, $ip);
    }

    /** @return array{scadute: int, richieste_scadute: int, inviate: int, avvisi: int} */
    public function scadenze(): array
    {
        $scadute = Delega::where('stato', Delega::ATTIVA)->where('valida_fino_at', '<', now())->with(['user', 'istituto'])->get();
        foreach ($scadute as $delega) {
            $delega->update(['stato' => Delega::SCADUTA, 'decisa_at' => now(), 'decisa_via' => 'sistema']);
            $delega->registra('scaduta', 'sistema');
            $this->notificaDelegato($delega->user, new EsitoDelegaNotification('scaduta', $delega->istituto));
        }

        $richieste = Delega::where('stato', Delega::RICHIESTA)->where('token_scadenza_at', '<', now())->get();
        foreach ($richieste as $delega) {
            $delega->update(['stato' => Delega::SCADUTA, 'decisa_at' => now(), 'decisa_via' => 'sistema']);
            $delega->registra('scaduta', 'sistema');
        }

        $inviate = $this->inviaInCoda();

        $daAvvisare = Delega::attive()
            ->whereNotNull('rinnovo_inviato_at')
            ->whereNull('avviso_inviato_at')
            ->where('valida_fino_at', '<=', now()->addDays($this->regola('deleghe_giorni_avviso_delegato')))
            ->with(['user', 'istituto'])
            ->get();
        foreach ($daAvvisare as $delega) {
            $this->notificaDelegato($delega->user, new EsitoDelegaNotification('in_scadenza', $delega->istituto, $delega->valida_fino_at));
            $delega->update(['avviso_inviato_at' => now()]);
        }

        return [
            'scadute' => $scadute->count(),
            'richieste_scadute' => $richieste->count(),
            'inviate' => $inviate,
            'avvisi' => $daAvvisare->count(),
        ];
    }

    /**
     * Delega decisa dall'ente (go-live, casi noti): attiva subito, nessuna
     * email alla segreteria; agganciata al primo accesso SPID di quel CF.
     *
     * @param list<int> $idPlessi vuoto = tutto l'istituto
     */
    public function predelega(string $codiceFiscale, Istituto $istituto, array $idPlessi, string $motivo, User $admin): void
    {
        $cf = strtoupper(trim($codiceFiscale));
        $idPlessi = $this->plessiDellIstituto($istituto, $idPlessi);
        $user = User::where('codice_fiscale', $cf)->where('auth_source', 'spid')->first();
        $gruppo = (string) Str::uuid();
        $validaFino = now()->addMonthsNoOverflow($this->regola('deleghe_mesi_validita'));

        DB::transaction(function () use ($cf, $istituto, $idPlessi, $motivo, $admin, $user, $gruppo, $validaFino) {
            foreach ($idPlessi === [] ? [null] : $idPlessi as $idPlesso) {
                $delega = Delega::create([
                    'user_id' => $user?->id,
                    'codice_fiscale' => $cf,
                    'id_istituto' => $istituto->id_istituto,
                    'id_plesso' => $idPlesso,
                    'gruppo_richiesta' => $gruppo,
                    'stato' => Delega::ATTIVA,
                    'decisa_at' => now(),
                    'decisa_via' => 'admin',
                    'decisa_da' => $admin->id,
                    'motivo' => $motivo,
                    'valida_fino_at' => $validaFino,
                ]);
                $delega->registra('predelega', 'admin', $admin);
                if ($idPlesso === null) {
                    $this->assorbi($delega);
                }
            }
        });
    }

    public function attivaDUfficio(string $gruppo, string $motivo, User $admin): void
    {
        $this->approva(Delega::where('gruppo_richiesta', $gruppo)->with(['user', 'istituto'])->get(), 'admin', $admin, null, $motivo);
    }

    public function sblocca(User $user): void
    {
        $user->forceFill(['bloccato_at' => null, 'motivo_blocco' => null])->save();
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
