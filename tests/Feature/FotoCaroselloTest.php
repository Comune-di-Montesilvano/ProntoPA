<?php

namespace Tests\Feature;

use App\Models\AllegatoSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 1.0.17: le foto si aprono nel browser (inline) in un carosello nella stessa pagina, senza scaricarle. */
class FotoCaroselloTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Storage::fake('local');
    }

    private function admin(): User
    {
        $user = User::factory()->create(['amministratore' => true, 'attivo' => true]);
        $user->syncRoles(['admin']);

        return $user;
    }

    private function allegato(Segnalazione $s, string $nome, string $tipo, array $extra = []): AllegatoSegnalazione
    {
        $percorso = 'allegati/'.$s->id_segnalazione.'/'.$nome;
        Storage::disk('local')->put($percorso, 'contenuto');

        return AllegatoSegnalazione::create(array_merge([
            'id_segnalazione' => $s->id_segnalazione,
            'percorso' => $percorso,
            'tipo' => $tipo,
            'nome_originale' => $nome,
            'dimensione' => 9,
            'id_utente_creazione' => User::factory()->create()->id,
        ], $extra));
    }

    public function test_foto_servita_inline_non_come_download(): void
    {
        $s = Segnalazione::factory()->create();
        $foto = $this->allegato($s, 'guasto.jpg', 'image/jpeg');

        $r = $this->actingAs($this->admin())->get(route('segnalazioni.allegati.foto', [$s, $foto]));

        $r->assertOk();
        $this->assertStringStartsWith('inline', (string) $r->headers->get('Content-Disposition'));
        $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    public function test_non_immagini_e_svg_non_si_aprono_inline(): void
    {
        $s = Segnalazione::factory()->create();
        $admin = $this->admin();

        // SVG può contenere script: mai servito inline dal nostro dominio.
        $svg = $this->allegato($s, 'disegno.svg', 'image/svg+xml');
        $this->actingAs($admin)->get(route('segnalazioni.allegati.foto', [$s, $svg]))->assertNotFound();

        $pdf = $this->allegato($s, 'preventivo.pdf', 'application/pdf');
        $this->actingAs($admin)->get(route('segnalazioni.allegati.foto', [$s, $pdf]))->assertNotFound();
    }

    public function test_foto_stesse_regole_del_download(): void
    {
        $s = Segnalazione::factory()->create();
        $altra = Segnalazione::factory()->create();
        $infetta = $this->allegato($s, 'virus.jpg', 'image/jpeg', ['stato_scansione' => 'infetto']);
        $buona = $this->allegato($s, 'ok.jpg', 'image/jpeg');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('segnalazioni.allegati.foto', [$s, $infetta]))->assertForbidden();
        $this->actingAs($admin)->get(route('segnalazioni.allegati.foto', [$altra, $buona]))->assertNotFound();

        $estraneo = User::factory()->create(['attivo' => true]);
        $estraneo->syncRoles(['segnalatore']);
        $this->actingAs($estraneo)->get(route('segnalazioni.allegati.foto', [$s, $buona]))->assertForbidden();
    }

    public function test_scheda_apre_le_foto_nel_carosello(): void
    {
        $s = Segnalazione::factory()->create();
        $prima = $this->allegato($s, 'prima.jpg', 'image/jpeg');
        $dopo = $this->allegato($s, 'dopo.png', 'image/png', ['fase' => 'dopo']);

        $html = $this->actingAs($this->admin())->get(route('segnalazioni.show', $s))->assertOk()->getContent();

        foreach ([$prima, $dopo] as $foto) {
            $url = route('segnalazioni.allegati.foto', [$s, $foto]);
            $this->assertStringContainsString('href="'.$url.'"', $html);
        }
        $this->assertStringContainsString('data-galleria="segnalazione-'.$s->id_segnalazione.'"', $html);
        // Il carosello ha anche il link per scaricare l'originale.
        $this->assertStringContainsString('data-scarica="'.route('segnalazioni.allegati.download', [$s, $prima]).'"', $html);
    }
}
