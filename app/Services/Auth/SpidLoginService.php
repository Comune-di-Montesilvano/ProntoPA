<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Oidc\IdentitaSpid;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Utenti delle scuole via SPID/CIE: identità = codice fiscale. L'utente
 * nuovo nasce solo con l'email (Completa profilo), mai prima.
 */
final class SpidLoginService
{
    /** provenienze_segnalazioni: 2 = DIREZIONI DIDATTICHE (scuole) */
    public const PROVENIENZA_SCUOLE = 2;

    /**
     * @throws AccessoSpidNegato
     */
    public function accedi(IdentitaSpid $identita): ?User
    {
        $user = User::where('codice_fiscale', $identita->codiceFiscale)->first();

        if ($user === null) {
            return null;
        }

        if ($user->isBloccato() || ! $user->attivo) {
            throw new AccessoSpidNegato('Accesso non consentito. Contatta l\'ente.');
        }

        $user->forceFill([
            'name' => $identita->nomeCompleto() ?: $user->name,
            'oidc_subject' => $identita->subject,
            'last_login' => now(),
        ])->save();

        return $user;
    }

    public function creaDaProfilo(IdentitaSpid $identita, string $email): User
    {
        // Doppio invio del form o due schede: l'utente esiste già.
        $esistente = User::where('codice_fiscale', $identita->codiceFiscale)->first();
        if ($esistente !== null) {
            return $esistente;
        }

        try {
            return DB::transaction(function () use ($identita, $email) {
                $user = new User();
                $user->forceFill([
                    'auth_source' => 'spid',
                    'codice_fiscale' => $identita->codiceFiscale,
                    'oidc_subject' => $identita->subject,
                    'name' => $identita->nomeCompleto(),
                    'username' => $this->usernameUnivoco($identita),
                    'email' => mb_strtolower(trim($email)),
                    'email_verified_at' => null,
                    'password' => null,
                    'id_provenienza' => self::PROVENIENZA_SCUOLE,
                    'attivo' => true,
                    'approval_status' => 'approved',
                    'last_login' => now(),
                ])->save();

                $user->syncRoles(['segnalatore']);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Race tra due richieste contemporanee sullo stesso codice fiscale.
            return User::where('codice_fiscale', $identita->codiceFiscale)->firstOrFail();
        }
    }

    private function usernameUnivoco(IdentitaSpid $identita): string
    {
        $base = Str::of($identita->nome.'.'.$identita->cognome)
            ->lower()->ascii()
            ->replaceMatches('/[^a-z0-9.]/', '')
            ->trim('.')
            ->value() ?: 'utente.scuola';

        $candidato = $base;
        $suffisso = 1;

        while (User::where('username', $candidato)->exists()) {
            $suffisso++;
            $candidato = $base.$suffisso;
        }

        return $candidato;
    }
}
