<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\DigestGestoreNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VisibilitaGestoreTipologiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param list<int> $tipologie */
    private function gestore(array $tipologie = [], bool $tutte = false): User
    {
        $user = User::factory()->create([
            'gestore_segnalazioni' => true,
            'gestore_tutte_tipologie' => $tutte,
            'attivo' => true,
        ]);
        $user->assignRole('gestore');
        $user->tipologieGestite()->sync($tipologie);

        return $user;
    }

    private function nuova(int $tipologia, array $attributi = []): Segnalazione
    {
        return Segnalazione::factory()->create(array_merge([
            'id_tipologia_segnalazione' => $tipologia,
            'id_stato_segnalazione' => SegnalazioneStato::NUOVA->value,
            'id_operatore_assegnato' => 0,
        ], $attributi));
    }

    /** @return list<int> */
    private function visibili(User $user): array
    {
        return Segnalazione::visibileA($user)->orderBy('id_segnalazione')->pluck('id_segnalazione')->all();
    }

    public function test_gestore_vede_nuove_della_sua_tipologia_e_non_delle_altre(): void
    {
        $g = $this->gestore([1]);
        $mia = $this->nuova(1);
        $altra = $this->nuova(3);

        $this->assertSame([$mia->id_segnalazione], $this->visibili($g));
        $this->actingAs($g)->get(route('segnalazioni.show', $altra))->assertForbidden();
    }

    public function test_gestore_tutte_vede_tutto(): void
    {
        $g = $this->gestore(tutte: true);
        $a = $this->nuova(1);
        $b = $this->nuova(3);

        $this->assertSame([$a->id_segnalazione, $b->id_segnalazione], $this->visibili($g));
    }

    public function test_gestore_vede_ancora_assegnate_fuori_tipologia(): void
    {
        $g = $this->gestore([1]);
        $assegnata = $this->nuova(3, ['id_operatore_assegnato' => $g->id]);

        $this->assertSame([$assegnata->id_segnalazione], $this->visibili($g));
    }

    public function test_gestore_tipologia_puo_assegnare(): void
    {
        $g = $this->gestore([1]);
        $s = $this->nuova(1);

        $this->assertTrue($g->can('update', $s));
        $this->assertTrue($g->can('modificaPriorita', $s));
        $this->assertTrue($g->can('accorpa', $s));
        $this->assertFalse($g->can('update', $this->nuova(3)));
    }

    public function test_gestore_tipologia_non_elimina_allegati(): void
    {
        $g = $this->gestore([1]);

        $this->assertFalse($g->can('deleteAllegato', $this->nuova(1)));
    }

    public function test_due_gestori_stessa_tipologia_vedono_entrambi(): void
    {
        $a = $this->gestore([1]);
        $b = $this->gestore([1]);
        $s = $this->nuova(1);

        $this->assertSame([$s->id_segnalazione], $this->visibili($a));
        $this->assertSame([$s->id_segnalazione], $this->visibili($b));
    }

    /** Il limite di 50 vale per il gestore, non per l'elenco globale: le sue non spariscono dietro le altre. */
    public function test_digest_limite_applicato_dopo_il_filtro_tipologia(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        Impostazione::set('digest_enabled', true);
        Impostazione::set('digest_skip_weekend', false);
        Notification::fake();

        $g = $this->gestore([1]);
        for ($i = 0; $i < 51; $i++) {
            $this->nuova(3, ['livello_priorita' => 4]);
        }
        $mia = $this->nuova(1, ['livello_priorita' => 1]);

        $this->artisan('digest:invia')->assertExitCode(0);

        Notification::assertSentTo($g, DigestGestoreNotification::class,
            fn (DigestGestoreNotification $n) => $n->nonAssegnateIds === [$mia->id_segnalazione]);
    }

    public function test_digest_non_assegnate_filtrate_per_tipologia(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        Impostazione::set('digest_enabled', true);
        Impostazione::set('digest_skip_weekend', false);
        Notification::fake();

        $g = $this->gestore([1]);
        $mia = $this->nuova(1);
        $this->nuova(3);

        $this->artisan('digest:invia')->assertExitCode(0);

        Notification::assertSentTo($g, DigestGestoreNotification::class,
            fn (DigestGestoreNotification $n) => $n->nonAssegnateIds === [$mia->id_segnalazione]);
    }
}
