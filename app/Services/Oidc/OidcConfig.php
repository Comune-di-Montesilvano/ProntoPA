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
        return $this->issuer() !== '' && $this->clientId() !== '' && $this->clientSecret() !== '';
    }
}
