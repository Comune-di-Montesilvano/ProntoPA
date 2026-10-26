<?php

namespace Tests\Unit\Segnalazioni;

use App\Enums\SegnalazioneStato;
use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Segnalazioni\LetturaScheda;
use App\Support\Segnalazioni\Scadenza;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PresentazioneTest extends TestCase
{
    use RefreshDatabase;

    public function test_tono_per_stato(): void
    {
        $this->assertSame('slate', SegnalazioneStato::NUOVA->tono());
        $this->assertSame('primary', SegnalazioneStato::IN_CARICO->tono());
        $this->assertSame('violet', SegnalazioneStato::ASSEGNATA_OPERATORE->tono());
        $this->assertSame('violet', SegnalazioneStato::ASSEGNATA_IMPRESA->tono());
        $this->assertSame('amber', SegnalazioneStato::PREVENTIVO_IN_ATTESA->tono());
        $this->assertSame('amber', SegnalazioneStato::SOSPESA->tono());
        $this->assertSame('emerald', SegnalazioneStato::COMPLETATA->tono());
        $this->assertSame('slate', SegnalazioneStato::DUPLICATA->tono());
    }

    public function test_etichetta_duplicata_diventa_accorpata(): void
    {
        $this->assertSame('Accorpata', SegnalazioneStato::DUPLICATA->etichetta());
        $this->assertSame('In carico', SegnalazioneStato::IN_CARICO->etichetta());
    }

    public function test_frase_segnalante(): void
    {
        $this->assertSame('Risolta', SegnalazioneStato::COMPLETATA->fraseSegnalante()['titolo']);
        $this->assertSame('Ci stiamo lavorando', SegnalazioneStato::ASSEGNATA_IMPRESA->fraseSegnalante()['titolo']);
        $this->assertSame('Già in lavorazione', SegnalazioneStato::DUPLICATA->fraseSegnalante()['titolo']);
        $this->assertNotSame('', SegnalazioneStato::NUOVA->fraseSegnalante()['dettaglio']);
    }

    public function test_tappa(): void
    {
        $this->assertSame(1, SegnalazioneStato::NUOVA->tappa());
        $this->assertSame(2, SegnalazioneStato::IN_CARICO->tappa());
        $this->assertSame(3, SegnalazioneStato::SOSPESA->tappa());
        $this->assertSame(3, SegnalazioneStato::PREVENTIVO_IN_ATTESA->tappa());
        $this->assertSame(4, SegnalazioneStato::ANNULLATA->tappa());
    }

    public function test_scadenza(): void
    {
        $ora = Carbon::parse('2026-10-25 12:00');
        $s = new Segnalazione;

        $s->data_scadenza_sla = $ora->copy()->subHours(50);
        $this->assertSame(['testo' => 'Superata da 2 g', 'tono' => 'rose'], Scadenza::per($s, $ora));

        $s->data_scadenza_sla = $ora->copy()->subHours(5);
        $this->assertSame(['testo' => 'Superata da 5 h', 'tono' => 'rose'], Scadenza::per($s, $ora));

        $s->data_scadenza_sla = $ora->copy()->addHours(6);
        $this->assertSame(['testo' => 'Entro 6 h', 'tono' => 'amber'], Scadenza::per($s, $ora));

        $s->data_scadenza_sla = $ora->copy()->addDays(4);
        $this->assertSame(['testo' => 'Entro 4 g', 'tono' => 'slate'], Scadenza::per($s, $ora));
        $this->assertSame('Scade il 29/10', Scadenza::estesa($s, $ora)['testo']);

        $s->data_scadenza_sla = $ora->copy()->subHours(50);
        $this->assertSame('Tempo massimo superato da 2 giorni', Scadenza::estesa($s, $ora)['testo']);

        $s->data_chiusura = $ora;
        $this->assertNull(Scadenza::per($s, $ora));

        $this->assertNull(Scadenza::per(new Segnalazione, $ora));
    }

    public function test_lettura_per_ruolo(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $attese = ['admin' => 'gestore', 'gestore' => 'gestore', 'operaio' => 'lavoro', 'impresa' => 'lavoro', 'segnalatore' => 'segnalante'];

        foreach ($attese as $ruolo => $lettura) {
            $u = User::factory()->create();
            $u->assignRole($ruolo);
            $this->assertSame($lettura, LetturaScheda::per($u), $ruolo);
        }
    }
}
