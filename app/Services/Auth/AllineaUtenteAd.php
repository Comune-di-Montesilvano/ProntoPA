<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Directory\DirectoryIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applica identità e ruolo AD all'utente ProntoPA. Unico punto di scrittura,
 * usato dal login e dalla sincronizzazione: le due strade non divergono.
 */
final class AllineaUtenteAd
{
    /**
     * Presuppone email non vuota (login e sync la verificano prima).
     *
     * @param  bool  $login  true = accesso dell'utente (aggiorna last_login)
     *
     * @throws UsernameOccupato
     */
    public function applica(DirectoryIdentity $identita, RuoloLdap $ruolo, bool $login): User
    {
        return DB::transaction(function () use ($identita, $ruolo, $login) {
            $email = mb_strtolower(trim((string) $identita->email));

            $user = User::where('ldap_guid', $identita->guid)->first()
                ?? $this->legacyDaAgganciare($email)
                ?? new User();

            $usernameOccupato = User::where('username', $identita->username)
                ->when($user->exists, fn ($q) => $q->whereKeyNot($user->getKey()))
                ->exists();

            if ($usernameOccupato) {
                Log::warning('Utente AD non allineato: username già usato da un altro account ProntoPA', [
                    'username' => $identita->username,
                    'user_id' => $user->id,
                ]);

                throw new UsernameOccupato($identita->username);
            }

            $eraGestore = $user->exists && $user->hasRole('gestore') && ! $user->supervisore_segnalazioni;

            $user->forceFill([
                'auth_source' => 'ldap',
                'ldap_guid' => $identita->guid,
                'username' => $identita->username,
                'name' => $identita->name,
                'email' => $email,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'password' => null,
                'password_legacy' => null,
                'amministratore' => $ruolo->ruolo === 'admin',
                'gestore_segnalazioni' => $ruolo->ruolo === 'gestore',
                'supervisore_segnalazioni' => $ruolo->supervisore,
                'attivo' => true,
                'approval_status' => 'approved',
            ]);
            if ($login) {
                $user->last_login = now();
            }
            $user->save();

            $user->syncRoles([$ruolo->ruolo]);

            if ($eraGestore && ! ($ruolo->ruolo === 'gestore' && ! $ruolo->supervisore)) {
                $this->togliTipologie($user, tieniEsplicite: $ruolo->ruolo === 'admin');
            }

            return $user;
        });
    }

    /**
     * Ex gestore: le sue tipologie non devono tornare da sole se un giorno
     * ridiventa gestore. Un admin può seguire tipologie esplicite (mai "tutte").
     */
    private function togliTipologie(User $user, bool $tieniEsplicite): void
    {
        if (! $tieniEsplicite) {
            $user->tipologieGestite()->detach();
        }
        $user->forceFill(['gestore_tutte_tipologie' => false])->save();
    }

    /**
     * Primo allineamento di un dipendente già presente come account locale
     * legacy: aggancio per email (quella di AD è affidabile), così lo
     * storico assegnazioni resta suo. Mai le ditte; mai se ambiguo.
     */
    private function legacyDaAgganciare(string $email): ?User
    {
        $candidati = User::where('auth_source', 'locale')
            ->whereNull('id_impresa')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'impresa'))
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)
            ->get();

        if ($candidati->count() > 1) {
            Log::warning('AD: più account legacy con la stessa email, nessun aggancio automatico', [
                'email_sha256' => hash('sha256', $email),
            ]);

            return null;
        }

        return $candidati->first();
    }
}
