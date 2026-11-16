<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\TipologiaSegnalazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 1.0.4: stato = pallino, tipologia = sfondo pieno, priorità = etichetta, colori da App\Support\Colori. */
class ComponentiColoriTest extends TestCase
{
    use RefreshDatabase;

    public function test_stato_pallino_col_suo_colore_e_chi(): void
    {
        $html = (string) $this->blade('<x-pa.stato :stato="$s" chi="Mario Rossi" />', ['s' => SegnalazioneStato::ASSEGNATA_OPERATORE]);
        $this->assertStringContainsString('pa-tono-blu', $html);
        $this->assertStringContainsString('Assegnata', $html);
        $this->assertStringContainsString('Mario Rossi', $html);
        $this->assertStringNotContainsString('a Mario', $html);

        $nuova = (string) $this->blade('<x-pa.stato :stato="$s" />', ['s' => SegnalazioneStato::NUOVA]);
        $this->assertStringContainsString('pa-tono-arancio', $nuova);
        $this->assertStringNotContainsString('pa-stato-chi', $nuova);
    }

    public function test_stato_per_il_segnalante_usa_la_sua_frase(): void
    {
        $html = (string) $this->blade('<x-pa.stato :stato="$s" segnalante />', ['s' => SegnalazioneStato::NUOVA]);
        $this->assertStringContainsString('Ricevuta', $html);
    }

    public function test_tipologia_col_suo_colore(): void
    {
        $t = TipologiaSegnalazione::create(['descrizione' => 'VERDE E GIARDINI', 'icona' => 'fas fa-seedling', 'attiva' => true, 'colore' => 'verde']);
        $html = (string) $this->blade('<x-pa.tipologia :tipologia="$t" />', ['t' => $t]);
        $this->assertStringContainsString('pa-tono-verde', $html);
        $this->assertStringContainsString('Verde e Giardini', $html);
        $this->assertStringContainsString('fa-seedling', $html);
    }

    public function test_priorita_media_si_mostra_solo_se_chiesto(): void
    {
        $this->assertSame('', trim((string) $this->blade('<x-pa.priorita :livello="2" />')));
        $this->assertStringContainsString('Media', (string) $this->blade('<x-pa.priorita :livello="2" media />'));

        $critica = (string) $this->blade('<x-pa.priorita :livello="4" />');
        $this->assertStringContainsString('Critica', $critica);
        $this->assertStringContainsString('fa-angles-up', $critica);
        $this->assertStringContainsString('pa-priorita-4', $critica);

        $alta = (string) $this->blade('<x-pa.priorita :livello="3" />');
        $this->assertStringContainsString('pa-tono-rosso', $alta);

        $this->assertStringContainsString('Bassa', (string) $this->blade('<x-pa.prio :livello="1" />'));
        $this->assertStringContainsString('Media', (string) $this->blade('<x-pa.prio :livello="2" />'));
    }
}
