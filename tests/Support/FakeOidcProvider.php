<?php

namespace Tests\Support;

use App\Models\Impostazione;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * pa-sso-proxy finto: chiave RSA vera generata al volo, discovery, JWKS,
 * token (solo client_secret_basic, come il proxy reale) e userinfo.
 */
final class FakeOidcProvider
{
    public const ISSUER = 'https://login.test';

    public const CLIENT_ID = 'prontopa';

    public const SECRET = 'segreto';

    public ?string $kid = 'k1';

    /** @var array<string, mixed> */
    public array $userinfo = [];

    /** @var array<string, mixed> */
    public array $claimsIdToken = [];

    public int $statoToken = 200;

    public bool $proxyGiu = false;

    public bool $dueChiavi = false;

    public ?string $nonce = null;

    private string $chiavePrivata;

    /** @var array<string, string> */
    private array $jwk;

    public function __construct()
    {
        $risorsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($risorsa, $pem);
        $this->chiavePrivata = $pem;
        $rsa = openssl_pkey_get_details($risorsa)['rsa'];
        $this->jwk = ['kty' => 'RSA', 'n' => self::b64url($rsa['n']), 'e' => self::b64url($rsa['e']), 'use' => 'sig', 'alg' => 'RS256'];
    }

    public function configura(): void
    {
        Impostazione::set('oidc_issuer', self::ISSUER);
        Impostazione::set('oidc_client_id', self::CLIENT_ID);
        Impostazione::set('oidc_client_secret', Crypt::encryptString(self::SECRET));
    }

    /** @param array<string, mixed> $override */
    public function idToken(array $override = []): string
    {
        $payload = array_merge([
            'iss' => self::ISSUER,
            'sub' => 'sub-1',
            'aud' => self::CLIENT_ID,
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => $this->nonce,
        ], $this->claimsIdToken, $override);

        return JWT::encode($payload, $this->chiavePrivata, 'RS256', $this->kid);
    }

    public function fake(): void
    {
        $issuer = self::ISSUER;

        Http::fake(function ($request) use ($issuer) {
            if ($this->proxyGiu) {
                return Http::failedConnection();
            }

            $url = $request->url();

            return match (true) {
                $url === $issuer.'/.well-known/openid-configuration' => Http::response([
                    'issuer' => $issuer,
                    'authorization_endpoint' => $issuer.'/OIDC/authorization',
                    'token_endpoint' => $issuer.'/OIDC/token',
                    'userinfo_endpoint' => $issuer.'/OIDC/userinfo',
                    'jwks_uri' => $issuer.'/OIDC/jwks',
                    'end_session_endpoint' => $issuer.'/OIDC/end_session',
                ]),
                $url === $issuer.'/OIDC/jwks' => Http::response(['keys' => $this->chiavi()]),
                $url === $issuer.'/OIDC/token' => $this->rispostaToken($request),
                $url === $issuer.'/OIDC/userinfo' => Http::response($this->userinfo),
                default => Http::response('not found', 404),
            };
        });
    }

    private function rispostaToken($request)
    {
        // Come il proxy reale: solo client_secret_basic, secret nel body → 401 HTML.
        $basicAtteso = 'Basic '.base64_encode(self::CLIENT_ID.':'.self::SECRET);
        $autorizzazione = $request->header('Authorization')[0] ?? null;

        if ($autorizzazione !== $basicAtteso || str_contains($request->body(), 'client_secret')) {
            return Http::response('<html>401</html>', 401);
        }

        if ($this->statoToken !== 200) {
            return Http::response(['error' => 'invalid_grant'], $this->statoToken);
        }

        return Http::response(['id_token' => $this->idToken(), 'access_token' => 'at-1', 'token_type' => 'Bearer']);
    }

    /** @return list<array<string, string>> */
    private function chiavi(): array
    {
        $chiave = $this->kid !== null ? $this->jwk + ['kid' => $this->kid] : $this->jwk;

        if (! $this->dueChiavi) {
            return [$chiave];
        }

        $altra = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))['rsa'];

        return [$chiave, ['kty' => 'RSA', 'n' => self::b64url($altra['n']), 'e' => self::b64url($altra['e']), 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'k2']];
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
