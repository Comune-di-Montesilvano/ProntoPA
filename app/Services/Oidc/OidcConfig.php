<?php

namespace App\Services\Oidc;

use App\Models\Impostazione;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class OidcConfig
{
    public function issuer(): string
    {
        return rtrim(trim((string) Impostazione::get('oidc_issuer', '')), '/');
    }

    public function clientId(): string
    {
        return trim((string) Impostazione::get('oidc_client_id', ''));
    }

    /** Secret cifrato con APP_KEY: se APP_KEY cambia va reinserito da UI. */
    public function clientSecret(): string
    {
        $cifrato = (string) Impostazione::get('oidc_client_secret', '');

        if ($cifrato === '') {
            return '';
        }

        try {
            return Crypt::decryptString($cifrato);
        } catch (DecryptException) {
            return '';
        }
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/spid/callback';
    }

    public function configurato(): bool
    {
        return $this->problemi() === [];
    }

    /**
     * Cosa manca perché il tasto SPID compaia al login, in chiaro per l'admin.
     *
     * @return list<string>
     */
    public function problemi(): array
    {
        $problemi = [];
        if ($this->issuer() === '') {
            $problemi[] = 'Issuer mancante.';
        }
        if ($this->clientId() === '') {
            $problemi[] = 'Client ID mancante.';
        }
        if ((string) Impostazione::get('oidc_client_secret', '') === '') {
            $problemi[] = 'Client secret mancante.';
        } elseif ($this->clientSecret() === '') {
            $problemi[] = 'Client secret non leggibile (salvato con un\'altra APP_KEY): reinseriscilo.';
        }

        return $problemi;
    }
}
