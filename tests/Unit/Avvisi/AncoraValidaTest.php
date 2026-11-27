<?php

namespace Tests\Unit\Avvisi;

use App\Models\Azione;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use App\Notifications\AccorpataNotification;
use App\Notifications\OperatoreAssegnatoNotification;
use App\Notifications\RichiestaInformazioniNotification;
use App\Notifications\SlaViolazioneNotification;
use App\Notifications\SlaWarningNotification;
use App\Notifications\SquadraAssegnataNotification;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Al giro del riepilogo l'avviso vale ancora? Si rilegge la segnalazione dal DB. */
class AncoraValidaTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Segnalazione::$silenziaAvvisi = true;
        $this->gestore = User::factory()->create();
        $this->gestore->syncRoles(['gestore']);
    }

    protected function tearDown(): void
    {
        Segnalazione::$silenziaAvvisi = false;
        parent::tearDown();
    }

    private function operaio(): User
    {
        $u = User::factory()->create();
        $u->syncRoles(['operaio']);

        return $u;
    }

    public function test_assegnazione_riassegnata_ad_altro_operaio_non_vale_piu(): void
    {
        $primo = $this->operaio();
        $secondo = $this->operaio();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $primo->id]);
        $notifica = new OperatoreAssegnatoNotification($s, Azione::where('codice', 'assegna_operatore')->firstOrFail(), $this->gestore);

        $this->assertTrue($notifica->ancoraValida($primo));
        $this->assertFalse($notifica->ancoraValida($secondo));

        Segnalazione::whereKey($s->id_segnalazione)->update(['id_operatore_assegnato' => $secondo->id]);

        $this->assertFalse($notifica->ancoraValida($primo));
    }

    public function test_assegnazione_chiusa_non_vale_piu(): void
    {
        $operaio = $this->operaio();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id]);
        $notifica = new OperatoreAssegnatoNotification($s, Azione::where('codice', 'assegna_operatore')->firstOrFail(), $this->gestore);

        Segnalazione::whereKey($s->id_segnalazione)->update(['id_stato_segnalazione' => 7]);

        $this->assertFalse($notifica->ancoraValida($operaio));
    }

    public function test_squadra_vale_solo_per_il_caposquadra_della_squadra_ancora_assegnata(): void
    {
        $capo = $this->operaio();
        $altroCapo = $this->operaio();
        $squadra = Squadra::create(['nome' => 'Elettricisti', 'id_caposquadra' => $capo->id, 'attiva' => true]);
        $altra = Squadra::create(['nome' => 'Idraulici', 'id_caposquadra' => $altroCapo->id, 'attiva' => true]);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_squadra_assegnata' => $squadra->id_squadra]);
        $notifica = new SquadraAssegnataNotification($s->id_segnalazione, $squadra->id_squadra);

        $this->assertTrue($notifica->ancoraValida($capo));
        $this->assertFalse($notifica->ancoraValida($altroCapo));

        Segnalazione::whereKey($s->id_segnalazione)->update(['id_squadra_assegnata' => $altra->id_squadra]);
        $this->assertFalse($notifica->ancoraValida($capo));

        $sparita = new SquadraAssegnataNotification(999999, $squadra->id_squadra);
        $this->assertFalse($sparita->ancoraValida($capo));
    }

    public function test_sla_scaduta_poi_chiusa_non_vale_piu(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'sla_violato' => true]);
        $notifica = new SlaViolazioneNotification($s);

        $this->assertTrue($notifica->ancoraValida($this->gestore));

        Segnalazione::whereKey($s->id_segnalazione)->update(['id_stato_segnalazione' => 7]);

        $this->assertFalse($notifica->ancoraValida($this->gestore));
    }

    public function test_sla_in_scadenza_non_vale_piu_se_gia_scaduta(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'sla_violato' => false]);
        $notifica = new SlaWarningNotification($s);

        $this->assertTrue($notifica->ancoraValida($this->gestore));

        Segnalazione::whereKey($s->id_segnalazione)->update(['sla_violato' => true]);

        $this->assertFalse($notifica->ancoraValida($this->gestore));
    }

    public function test_richiesta_informazioni_con_stato_tornato_in_carico_non_vale_piu(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 11]);
        $notifica = new RichiestaInformazioniNotification($s, 'Quale aula?', now()->addDays(5));

        $this->assertTrue($notifica->ancoraValida($this->gestore));

        Segnalazione::whereKey($s->id_segnalazione)->update(['id_stato_segnalazione' => 2]);

        $this->assertFalse($notifica->ancoraValida($this->gestore));
    }

    public function test_segnalazione_eliminata_non_vale_piu(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 8]);
        $destinazione = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);
        $notifica = new AccorpataNotification($destinazione, $s, 'lavoro');

        $this->assertTrue($notifica->ancoraValida($this->gestore));
        $this->assertSame($destinazione->id_segnalazione, $notifica->segnalazioneRiepilogo());
        $this->assertStringContainsString('ha assorbito la #'.$s->id_segnalazione, $notifica->rigaRiepilogo($this->gestore));

        $destinazione->delete();

        $this->assertFalse($notifica->ancoraValida($this->gestore));
    }
}
