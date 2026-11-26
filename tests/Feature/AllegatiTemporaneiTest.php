<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Segnalazioni\AllegatiTemporanei;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

/** 1.0.20: foto caricate a blocchi da 512 KB (proxy dell'ente con limite 1 MB), poi inviate col form come id. */
class AllegatiTemporaneiTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $urp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Storage::fake('local');
        $this->urp = User::factory()->create(['auth_source' => 'ldap']);
        $this->urp->assignRole('segnalatore');
    }

    /** PNG vero oltre i 512 KB (pixel casuali, non comprimibili): servono due blocchi. */
    private function fotoGrande(): string
    {
        $img = imagecreatetruecolor(420, 420);
        for ($x = 0; $x < 420; $x++) {
            for ($y = 0; $y < 420; $y++) {
                imagesetpixel($img, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($img, null, 0);
        $byte = (string) ob_get_clean();
        $this->assertGreaterThan(AllegatiTemporanei::DIMENSIONE_BLOCCO, strlen($byte));

        return $byte;
    }

    private function blocco(User $u, string $id, int $indice, int $totale, string $byte, string $nome = 'guasto.png'): TestResponse
    {
        return $this->actingAs($u)->post(route('allegati.temporanei.store'), [
            'id' => $id, 'indice' => $indice, 'totale' => $totale, 'nome' => $nome,
            'blocco' => UploadedFile::fake()->createWithContent('blocco', $byte),
        ], ['Accept' => 'application/json']);
    }

    /** Carica tutti i blocchi e restituisce l'id. */
    private function carica(User $u, string $byte, string $nome = 'guasto.png'): string
    {
        $id = (string) Str::uuid();
        $parti = str_split($byte, AllegatiTemporanei::DIMENSIONE_BLOCCO);
        foreach ($parti as $i => $parte) {
            $this->blocco($u, $id, $i, count($parti), $parte, $nome)
                ->assertOk()->assertJson(['completo' => $i === count($parti) - 1]);
        }

        return $id;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function dati(array $extra = []): array
    {
        return array_merge([
            'id_plesso' => $this->idPlessi($this->istitutoConPlessi())[0],
            'id_tipologia_segnalazione' => 1,
            'titolo' => 'Infissi rotti',
            'testo_segnalazione' => 'Gli avvolgibili non si chiudono.',
        ], $extra);
    }

    public function test_foto_a_blocchi_arriva_intera_con_la_segnalazione(): void
    {
        $byte = $this->fotoGrande();
        $id = $this->carica($this->urp, $byte);

        $this->actingAs($this->urp)->post(route('segnalazioni.store'), $this->dati(['allegati_caricati' => [$id]]))
            ->assertSessionHasNoErrors();

        $allegato = Segnalazione::firstOrFail()->allegati()->firstOrFail();
        $this->assertSame('guasto.png', $allegato->nome_originale);
        $this->assertSame('image/png', $allegato->tipo);
        $this->assertSame($byte, Storage::disk('local')->get($allegato->percorso));
        $this->assertNull(AllegatiTemporanei::file($this->urp, $id), 'usata: la copia temporanea si cancella');
    }

    public function test_scuola_soddisfa_la_foto_obbligatoria_con_una_caricata(): void
    {
        $scuola = $this->utenteSpid();
        $dati = $this->dati();
        $this->delega($scuola, \App\Models\Istituto::firstOrFail(), null);
        $id = $this->carica($scuola, (string) UploadedFile::fake()->image('a.jpg', 50, 50)->getContent(), 'a.jpg');

        $this->actingAs($scuola)->post(route('segnalazioni.store'), $dati + ['allegati_caricati' => [$id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Segnalazione::firstOrFail()->allegati()->count());
    }

    public function test_foto_di_un_altro_utente_ignorata(): void
    {
        $altro = User::factory()->create(['auth_source' => 'ldap']);
        $altro->assignRole('segnalatore');
        $id = $this->carica($altro, (string) UploadedFile::fake()->image('a.jpg')->getContent(), 'a.jpg');

        $this->actingAs($this->urp)->post(route('segnalazioni.store'), $this->dati(['allegati_caricati' => [$id, '../../x']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Segnalazione::firstOrFail()->allegati()->count());
        $this->assertNotNull(AllegatiTemporanei::file($altro, $id), 'la foto altrui resta al suo proprietario');
    }

    public function test_tolta_prima_dell_invio_non_arriva(): void
    {
        $id = $this->carica($this->urp, (string) UploadedFile::fake()->image('a.jpg')->getContent(), 'a.jpg');
        $this->actingAs($this->urp)->deleteJson(route('allegati.temporanei.destroy', $id))->assertOk();

        $this->assertNull(AllegatiTemporanei::file($this->urp, $id));
    }

    public function test_blocco_fuori_sequenza_rifiutato(): void
    {
        $byte = $this->fotoGrande();
        $id = (string) Str::uuid();
        $this->blocco($this->urp, $id, 0, 2, substr($byte, 0, AllegatiTemporanei::DIMENSIONE_BLOCCO))->assertOk();
        $this->blocco($this->urp, $id, 0, 2, substr($byte, 0, AllegatiTemporanei::DIMENSIONE_BLOCCO))->assertOk(); // ripartenza
        $this->blocco($this->urp, $id, 1, 3, 'x')->assertStatus(422);

        $id2 = (string) Str::uuid();
        $this->blocco($this->urp, $id2, 1, 2, 'x')->assertStatus(422)->assertJson(['errore' => 'Caricamento non riconosciuto: togli la foto e riprova.']);
    }

    public function test_blocco_oltre_512_kb_rifiutato(): void
    {
        $this->blocco($this->urp, (string) Str::uuid(), 0, 1, str_repeat('a', AllegatiTemporanei::DIMENSIONE_BLOCCO + 1))
            ->assertStatus(422);
    }

    public function test_file_troppo_grande_rifiutato_al_primo_blocco(): void
    {
        $this->blocco($this->urp, (string) Str::uuid(), 0, 21, str_repeat('a', AllegatiTemporanei::DIMENSIONE_BLOCCO))
            ->assertStatus(422)->assertJson(['errore' => 'Ogni file non può superare 10 MB.']);
    }

    public function test_tipo_non_consentito_rifiutato(): void
    {
        $id = (string) Str::uuid();
        $this->blocco($this->urp, $id, 0, 1, "solo testo\n", 'nota.txt')
            ->assertStatus(422)->assertJson(['errore' => 'Tipo di file non consentito.']);

        $this->assertNull(AllegatiTemporanei::file($this->urp, $id));
    }

    public function test_pulizia_cancella_solo_le_vecchie(): void
    {
        $vecchia = $this->carica($this->urp, (string) UploadedFile::fake()->image('a.jpg')->getContent(), 'a.jpg');
        $nuova = $this->carica($this->urp, (string) UploadedFile::fake()->image('b.jpg')->getContent(), 'b.jpg');
        $cartella = Storage::disk('local')->path('allegati-temporanei/'.$this->urp->id);
        touch($cartella.'/'.$vecchia.'.json', time() - (AllegatiTemporanei::ORE_VITA + 1) * 3600);

        $this->artisan('allegati:pulisci-temporanei')->assertSuccessful();

        $this->assertNull(AllegatiTemporanei::file($this->urp, $vecchia));
        $this->assertFileDoesNotExist($cartella.'/'.$vecchia.'.part');
        $this->assertNotNull(AllegatiTemporanei::file($this->urp, $nuova));
    }

    public function test_form_usa_il_caricamento_a_blocchi(): void
    {
        $this->actingAs($this->urp)->get(route('segnalazioni.create'))
            ->assertOk()
            ->assertSee('x-data="paFoto(', false)
            ->assertSee('allegati_caricati[]', false)
            ->assertDontSee('name="allegati[]"', false);
    }
}
