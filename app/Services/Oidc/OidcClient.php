<?php

namespace App\Services\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client OIDC verso pa-sso-proxy. Nessuna specificità SPID/CIE qui: solo
 * Authorization Code + PKCE, con i vincoli del proxy (client_secret_basic,
 * id_token minimale + userinfo, id_token senza kid con una sola chiave).
 */
final class OidcClient
{
    private const LEEWAY = 60;

    private const CACHE_TTL = 600;

    public function __construct(private readonly OidcConfig $config) {}

    public static function nuovoVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public function urlAutorizzazione(string $state, string $nonce, string $codeVerifier): string
    {
        $discovery = $this->discovery();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return $discovery['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config->clientId(),
            'redirect_uri' => $this->config->redirectUri(),
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{claims: array<string, mixed>, id_token: string}
     */
    public function completaAccesso(string $code, string $codeVerifier, string $nonce): array
    {
        $discovery = $this->discovery();

        try {
            // Solo client_secret_basic: col secret nel body il proxy risponde 401 HTML.
            $risposta = $this->http()
                ->asForm()
                ->withBasicAuth($this->config->clientId(), $this->config->clientSecret())
                ->post($discovery['token_endpoint'], [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->config->redirectUri(),
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (ConnectionException $e) {
            throw new OidcNonDisponibile('Token endpoint non raggiungibile', 0, $e);
        }

        if ($risposta->serverError()) {
            throw new OidcNonDisponibile('Token endpoint: HTTP '.$risposta->status());
        }

        $idToken = $risposta->json('id_token');
        $accessToken = $risposta->json('access_token');

        if (! $risposta->successful() || ! is_string($idToken) || ! is_string($accessToken)) {
            throw new OidcAccessoFallito('Scambio del codice rifiutato: HTTP '.$risposta->status());
        }

        $claims = $this->verificaIdToken($idToken, $nonce, $discovery);

        return [
            'claims' => array_merge($claims, $this->userinfo($accessToken, (string) $claims['sub'], $discovery)),
            'id_token' => $idToken,
        ];
    }

    public function endSessionEndpoint(): ?string
    {
        $endpoint = $this->discovery()['end_session_endpoint'] ?? null;

        return is_string($endpoint) ? $endpoint : null;
    }

    /**
     * @param  array<string, mixed>  $discovery
     * @return array<string, mixed>
     */
    private function verificaIdToken(string $idToken, string $nonce, array $discovery): array
    {
        $parti = explode('.', $idToken);
        $header = count($parti) === 3 ? json_decode(JWT::urlsafeB64Decode($parti[0]), true) : null;

        if (! is_array($header)) {
            throw new OidcAccessoFallito('id_token malformato');
        }

        $chiavi = $this->chiavi($discovery);
        $kid = $header['kid'] ?? null;

        if ($kid === null) {
            // pa-sso-proxy omette kid quando il JWKS ha una sola chiave: legittimo.
            if (count($chiavi) !== 1) {
                throw new OidcAccessoFallito('id_token senza kid con più chiavi nel JWKS');
            }
            $chiave = reset($chiavi);
        } else {
            $chiave = $chiavi[$kid] ?? throw new OidcAccessoFallito('kid sconosciuto');
        }

        JWT::$leeway = self::LEEWAY;

        try {
            $payload = JWT::decode($idToken, $chiave);
        } catch (Throwable $e) {
            throw new OidcAccessoFallito('id_token non valido: '.$e->getMessage(), 0, $e);
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($payload), true);
        $audience = (array) ($claims['aud'] ?? []);

        if (($claims['iss'] ?? null) !== ($discovery['issuer'] ?? $this->config->issuer())) {
            throw new OidcAccessoFallito('issuer non valido');
        }
        if (! in_array($this->config->clientId(), $audience, true)) {
            throw new OidcAccessoFallito('audience non valida');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new OidcAccessoFallito('nonce non valido');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcAccessoFallito('sub mancante');
        }

        return $claims;
    }

    /**
     * Claim persona dal userinfo: l'id_token del proxy è minimale. Mai
     * bloccante: endpoint assente/in errore → solo claim id_token.
     *
     * @param  array<string, mixed>  $discovery
     * @return array<string, mixed>
     */
    private function userinfo(string $accessToken, string $sub, array $discovery): array
    {
        $url = $discovery['userinfo_endpoint'] ?? null;
        if (! is_string($url)) {
            return [];
        }

        try {
            $risposta = $this->http()->withToken($accessToken)->acceptJson()->get($url);
        } catch (ConnectionException) {
            return [];
        }

        $info = $risposta->successful() ? $risposta->json() : null;
        if (! is_array($info)) {
            return [];
        }

        // OIDC Core 5.3.2: sub del userinfo deve coincidere con quello verificato.
        if (($info['sub'] ?? null) !== $sub) {
            Log::warning('OIDC userinfo con sub diverso dall\'id_token: claim scartati');

            return [];
        }

        return $info;
    }

    /**
     * @param  array<string, mixed>  $discovery
     * @return array<string, Key>
     */
    private function chiavi(array $discovery): array
    {
        $uri = $discovery['jwks_uri'] ?? null;
        if (! is_string($uri)) {
            throw new OidcNonDisponibile('jwks_uri assente nella discovery');
        }

        $jwks = Cache::remember('oidc:jwks:'.md5($uri), self::CACHE_TTL, function () use ($uri) {
            try {
                $risposta = $this->http()->acceptJson()->get($uri);
            } catch (ConnectionException $e) {
                throw new OidcNonDisponibile('JWKS non raggiungibile', 0, $e);
            }
            if (! $risposta->successful() || ! is_array($risposta->json('keys'))) {
                throw new OidcNonDisponibile('JWKS non leggibile: HTTP '.$risposta->status());
            }

            return $risposta->json();
        });

        return JWK::parseKeySet($jwks, 'RS256');
    }

    /**
     * @return array<string, mixed>
     */
    private function discovery(): array
    {
        if (! $this->config->configurato()) {
            throw new OidcNonDisponibile('OIDC non configurato (Admin → Impostazioni → SPID)');
        }

        $issuer = $this->config->issuer();

        return Cache::remember('oidc:discovery:'.md5($issuer), self::CACHE_TTL, function () use ($issuer) {
            try {
                $risposta = $this->http()->acceptJson()->get($issuer.'/.well-known/openid-configuration');
            } catch (ConnectionException $e) {
                throw new OidcNonDisponibile('pa-sso-proxy non raggiungibile', 0, $e);
            }

            $documento = $risposta->json();
            if (! $risposta->successful() || ! is_array($documento) || ! isset($documento['authorization_endpoint'], $documento['token_endpoint'])) {
                throw new OidcNonDisponibile('Discovery non valida: HTTP '.$risposta->status());
            }

            return $documento;
        });
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('oidc.timeout', 10));
    }
}
