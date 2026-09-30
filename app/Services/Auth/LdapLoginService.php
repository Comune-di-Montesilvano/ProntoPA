<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Login dipendenti: AD è la fonte di verità. A ogni login ruolo, flag,
 * provenienza e permesso per-conto vengono ricalcolati dai gruppi.
 */
final class LdapLoginService
{
    public function __construct(
        private readonly Directory $directory,
        private readonly MappaGruppiLdap $mappa,
    ) {}

    /**
     * @return User|null null = credenziali non valide
     *
     * @throws AccessoLdapNegato
     * @throws \App\Services\Directory\DirectoryNonDisponibile
     */
    public function login(string $username, string $password): ?User
    {
        // Su AD un bind con password vuota è un bind anonimo che "riesce".
        if ($password === '') {
            return null;
        }

        $identita = $this->directory->authenticate($username, $password);
        if ($identita === null) {
            return null;
        }

        $ruolo = $this->mappa->risolvi($identita->groups);
        if ($ruolo === null) {
            throw new AccessoLdapNegato('Non sei abilitato a ProntoPA. Contatta l\'amministratore.');
        }

        if (blank($identita->email)) {
            throw new AccessoLdapNegato('Il tuo account di dominio non ha un indirizzo email: contatta l\'amministratore.');
        }

        return DB::transaction(fn () => $this->sincronizza($identita, $ruolo));
    }

    private function sincronizza(DirectoryIdentity $identita, RuoloLdap $ruolo): User
    {
        $email = mb_strtolower(trim((string) $identita->email));

        $user = User::where('ldap_guid', $identita->guid)->first()
            ?? $this->legacyDaAgganciare($email)
            ?? new User();

        $usernameOccupato = User::where('username', $identita->username)
            ->when($user->exists, fn ($q) => $q->whereKeyNot($user->getKey()))
            ->exists();

        if ($usernameOccupato) {
            Log::warning('Login AD rifiutato: username già usato da un altro account ProntoPA', [
                'username' => $identita->username,
                'user_id' => $user->id,
            ]);

            throw new AccessoLdapNegato('Il tuo nome utente è già usato da un altro account ProntoPA: contatta l\'amministratore.');
        }

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
            'id_provenienza' => $ruolo->idProvenienza,
            'attivo' => true,
            'approval_status' => 'approved',
            'last_login' => now(),
        ])->save();

        $user->syncRoles([$ruolo->ruolo]);

        if ($ruolo->perConto) {
            $user->givePermissionTo('segnalazioni.per-conto');
        } else {
            $user->revokePermissionTo('segnalazioni.per-conto');
        }

        return $user;
    }

    /**
     * Primo accesso AD di un dipendente già presente come account locale
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
            Log::warning('Login AD: più account legacy con la stessa email, nessun aggancio automatico', [
                'email_sha256' => hash('sha256', $email),
            ]);

            return null;
        }

        return $candidati->first();
    }
}
