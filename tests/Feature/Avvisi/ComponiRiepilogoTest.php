<?php

namespace Tests\Feature\Avvisi;

use App\Enums\SegnalazioneStato;
use App\Models\AvvisoInAttesa;
use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\OperatoreAssegnatoNotification;
use App\Notifications\Riepilogo\Raggruppabile;
use App\Notifications\SlaViolazioneNotification;
use App\Services\Avvisi\ComponiRiepilogo;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComponiRiepilogoTest extends TestCase
{
    use RefreshDatabase;

    private User $attore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Cache::flush();
        Impostazione::set('digest_enabled', false);
        $this->attore = User::factory()->create(['auth_source' => 'ldap']);
        $this->attore->syncRoles(['admin']);
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

    private function assegnazione(Segnalazione $s): OperatoreAssegnatoNotification
    {
        return new OperatoreAssegnatoNotification($s, Azione::findOrFail(2), $this->attore);
    }

    /** @param  Notification&Raggruppabile  $notifica */
    private function riga(User $chi, Notification $notifica, int $minutiFa = 0): AvvisoInAttesa
    {
        $riga = AvvisoInAttesa::create([
            'id_notifica'       => (string) Str::uuid(),
            'destinatario_tipo' => AvvisoInAttesa::TIPO_UTENTE,
            'id_utente'         => $chi->id,
            'email'             => '',
            'categoria'         => $notifica->categoriaRiepilogo(),
            'ritmo'             => 'ora',
            'id_segnalazione'   => $notifica->segnalazioneRiepilogo(),
            'tipo'              => class_basename($notifica),
            'notifica'          => serialize($notifica),
        ]);
        $riga->created_at = now()->subMinutes($minutiFa);
        $riga->save();

        return $riga;
    }

    /** @param  list<AvvisoInAttesa>  $righe */
    private function righe(array $righe): Collection
    {
        return AvvisoInAttesa::whereIn('id', array_map(fn ($r) => $r->id, $righe))->get();
    }

    public function test_stessa_segnalazione_categorie_diverse_una_voce_con_tutti_i_motivi(): void
    {
        $operaio = $this->utente('operaio');
        $s = $this->segnalazione([
            'id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value,
            'id_operatore_assegnato' => $operaio->id,
            'sla_violato' => true,
            'data_scadenza_sla' => now()->subDay(),
        ]);
        $a = $this->riga($operaio, $this->assegnazione($s), 10);
        $b = $this->riga($operaio, new SlaViolazioneNotification($s), 5);

        $c = ComponiRiepilogo::componi($this->righe([$a, $b]), $operaio, false);

        $this->assertSame(['assegnazioni'], array_keys($c->sezioni));
        $this->assertCount(1, $c->sezioni['assegnazioni']);
        $voce = $c->sezioni['assegnazioni'][0];
        $this->assertSame(['Assegnata a te', 'Scaduta'], $voce->motivi);
        $this->assertSame('assegnazioni', $voce->categoria);
        $this->assertSame($s->id_segnalazione, $voce->segnalazione->id_segnalazione);
        $this->assertStringStartsWith('#'.$s->id_segnalazione, $voce->riga);
        $this->assertInstanceOf(OperatoreAssegnatoNotification::class, $voce->notifica);
        $this->assertNull($c->unica);
        $this->assertSame(1, $c->totaleSegnalazioni());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $c->idRigheUsate);
    }

    public function test_avviso_non_piu_valido_sparisce(): void
    {
        $operaio = $this->utente('operaio');
        $altro = $this->utente('operaio');
        $s = $this->segnalazione([
            'id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value,
            'id_operatore_assegnato' => $operaio->id,
        ]);
        $a = $this->riga($operaio, $this->assegnazione($s));
        $s->update(['id_operatore_assegnato' => $altro->id]);

        $c = ComponiRiepilogo::componi($this->righe([$a]), $operaio, false);

        $this->assertTrue($c->vuota());
        $this->assertNull($c->unica);
        $this->assertSame([$a->id], $c->idRigheUsate);
        $this->assertSame(0, $c->totaleSegnalazioni());
    }

    public function test_segnalazione_eliminata_scartata_resto_parte(): void
    {
        $operaio = $this->utente('operaio');
        $mia = ['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value, 'id_operatore_assegnato' => $operaio->id];
        $eliminata = $this->segnalazione($mia);
        $resta = $this->segnalazione($mia);
        $a = $this->riga($operaio, $this->assegnazione($eliminata), 10);
        $b = $this->riga($operaio, $this->assegnazione($resta), 5);
        $eliminata->delete();

        $c = ComponiRiepilogo::componi($this->righe([$a, $b]), $operaio, false);

        $this->assertFalse($c->vuota());
        $this->assertSame([$resta->id_segnalazione], array_map(fn ($v) => $v->segnalazione->id_segnalazione, $c->sezioni['assegnazioni']));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $c->idRigheUsate);
    }

    public function test_una_sola_voce_un_motivo_restituisce_unica(): void
    {
        $operaio = $this->utente('operaio');
        $s = $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::ASSEGNATA_OPERATORE->value, 'id_operatore_assegnato' => $operaio->id]);
        // Due avvisi della stessa categoria: conta il più recente, resta un motivo solo.
        $vecchia = $this->riga($operaio, $this->assegnazione($s), 30);
        $recente = $this->riga($operaio, $this->assegnazione($s), 1);

        $c = ComponiRiepilogo::componi($this->righe([$recente, $vecchia]), $operaio, false);

        $this->assertInstanceOf(OperatoreAssegnatoNotification::class, $c->unica);
        $this->assertSame($s->id_segnalazione, $c->unica->segnalazioneRiepilogo());
        $this->assertSame(['Assegnata a te'], $c->sezioni['assegnazioni'][0]->motivi);
        $this->assertEqualsCanonicalizing([$vecchia->id, $recente->id], $c->idRigheUsate);
    }

    public function test_segnalazione_nel_quadro_non_ripetuta(): void
    {
        Impostazione::set('digest_enabled', true);
        $gestore = $this->utente('gestore', ['gestore_segnalazioni' => true, 'gestore_tutte_tipologie' => false]);
        $s = $this->segnalazione([
            'id_stato_segnalazione' => SegnalazioneStato::IN_CARICO->value,
            'id_operatore_assegnato' => $gestore->id,
        ]);
        $a = $this->riga($gestore, $this->assegnazione($s));

        $c = ComponiRiepilogo::componi($this->righe([$a]), $gestore, true);

        $this->assertSame([], $c->sezioni);
        $this->assertCount(1, $c->quadro['da_fare']);
        $voce = $c->quadro['da_fare'][0];
        $this->assertSame('da_fare', $voce->categoria);
        $this->assertSame(['Assegnata a te'], $voce->motivi);
        $this->assertNull($voce->notifica);
        $this->assertStringStartsWith('#'.$s->id_segnalazione, $voce->riga);
        $this->assertSame([], $c->quadro['nuove']);
        $this->assertSame([], $c->quadro['in_ritardo']);
        $this->assertNull($c->unica);
        $this->assertFalse($c->vuota());
        $this->assertSame(1, $c->totaleSegnalazioni());
        $this->assertSame([$a->id], $c->idRigheUsate);
    }

    public function test_senza_quadro_non_lo_calcola(): void
    {
        Impostazione::set('digest_enabled', true);
        $gestore = $this->utente('gestore', ['gestore_segnalazioni' => true, 'gestore_tutte_tipologie' => false]);
        $s = $this->segnalazione(['id_stato_segnalazione' => SegnalazioneStato::IN_CARICO->value, 'id_operatore_assegnato' => $gestore->id]);
        $a = $this->riga($gestore, $this->assegnazione($s));

        $c = ComponiRiepilogo::componi($this->righe([$a]), $gestore, false);

        $this->assertTrue($c->quadroVuoto());
        $this->assertCount(1, $c->sezioni['assegnazioni']);
        $this->assertInstanceOf(OperatoreAssegnatoNotification::class, $c->unica);
    }

    public function test_supervisore_non_assegnata_scaduta_solo_in_ritardo(): void
    {
        Impostazione::set('digest_enabled', true);
        $supervisore = $this->utente('gestore', ['gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true]);
        $s = $this->segnalazione([
            'id_stato_segnalazione' => SegnalazioneStato::NUOVA->value,
            'id_operatore_assegnato' => 0,
            'sla_violato' => true,
            'data_scadenza_sla' => now()->subDay(),
        ]);
        $a = $this->riga($supervisore, new SlaViolazioneNotification($s));

        $c = ComponiRiepilogo::componi($this->righe([$a]), $supervisore, true);

        $this->assertSame(['nuove', 'da_fare', 'in_ritardo'], array_keys($c->quadro));
        $this->assertSame([], $c->quadro['nuove']);
        $this->assertSame([], $c->quadro['da_fare']);
        $this->assertCount(1, $c->quadro['in_ritardo']);
        $this->assertSame(['Scaduta'], $c->quadro['in_ritardo'][0]->motivi);
        $this->assertSame([], $c->sezioni);
        $voci = array_sum(array_map('count', $c->quadro));
        $this->assertSame($voci, $c->totaleSegnalazioni());
        $this->assertSame(1, $c->totaleSegnalazioni());
    }
}
