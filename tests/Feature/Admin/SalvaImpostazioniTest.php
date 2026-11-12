<?php

namespace Tests\Feature\Admin;

use App\Models\Impostazione;
use App\Services\Impostazioni\SalvaImpostazioni;
use App\Services\Sla\CalendarioUfficio;
use App\Support\Impostazioni\Sezioni;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SalvaImpostazioniTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(ImpostazioniSeeder::class);
    }

    private function salva(string $sezione, array $input): array
    {
        return app(SalvaImpostazioni::class)->salva($sezione, $input);
    }

    public function test_segreto_vuoto_non_sovrascrive(): void
    {
        Impostazione::set('telegram_bot_token', 'abc');
        $cambiate = $this->salva('INTEGRAZIONI_TELEGRAM', ['telegram_bot_token' => '']);

        $this->assertSame('abc', Impostazione::get('telegram_bot_token'));
        $this->assertNotContains('telegram_bot_token', $cambiate);
    }

    public function test_segreto_cifrato(): void
    {
        $cambiate = $this->salva('INTEGRAZIONI_SPID', ['oidc_client_secret' => 's3']);

        $this->assertSame('s3', Crypt::decryptString(Impostazione::get('oidc_client_secret')));
        $this->assertContains('oidc_client_secret', $cambiate);
        $this->assertNotContains('oidc_client_secret', $this->salva('INTEGRAZIONI_SPID', ['oidc_client_secret' => 's3']));
    }

    public function test_issuer_senza_slash_finale(): void
    {
        $this->salva('INTEGRAZIONI_SPID', ['oidc_issuer' => 'https://sso.example.it/']);

        $this->assertSame('https://sso.example.it', Impostazione::get('oidc_issuer'));
    }

    public function test_bool_assente_salva_zero(): void
    {
        Impostazione::set('digest_enabled', '1');
        $this->salva('AVVISI', []);

        $this->assertSame('0', Impostazione::get('digest_enabled'));
    }

    public function test_bool_presente_salva_uno(): void
    {
        Impostazione::set('squadre_enabled', '0');
        $this->salva('SQUADRE', ['squadre_enabled' => '1']);

        $this->assertSame('1', Impostazione::get('squadre_enabled'));
    }

    public function test_chiave_di_altra_sezione_ignorata(): void
    {
        $prima = Impostazione::get('ente_nome');
        $this->salva('AVVISI', ['ente_nome' => 'X']);

        $this->assertSame($prima, Impostazione::get('ente_nome'));
    }

    public function test_chiavi_interne_ignorate(): void
    {
        Impostazione::set('ripristino_id', 'orig');
        $this->salva('ASPETTO', ['ripristino_id' => 'x']);

        $this->assertSame('orig', Impostazione::get('ripristino_id'));
    }

    public function test_colore_non_valido_rifiutato(): void
    {
        try {
            $this->salva('ASPETTO', ['ente_colore_primario' => 'rosso']);
            $this->fail('Attesa ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('impostazioni.ente_colore_primario', $e->errors());
        }
    }

    public function test_orario_tutto_chiuso_rifiutato(): void
    {
        $input = [];
        foreach (CalendarioUfficio::GIORNI as $g) {
            $input["orario_$g"] = '';
        }

        try {
            $this->salva('SCADENZE', $input);
            $this->fail('Attesa ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Almeno un giorno deve essere aperto', $e->errors()['impostazioni.orario_lun'][0]);
        }
    }

    public function test_orario_e_patrono_non_validi_rifiutati(): void
    {
        try {
            $this->salva('SCADENZE', ['orario_lun' => 'pippo', 'patrono' => '99/99']);
            $this->fail('Attesa ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('impostazioni.orario_lun', $e->errors());
            $this->assertArrayHasKey('impostazioni.patrono', $e->errors());
        }
    }

    public function test_ritorna_chiavi_cambiate(): void
    {
        Impostazione::set('patrono', '');
        $cambiate = $this->salva('SCADENZE', ['patrono' => '13/06']);

        $this->assertContains('patrono', $cambiate);
        $this->assertNotContains('orario_lun', $cambiate);
    }

    public function test_disco_e_stato_validati(): void
    {
        try {
            $this->salva('INTEGRAZIONI_ALLEGATI', ['allegati_storage_disk' => 'ftp']);
            $this->fail('Attesa ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('impostazioni.allegati_storage_disk', $e->errors());
        }

        $this->salva('SEGNALAZIONI', ['publication_auto_state_id' => '2']);
        $this->assertSame('2', (string) Impostazione::get('publication_auto_state_id'));
    }

    public function test_componente_non_stampa_i_segreti(): void
    {
        Impostazione::set('telegram_bot_token', 'SEGRETISSIMO');
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());

        $html = \Illuminate\Support\Facades\Blade::render(
            '<x-pa.impostazione chiave="telegram_bot_token" tipo="segreto" etichetta="Token" />'
            . '<x-pa.impostazione chiave="allegati_storage_disk" tipo="disco" etichetta="Dove" />'
            . '<x-pa.impostazione chiave="digest_enabled" tipo="bool" etichetta="Digest" />'
        );

        $this->assertStringNotContainsString('SEGRETISSIMO', $html);
        $this->assertStringContainsString('Configurato — lascia vuoto per non modificarlo', $html);
        $this->assertStringContainsString('name="impostazioni[telegram_bot_token]"', $html);
        $this->assertStringContainsString('S3 o compatibile', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function test_sezione_sconosciuta_404(): void
    {
        $this->expectException(NotFoundHttpException::class);
        Sezioni::di('INESISTENTE');
    }

    public function test_telegram_notifica_stato_critico_non_presente(): void
    {
        $chiavi = collect(Sezioni::tutte())->flatMap(fn ($m) => array_keys($m));
        $this->assertFalse($chiavi->contains('telegram_notifica_stato_critico'));
    }

    public function test_valore_array_e_errore_di_validazione_non_eccezione(): void
    {
        foreach ([['ASPETTO', 'ente_nome', ['a', 'b']], ['ASPETTO', 'ente_colore_primario', [['x']]], ['INTEGRAZIONI_SPID', 'oidc_issuer', ['x']], ['INTEGRAZIONI_TELEGRAM', 'telegram_bot_token', ['x']]] as [$sezione, $chiave, $valore]) {
            try {
                $this->salva($sezione, [$chiave => $valore]);
                $this->fail("Atteso ValidationException per $chiave");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey("impostazioni.$chiave", $e->errors());
            }
        }
    }
}
