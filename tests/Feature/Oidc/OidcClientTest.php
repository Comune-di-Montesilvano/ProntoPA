<?php

namespace Tests\Feature\Oidc;

use App\Models\Impostazione;
use App\Services\Oidc\OidcAccessoFallito;
use App\Services\Oidc\OidcClient;
use App\Services\Oidc\OidcNonDisponibile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeOidcProvider;
use Tests\TestCase;

class OidcClientTest extends TestCase
{
    use RefreshDatabase;

    private FakeOidcProvider $idp;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://prontopa.test']);
        $this->idp = new FakeOidcProvider();
        $this->idp->configura();
        $this->idp->nonce = 'nonce-1';
        $this->idp->fake();
    }

    private function client(): OidcClient
    {
        return app(OidcClient::class);
    }

    public function test_url_autorizzazione_con_pkce_s256(): void
    {
        $url = $this->client()->urlAutorizzazione('stato', 'nonce-1', 'verifier-di-prova');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith(FakeOidcProvider::ISSUER.'/OIDC/authorization?', $url);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame(FakeOidcProvider::CLIENT_ID, $q['client_id']);
        $this->assertSame('https://prontopa.test/auth/spid/callback', $q['redirect_uri']);
        $this->assertSame('openid profile email', $q['scope']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', 'verifier-di-prova', true)), '+/', '-_'), '='), $q['code_challenge']);
        $this->assertSame('stato', $q['state']);
        $this->assertSame('nonce-1', $q['nonce']);
    }

    public function test_completa_accesso_unisce_userinfo_e_usa_basic_auth(): void
    {
        $this->idp->userinfo = ['sub' => 'sub-1', 'fiscal_number' => 'TINIT-RSSMRA80A01G482X', 'given_name' => 'Mario'];

        $esito = $this->client()->completaAccesso('code-1', 'verifier', 'nonce-1');

        $this->assertSame('TINIT-RSSMRA80A01G482X', $esito['claims']['fiscal_number']);
        $this->assertSame('sub-1', $esito['claims']['sub']);
        $this->assertNotEmpty($esito['id_token']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/OIDC/token')
            && ! str_contains($r->body(), 'client_secret')
            && str_contains($r->body(), 'code_verifier=verifier'));
    }

    public function test_userinfo_con_sub_diverso_viene_scartato(): void
    {
        $this->idp->userinfo = ['sub' => 'altro', 'fiscal_number' => 'TINIT-XXXXXX00X00X000X'];

        $esito = $this->client()->completaAccesso('code-1', 'verifier', 'nonce-1');

        $this->assertArrayNotHasKey('fiscal_number', $esito['claims']);
    }

    public function test_id_token_senza_kid_accettato_con_una_sola_chiave(): void
    {
        $this->idp->kid = null;

        $esito = $this->client()->completaAccesso('code-1', 'verifier', 'nonce-1');

        $this->assertSame('sub-1', $esito['claims']['sub']);
    }

    public function test_id_token_senza_kid_rifiutato_con_piu_chiavi(): void
    {
        $this->idp->kid = null;
        $this->idp->dueChiavi = true;

        $this->expectException(OidcAccessoFallito::class);
        $this->client()->completaAccesso('code-1', 'verifier', 'nonce-1');
    }

    public function test_nonce_errato_rifiutato(): void
    {
        $this->expectException(OidcAccessoFallito::class);
        $this->client()->completaAccesso('code-1', 'verifier', 'nonce-sbagliato');
    }

    public function test_issuer_o_audience_errati_rifiutati(): void
    {
        $this->idp->claimsIdToken = ['aud' => 'altro-client'];
        try {
            $this->client()->completaAccesso('code-1', 'verifier', 'nonce-1');
            $this->fail('audience errata accettata');
        } catch (OidcAccessoFallito) {
        }

        $this->idp->claimsIdToken = ['iss' => 'https://malevolo.test'];
        $this->expectException(OidcAccessoFallito::class);
        $this->client()->completaAccesso('code-1', 'verifier', 'nonce-1');
    }

    public function test_id_token_scaduto_rifiutato(): void
    {
        $this->idp->claimsIdToken = ['exp' => time() - 3600, 'iat' => time() - 7200];

        $this->expectException(OidcAccessoFallito::class);
        $this->client()->completaAccesso('code-1', 'verifier', 'nonce-1');
    }

    public function test_token_rifiutato_dal_proxy(): void
    {
        $this->idp->statoToken = 400;

        $this->expectException(OidcAccessoFallito::class);
        $this->client()->completaAccesso('code-1', 'verifier', 'nonce-1');
    }

    public function test_proxy_giu_lancia_non_disponibile(): void
    {
        $this->idp->proxyGiu = true;

        $this->expectException(OidcNonDisponibile::class);
        $this->client()->urlAutorizzazione('s', 'n', 'v');
    }

    public function test_non_configurato_lancia_non_disponibile(): void
    {
        Impostazione::set('oidc_client_secret', '');

        $this->expectException(OidcNonDisponibile::class);
        $this->client()->urlAutorizzazione('s', 'n', 'v');
    }

    public function test_end_session_dalla_discovery(): void
    {
        $this->assertSame(FakeOidcProvider::ISSUER.'/OIDC/end_session', $this->client()->endSessionEndpoint());
    }

    public function test_verifier_casuale_e_url_safe(): void
    {
        $v = OidcClient::nuovoVerifier();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{64}$/', $v);
        $this->assertNotSame($v, OidcClient::nuovoVerifier());
    }
}
