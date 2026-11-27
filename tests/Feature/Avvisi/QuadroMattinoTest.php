<?php

namespace Tests\Feature\Avvisi;

use App\Enums\SegnalazioneStato;
use App\Models\Appalto;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use App\Services\Avvisi\QuadroMattino;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

class QuadroMattinoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        \Illuminate\Support\Facades\Cache::flush();
        Impostazione::set('digest_enabled', true);
    }

    private function utente(string $ruolo, array $attr = []): User
    {
        $u = User::factory()->create($attr + ['attivo' => true]);
        $u->assignRole($ruolo);

        return $u;
    }

    private function segnalazione(array $attr = []): Segnalazione
    {
        Segnalazione::$silenziaAvvisi = true;
        try {
            return Segnalazione::factory()->create($attr);
        } finally {
            Segnalazione::$silenziaAvvisi = false;
        }
    }

    public function test_gestore_tipologia_vede_solo_le_sue_nuove(): void
    {
        $mia = TipologiaSegnalazione::first();
        $altra = TipologiaSegnalazione::where('id_tipologia_segnalazione', '!=', $mia->id_tipologia_segnalazione)->first();
        $gestore = $this->utente('gestore', ['gestore_segnalazioni' => true, 'gestore_tutte_tipologie' => false]);
        $gestore->tipologieGestite()->attach($mia->id_tipologia_segnalazione);

        $suo = $this->segnalazione(['id_tipologia_segnalazione' => $mia->id_tipologia_segnalazione]);
        $this->segnalazione(['id_tipologia_segnalazione' => $altra->id_tipologia_segnalazione]);

        $quadro = QuadroMattino::per($gestore);

        $this->assertSame([$suo->id_segnalazione], $quadro['nuove']->pluck('id_segnalazione')->all());
    }

    public function test_gestore_da_fare_include_in_carico_e_attesa_risposta_ed_esclude_in_ritardo(): void
    {
        $gestore = $this->utente('gestore', ['gestore_segnalazioni' => true, 'gestore_tutte_tipologie' => false]);
        $altro = $this->utente('gestore', ['gestore_segnalazioni' => true]);
        $mio = ['id_operatore_assegnato' => $gestore->id];

        $inCarico = $this->segnalazione($mio + ['id_stato_segnalazione' => SegnalazioneStato::IN_CARICO->value]);
        $attesa = $this->segnalazione($mio + ['id_stato_segnalazione' => SegnalazioneStato::ATTESA_RISPOSTA->value]);
        $ritardo = $this->segnalazione($mio + ['id_stato_segnalazione' => SegnalazioneStato::IN_CARICO->value, 'sla_violato' => true, 'data_scadenza_sla' => now()->subDay()]);
        $this->segnalazione(['id_operatore_assegnato' => $altro->id, 'id_stato_segnalazione' => SegnalazioneStato::IN_CARICO->value]);

        $quadro = QuadroMattino::per($gestore);

        $this->assertEqualsCanonicalizing([$inCarico->id_segnalazione, $attesa->id_segnalazione], $quadro['da_fare']->pluck('id_segnalazione')->all());
        $this->assertSame([$ritardo->id_segnalazione], $quadro['in_ritardo']->pluck('id_segnalazione')->all());
    }

    public function test_operaio_da_fare_include_squadra_da_caposquadra(): void
    {
        $operaio = $this->utente('operaio');
        $squadra = Squadra::create(['nome' => 'Idraulici', 'id_caposquadra' => $operaio->id, 'attiva' => true]);

        $diSquadra = $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value, 'id_squadra_assegnata' => $squadra->id_squadra]);
        $sua = $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value, 'id_operatore_assegnato' => $operaio->id]);
        $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value, 'id_operatore_assegnato' => $this->utente('operaio')->id]);
        $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::COMPLETATA->value, 'id_operatore_assegnato' => $operaio->id, 'data_chiusura' => now()]);

        $quadro = QuadroMattino::per($operaio);

        $this->assertEqualsCanonicalizing(
            [$diSquadra->id_segnalazione, $sua->id_segnalazione],
            $quadro['da_fare']->pluck('id_segnalazione')->all(),
        );
        $this->assertTrue($quadro['nuove']->isEmpty());
    }

    public function test_ditta_email_aziendale_da_fare(): void
    {
        $impresa = Impresa::factory()->create(['email' => 'Info@Ditta.it']);
        $appalto = Appalto::create(['descrizione' => 'Edile', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $altra = Appalto::create(['descrizione' => 'Altro', 'id_impresa' => Impresa::factory()->create()->id_impresa, 'valido' => true]);

        $nelTermine = $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_IMPRESA->value, 'id_appalto' => $appalto->id_appalto]);
        $ritardo = $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_IMPRESA->value, 'id_appalto' => $appalto->id_appalto, 'sla_violato' => true, 'data_scadenza_sla' => now()->subDay()]);
        $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_IMPRESA->value, 'id_appalto' => $altra->id_appalto]);

        $quadro = QuadroMattino::per((new AnonymousNotifiable)->route('mail', 'info@ditta.it'));

        $this->assertSame([$nelTermine->id_segnalazione], $quadro['da_fare']->pluck('id_segnalazione')->all());
        $this->assertSame([$ritardo->id_segnalazione], $quadro['in_ritardo']->pluck('id_segnalazione')->all());

        $persona = $this->utente('impresa', ['id_impresa' => $impresa->id_impresa, 'auth_source' => 'spid']);
        $this->assertCount(1, QuadroMattino::per($persona)['da_fare']);
    }

    public function test_supervisore_in_ritardo_tutte(): void
    {
        $sup = $this->utente('gestore', ['gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true]);
        $a = $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value, 'sla_violato' => true, 'data_scadenza_sla' => now()->subDays(2)]);
        $b = $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_IMPRESA->value, 'sla_violato' => true, 'data_scadenza_sla' => now()->subDay()]);
        $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value, 'sla_violato' => false]);

        $quadro = QuadroMattino::per($sup);

        $this->assertSame([$a->id_segnalazione, $b->id_segnalazione], $quadro['in_ritardo']->pluck('id_segnalazione')->all());
    }

    public function test_nuove_escludono_le_affidate_a_squadra_o_ditta(): void
    {
        $sup = $this->utente('gestore', ['gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true]);
        $capo = $this->utente('operaio');
        $squadra = Squadra::create(['nome' => 'Elettricisti', 'id_caposquadra' => $capo->id, 'attiva' => true]);
        $appalto = Appalto::create(['descrizione' => 'Edile', 'id_impresa' => Impresa::factory()->create()->id_impresa, 'valido' => true]);

        $daSmistare = $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::NUOVA->value]);
        $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value, 'id_operatore_assegnato' => 0, 'id_squadra_assegnata' => $squadra->id_squadra]);
        $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_IMPRESA->value, 'id_operatore_assegnato' => 0, 'id_appalto' => $appalto->id_appalto]);

        $this->assertSame([$daSmistare->id_segnalazione], QuadroMattino::per($sup)['nuove']->pluck('id_segnalazione')->all());
    }

    public function test_supervisore_in_ritardo_al_massimo_50_con_il_conto_delle_altre(): void
    {
        $sup = $this->utente('gestore', ['gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true]);
        for ($i = 0; $i < QuadroMattino::LIMITE_NUOVE + 3; $i++) {
            $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value, 'id_operatore_assegnato' => $sup->id, 'sla_violato' => true, 'data_scadenza_sla' => now()->subDays(2)]);
        }

        $quadro = QuadroMattino::per($sup);

        $this->assertCount(QuadroMattino::LIMITE_NUOVE, $quadro['in_ritardo']);
        $this->assertSame(['nuove' => 0, 'da_fare' => 0, 'in_ritardo' => 3], $quadro['altre']);
    }

    public function test_segnalante_quadro_vuoto(): void
    {
        $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::NUOVA->value]);
        $scuola = $this->utente('segnalatore', ['auth_source' => 'spid']);
        $urp = $this->utente('segnalatore', ['auth_source' => 'ldap']);

        foreach ([$scuola, $urp] as $u) {
            $quadro = QuadroMattino::per($u);
            $this->assertTrue($quadro['nuove']->isEmpty() && $quadro['da_fare']->isEmpty() && $quadro['in_ritardo']->isEmpty());
        }
    }

    public function test_digest_spento_quadro_vuoto(): void
    {
        Impostazione::set('digest_enabled', false);
        $sup = $this->utente('gestore', ['gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true]);
        $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::NUOVA->value]);

        $quadro = QuadroMattino::per($sup);

        $this->assertTrue($quadro['nuove']->isEmpty() && $quadro['da_fare']->isEmpty() && $quadro['in_ritardo']->isEmpty());
    }
}
