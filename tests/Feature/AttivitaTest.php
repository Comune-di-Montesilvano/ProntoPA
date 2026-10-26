<?php

namespace Tests\Feature;

use App\Models\AllegatoSegnalazione;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\NotaDaEsternoNotification;
use App\Support\Segnalazioni\Attivita;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Storia unica della segnalazione (note, stati, allegati) letta per ruolo, e note scritte da ditta e segnalante. */
class AttivitaTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $operaio;

    private User $segnalatore;

    private User $ditta;

    private Segnalazione $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);

        $this->gestore = User::factory()->create(['name' => 'Anna Gestore', 'supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->operaio = User::factory()->create(['name' => 'Paolo Neri']);
        $this->operaio->assignRole('operaio');
        $this->segnalatore = User::factory()->create(['name' => 'Giulia Ferri']);
        $this->segnalatore->assignRole('segnalatore');

        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');

        $this->s = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $this->segnalatore->id,
            'id_stato_segnalazione' => 4,
            'id_appalto' => $appalto->id_appalto,
        ]);
        $storico = fn (int $stato, int $collegato = 0, int $appalto = 0, string $quando = '2026-10-21 10:00') => StoricoStatoSegnalazione::create([
            'id_segnalazione' => $this->s->id_segnalazione, 'id_stato_segnalazione' => $stato,
            'id_utente' => $this->gestore->id, 'id_utente_collegato' => $collegato, 'id_appalto' => $appalto,
        ])->forceFill(['data_registrazione' => $quando])->save();
        $storico(2, quando: '2026-10-21 09:00');
        $storico(3, $this->operaio->id, quando: '2026-10-21 10:00');
        $storico(4, 0, $appalto->id_appalto, '2026-10-22 10:00');
        NotaSegnalazione::create([
            'id_segnalazione' => $this->s->id_segnalazione, 'testo' => 'Ricambio ordinato',
            'id_utente' => $this->gestore->id, 'visibile_web' => true, 'visibile_impresa' => true,
        ]);
    }

    /** @return list<string> */
    private function testi(User $u): array
    {
        return Attivita::per($this->s->fresh(), $u)
            ->map(fn ($v) => $v['autore'].' | '.$v['frase'].' | '.($v['testo'] ?? ''))
            ->all();
    }

    public function test_segnalante_vede_ufficio_non_nomi(): void
    {
        $tutto = implode("\n", $this->testi($this->segnalatore));

        $this->assertStringNotContainsString('Anna Gestore', $tutto);
        $this->assertStringNotContainsString('Paolo Neri', $tutto);
        $this->assertStringNotContainsString('Edil Adriatica', $tutto);
        $this->assertStringContainsString('Ufficio manutenzioni', $tutto);
        $this->assertStringContainsString('Ricambio ordinato', $tutto);
    }

    public function test_ditta_vede_eventi_da_assegnazione(): void
    {
        $tutto = implode("\n", $this->testi($this->ditta));

        $this->assertStringNotContainsString('ha preso in carico', $tutto);
        $this->assertStringContainsString('Edil Adriatica', $tutto);
    }

    public function test_gestore_vede_frase_assegnazione(): void
    {
        $tutto = implode("\n", $this->testi($this->gestore));

        $this->assertStringContainsString('ha assegnato a Paolo Neri', $tutto);
        $this->assertStringContainsString('ha preso in carico', $tutto);
        $prima = Attivita::per($this->s->fresh(), $this->gestore)->first();
        $this->assertSame('Ricambio ordinato', $prima['testo']);
    }

    public function test_nota_ditta_forzata_visibile_impresa(): void
    {
        $this->actingAs($this->ditta)->post(route('segnalazioni.nota', $this->s), [
            'testo' => 'Passiamo domani', 'visibile_web' => '1',
        ])->assertRedirect();

        $nota = NotaSegnalazione::where('testo', 'Passiamo domani')->firstOrFail();
        $this->assertTrue($nota->visibile_impresa);
        $this->assertFalse($nota->visibile_web);
    }

    public function test_nota_segnalante_forzata_visibile_web_e_avvisa_supervisori(): void
    {
        Notification::fake();

        $this->actingAs($this->segnalatore)->post(route('segnalazioni.nota', $this->s), [
            'testo' => 'Ora non si chiude affatto', 'visibile_impresa' => '1',
        ])->assertRedirect();

        $nota = NotaSegnalazione::where('testo', 'Ora non si chiude affatto')->firstOrFail();
        $this->assertTrue($nota->visibile_web);
        $this->assertFalse($nota->visibile_impresa);
        Notification::assertSentTo($this->gestore, NotaDaEsternoNotification::class);
    }

    public function test_nota_segnalante_avvisa_gestore_assegnatario_se_presente(): void
    {
        Notification::fake();
        $assegnatario = User::factory()->create(['supervisore_segnalazioni' => false]);
        $assegnatario->assignRole('gestore');
        $this->s->update(['id_operatore_assegnato' => $assegnatario->id]);

        $this->actingAs($this->segnalatore)->post(route('segnalazioni.nota', $this->s), ['testo' => 'Sollecito']);

        Notification::assertSentTo($assegnatario, NotaDaEsternoNotification::class);
        Notification::assertNotSentTo($this->gestore, NotaDaEsternoNotification::class);
    }

    public function test_nota_con_foto_salva_allegato(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->actingAs($this->segnalatore)->post(route('segnalazioni.nota', $this->s), [
            'testo' => 'Ecco la foto',
            'allegati' => [UploadedFile::fake()->image('finestra.jpg')],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, AllegatoSegnalazione::where('id_segnalazione', $this->s->id_segnalazione)->count());
    }
}
