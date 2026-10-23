<?php

namespace Tests\Feature\Deleghe;

use App\Models\Appalto;
use App\Models\DelegaImpresa;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Auth\SpidLoginService;
use App\Services\Deleghe\DelegaImpresaService;
use App\Services\Oidc\IdentitaSpid;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

class DelegaImpresaTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private const CF = 'RSSMRA80A01H501U';

    private DelegaImpresaService $servizio;

    private User $operatore;

    private Impresa $impresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->servizio = app(DelegaImpresaService::class);
        $this->operatore = User::factory()->create(['auth_source' => 'ldap']);
        $this->operatore->assignRole('gestore');
        $this->impresa = Impresa::create(['ragione_sociale' => 'Edil Test', 'email' => 'info@ediltest.it']);
    }

    private function identita(string $cf = self::CF): IdentitaSpid
    {
        return new IdentitaSpid($cf, 'Mario', 'Rossi', null, 'sub-'.$cf);
    }

    private function segnalazioneDi(Impresa $impresa, bool $appaltoValido = true): Segnalazione
    {
        $appalto = Appalto::create([
            'id_gruppo' => 1, 'descrizione' => 'Appalto', 'id_impresa' => $impresa->id_impresa, 'valido' => $appaltoValido,
        ]);

        return Segnalazione::create([
            'id_tipologia_segnalazione' => 1, 'id_utente_segnalazione' => $this->operatore->id,
            'testo_segnalazione' => 'Guasto', 'flag_riservata' => false, 'flag_pubblicata' => false,
            'id_stato_segnalazione' => 4, 'id_provenienza' => 1, 'id_appalto' => $appalto->id_appalto,
        ]);
    }

    public function test_predelega_e_primo_accesso_spid_danno_ruolo_impresa(): void
    {
        $this->servizio->predelega(self::CF, $this->impresa, null, $this->operatore);

        $user = app(SpidLoginService::class)->creaDaProfilo($this->identita(), 'm@x.it');

        $this->assertTrue($user->fresh()->hasRole('impresa'));
        $this->assertSame($this->impresa->id_impresa, $user->fresh()->id_impresa);
        $this->assertSame($user->id, DelegaImpresa::first()->user_id);

        $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('verification.notice'));

        $user->markEmailAsVerified();
        $this->actingAs($user->fresh())->get(route('imprese.dashboard'))->assertOk();
    }

    public function test_utente_spid_esistente_viene_agganciato_al_login(): void
    {
        $user = $this->utenteSpid(self::CF);
        $this->servizio->predelega(self::CF, $this->impresa, null, $this->operatore);
        // predelega su utente esistente aggancia già; simuliamo una revoca del ruolo fuori banda
        $user->syncRoles(['segnalatore']);

        app(SpidLoginService::class)->accedi($this->identita());

        $this->assertTrue($user->fresh()->hasRole('impresa'));
    }

    public function test_predelega_su_utente_gia_esistente_aggancia_subito(): void
    {
        $user = $this->utenteSpid(self::CF);

        $delega = $this->servizio->predelega(self::CF, $this->impresa, 'Nuovo appalto', $this->operatore);

        $this->assertSame($user->id, $delega->user_id);
        $this->assertTrue($user->fresh()->hasRole('impresa'));
        $this->assertSame('Nuovo appalto', $delega->motivo);
        $this->assertSame($this->operatore->id, $delega->creata_da);
    }

    public function test_persona_vede_storico_della_ditta(): void
    {
        $vecchia = $this->segnalazioneDi($this->impresa, appaltoValido: false);
        $altra = $this->segnalazioneDi(Impresa::create(['ragione_sociale' => 'Altra']));
        $user = $this->utenteSpid(self::CF);
        $this->servizio->predelega(self::CF, $this->impresa, null, $this->operatore);

        $visibili = Segnalazione::visibileA($user->fresh())->pluck('id_segnalazione');

        $this->assertContains($vecchia->id_segnalazione, $visibili);
        $this->assertNotContains($altra->id_segnalazione, $visibili);
    }

    public function test_revoca_toglie_accesso_alla_richiesta_successiva(): void
    {
        $segnalazione = $this->segnalazioneDi($this->impresa);
        $user = $this->utenteSpid(self::CF);
        $delega = $this->servizio->predelega(self::CF, $this->impresa, null, $this->operatore);
        $this->actingAs($user->fresh())->get(route('segnalazioni.show', $segnalazione))->assertOk();

        $this->servizio->revoca($delega, $this->operatore, 'Fine appalto');

        // Senza ditta né deleghe scuola: rimandato a "Le mie deleghe", scheda non visibile.
        $this->actingAs($user->fresh())->get(route('segnalazioni.show', $segnalazione))
            ->assertRedirect(route('scuola.deleghe.index'));
        $this->assertFalse($user->fresh()->can('view', $segnalazione));
        $this->assertTrue($user->fresh()->hasRole('segnalatore'));
        $this->assertNull($user->fresh()->id_impresa);
        $this->assertSame(DelegaImpresa::REVOCATA, $delega->fresh()->stato);
        $this->assertSame($this->operatore->id, $delega->fresh()->revocata_da);
        $this->assertNotNull($delega->fresh()->revocata_at);
    }

    public function test_cf_con_delega_attiva_non_si_ridelega(): void
    {
        $this->servizio->predelega(self::CF, $this->impresa, null, $this->operatore);

        try {
            $this->servizio->predelega(self::CF, Impresa::create(['ragione_sociale' => 'Altra']), null, $this->operatore);
            $this->fail('Attesa ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('codice_fiscale', $e->errors());
            $this->assertStringContainsString('Edil Test', $e->errors()['codice_fiscale'][0]);
        }
    }

    public function test_cf_non_valido_rifiutato(): void
    {
        $this->expectException(ValidationException::class);

        $this->servizio->predelega('ABC', $this->impresa, null, $this->operatore);
    }

    public function test_impresa_disattivata_revoca_tutte_e_blocca_nuove(): void
    {
        $this->servizio->predelega(self::CF, $this->impresa, null, $this->operatore);
        $this->servizio->predelega('VRDLGU75B02H501K', $this->impresa, null, $this->operatore);

        $this->servizio->disattivaImpresa($this->impresa, $this->operatore);

        $this->assertFalse($this->impresa->fresh()->attiva);
        $this->assertSame(0, DelegaImpresa::attive()->count());
        $this->assertSame(2, DelegaImpresa::where('motivo', 'Ditta disattivata')->count());

        try {
            $this->servizio->predelega('BNCLRA90C41H501X', $this->impresa->fresh(), null, $this->operatore);
            $this->fail('Attesa ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('codice_fiscale', $e->errors());
        }

        $this->servizio->riattivaImpresa($this->impresa->fresh());
        $this->assertTrue($this->impresa->fresh()->attiva);
        $this->assertSame(0, DelegaImpresa::attive()->count());
    }

    public function test_cf_con_prefisso_tinit_viene_normalizzato(): void
    {
        $delega = $this->servizio->predelega('TINIT-rssmra80a01h501u', $this->impresa, null, $this->operatore);

        $this->assertSame(self::CF, $delega->codice_fiscale);
    }

    public function test_allinea_ignora_utenti_non_spid(): void
    {
        $ldap = User::factory()->create(['auth_source' => 'ldap', 'codice_fiscale' => self::CF]);
        $ldap->assignRole('gestore');
        $this->servizio->predelega(self::CF, $this->impresa, null, $this->operatore);

        $this->servizio->allinea($ldap);

        $this->assertTrue($ldap->fresh()->hasRole('gestore'));
        $this->assertNull(DelegaImpresa::first()->user_id);
    }
}
