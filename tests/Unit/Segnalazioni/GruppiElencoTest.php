<?php

namespace Tests\Unit\Segnalazioni;

use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\TipologiaSegnalazione;
use App\Support\Segnalazioni\GruppiElenco;
use Tests\TestCase;

/** 1.0.4: celle di gruppo (rowspan) dell'elenco gestione raggruppato. */
class GruppiElencoTest extends TestCase
{
    private function riga(int $id, int $plesso, int $tipologia): Segnalazione
    {
        $s = new Segnalazione;
        $s->id_segnalazione = $id;
        $s->id_plesso = $plesso;
        $s->id_tipologia_segnalazione = $tipologia;
        $s->setRelation('plesso', $plesso ? new Plesso(['nome' => 'SEDE '.$plesso]) : null);
        $s->setRelation('tipologia', new TipologiaSegnalazione(['descrizione' => 'TIPO '.$tipologia, 'colore' => 'verde']));

        return $s;
    }

    public function test_livelli(): void
    {
        $this->assertSame([], GruppiElenco::livelli(''));
        $this->assertSame([], GruppiElenco::livelli('fucsia'));
        $this->assertSame(['sede'], GruppiElenco::livelli('sede'));
        $this->assertSame(['sede', 'tipologia'], GruppiElenco::livelli('sede_tipologia'));
        $this->assertSame(['tipologia', 'sede'], GruppiElenco::livelli('tipologia_sede'));
    }

    public function test_due_livelli_con_rowspan(): void
    {
        $pagina = collect([$this->riga(1, 5, 1), $this->riga(2, 5, 1), $this->riga(3, 5, 2), $this->riga(4, 6, 1)]);
        $righe = GruppiElenco::costruisci($pagina, 'sede_tipologia', [5 => ['totale' => 9, 'in_ritardo' => 4]], null);

        $this->assertCount(4, $righe);
        [$sede, $tipo] = $righe[0]['celle'];
        $this->assertSame(['sede', 3, 'Sede 5', 9, 4, false], [$sede['tipo'], $sede['rowspan'], $sede['nome'], $sede['totale'], $sede['in_ritardo'], $sede['continua']]);
        $this->assertSame([1, 2, 3], $sede['ids']);
        $this->assertSame(['tipologia', 2, 'Tipo 1', null, 'verde'], [$tipo['tipo'], $tipo['rowspan'], $tipo['nome'], $tipo['totale'], $tipo['tono']]);

        $this->assertSame([], $righe[1]['celle']);
        $this->assertCount(1, $righe[2]['celle']);
        $this->assertSame(1, $righe[2]['celle'][0]['rowspan']);
        $this->assertCount(2, $righe[3]['celle']);
        $this->assertSame(0, $righe[3]['celle'][0]['totale'], 'gruppo senza conteggio = 0');
    }

    public function test_gruppo_che_continua_dalla_pagina_prima(): void
    {
        $pagina = collect([$this->riga(7, 5, 2), $this->riga(8, 6, 2)]);

        $righe = GruppiElenco::costruisci($pagina, 'sede_tipologia', [], $this->riga(6, 5, 1));
        $this->assertTrue($righe[0]['celle'][0]['continua']);
        $this->assertFalse($righe[0]['celle'][1]['continua'], 'stessa sede, tipologia diversa');
        $this->assertFalse($righe[1]['celle'][0]['continua']);

        $righe = GruppiElenco::costruisci($pagina, 'sede_tipologia', [], $this->riga(6, 5, 2));
        $this->assertTrue($righe[0]['celle'][1]['continua']);
    }

    public function test_senza_sede(): void
    {
        $righe = GruppiElenco::costruisci(collect([$this->riga(1, 0, 1)]), 'sede', [], null);
        $this->assertSame('Senza sede', $righe[0]['celle'][0]['nome']);
        $this->assertSame(0, $righe[0]['celle'][0]['chiave']);
    }

    public function test_senza_gruppo_nessuna_cella(): void
    {
        $righe = GruppiElenco::costruisci(collect([$this->riga(1, 5, 1)]), '', [], null);
        $this->assertSame([], $righe[0]['celle']);
    }
}
