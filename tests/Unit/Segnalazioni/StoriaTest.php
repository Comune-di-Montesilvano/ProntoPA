<?php

namespace Tests\Unit\Segnalazioni;

use App\Support\Segnalazioni\Storia;
use Database\Seeders\ImpostazioniSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** La storia raggruppata per giorno, con le righe dei giorni vuoti. Oggi = lunedì 16/11/2026. */
class StoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ImpostazioniSeeder::class);
    }

    private function voce(string $quando): array
    {
        return ['quando' => Carbon::parse($quando), 'frase' => 'x'];
    }

    /** @param  list<string>  $date */
    private function giorni(array $date): array
    {
        rsort($date);

        return Storia::giorni(collect(array_map(fn ($d) => $this->voce($d), $date)), Carbon::parse('2026-11-16 10:00:00'));
    }

    public function test_raggruppa_per_giorno_dal_piu_recente(): void
    {
        $g = $this->giorni(['2026-11-16 09:00:00', '2026-11-16 08:00:00', '2026-11-15 18:00:00', '2025-11-14 09:00:00']);

        $this->assertSame('Oggi · lunedì 16 novembre', $g[0]['intestazione']);
        $this->assertCount(2, $g[0]['voci']);
        $this->assertSame('Ieri · domenica 15 novembre', $g[1]['intestazione']);
        $this->assertSame('Venerdì 14 novembre 2025', end($g)['intestazione']);
    }

    public function test_vuoto_lavorativo(): void
    {
        $g = $this->giorni(['2026-11-13 09:00:00', '2026-11-10 09:00:00']);

        $this->assertSame('Mercoledì e giovedì · nessuna attività · 2 giorni lavorativi fermi', $g[1]['vuoto']);
    }

    public function test_vuoto_fine_settimana_ufficio_chiuso(): void
    {
        $g = $this->giorni(['2026-11-16 09:00:00', '2026-11-13 09:00:00']);

        $this->assertSame('Sabato e domenica · nessuna attività, ufficio chiuso', $g[1]['vuoto']);
    }

    public function test_vuoto_lungo_dal_al(): void
    {
        $g = $this->giorni(['2026-11-10 09:00:00', '2026-11-02 09:00:00']);

        $this->assertSame('Dal 3 al 9 novembre · nessuna attività · 5 giorni lavorativi fermi', $g[1]['vuoto']);
    }
}
