<?php

namespace Tests\Unit;

use App\Enums\SegnalazioneStato;
use App\Support\Colori;
use PHPUnit\Framework\TestCase;

class ColoriTest extends TestCase
{
    public function test_ogni_stato_ha_il_suo_colore(): void
    {
        $atteso = [
            1 => 'arancio', 2 => 'azzurro', 3 => 'blu', 4 => 'indaco',
            5 => 'giallo', 6 => 'giallo', 11 => 'giallo', 7 => 'verde',
            8 => 'grigio', 9 => 'grigio', 10 => 'grigio',
        ];
        foreach (SegnalazioneStato::cases() as $stato) {
            $this->assertSame($atteso[$stato->value], Colori::stato($stato), $stato->name);
            $this->assertSame($atteso[$stato->value], $stato->colore());
        }
    }

    public function test_priorita(): void
    {
        $this->assertSame('rosso', Colori::priorita(4));
        $this->assertSame('rosso', Colori::priorita(3));
        $this->assertNull(Colori::priorita(2));
        $this->assertSame('grigio', Colori::priorita(1));
    }

    public function test_tono_sconosciuto_e_grigio(): void
    {
        $this->assertSame(Colori::TONI['grigio'], Colori::tono('fucsia'));
        $this->assertSame(Colori::TONI['grigio'], Colori::tono(null));
        $this->assertSame(['sfondo' => '#dcfce7', 'testo' => '#166534', 'punto' => '#16a34a'], Colori::tono('verde'));
    }

    public function test_css_contiene_ogni_tono(): void
    {
        $css = Colori::css();
        $this->assertCount(13, Colori::TONI);
        foreach (Colori::TONI as $nome => $t) {
            $this->assertStringContainsString(".pa-tono-{$nome}{--tono-sfondo:{$t['sfondo']};--tono-testo:{$t['testo']};--tono-punto:{$t['punto']}}", $css);
        }
    }
}
