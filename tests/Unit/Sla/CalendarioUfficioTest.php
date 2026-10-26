<?php

namespace Tests\Unit\Sla;

use App\Services\Sla\CalendarioUfficio;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalendarioUfficioTest extends TestCase
{
    private const ORARIO = [
        'lun' => '08:00-14:00', 'mar' => '08:00-17:00', 'mer' => '08:00-14:00',
        'gio' => '08:00-17:00', 'ven' => '08:00-14:00', 'sab' => '', 'dom' => '',
    ];

    private function cal(?string $patrono = null, array $orario = self::ORARIO): CalendarioUfficio
    {
        return new CalendarioUfficio($orario, $patrono, 'Europe/Rome');
    }

    private function t(string $quando): Carbon
    {
        return Carbon::parse($quando, 'Europe/Rome');
    }

    private function assertMomento(string $atteso, ?Carbon $ottenuto): void
    {
        $this->assertNotNull($ottenuto);
        $this->assertSame($atteso, $ottenuto->copy()->setTimezone('Europe/Rome')->format('Y-m-d H:i'));
    }

    // 2026-10-24 sabato, 2026-10-26 lunedì

    public function test_ore_da_sabato_partono_da_lunedi(): void
    {
        $this->assertMomento('2026-10-26 14:00', $this->cal()->aggiungiOreLavorative($this->t('2026-10-24 10:00'), 6));
    }

    public function test_ore_da_venerdi_scavalcano_il_weekend(): void
    {
        $this->assertMomento('2026-11-02 11:00', $this->cal()->aggiungiOreLavorative($this->t('2026-10-30 13:00'), 4));
    }

    public function test_ore_a_cavallo_di_due_giorni(): void
    {
        $this->assertMomento('2026-10-28 08:30', $this->cal()->aggiungiOreLavorative($this->t('2026-10-27 16:30'), 1));
    }

    public function test_ore_da_lunedi_alla_chiusura_partono_da_martedi(): void
    {
        $this->assertMomento('2026-10-27 09:00', $this->cal()->aggiungiOreLavorative($this->t('2026-10-26 14:00'), 1));
    }

    public function test_ore_prima_dell_apertura_partono_dall_apertura(): void
    {
        $this->assertMomento('2026-10-26 09:00', $this->cal()->aggiungiOreLavorative($this->t('2026-10-26 07:00'), 1));
    }

    public function test_giorni_lavorativi(): void
    {
        $this->assertMomento('2026-10-27 17:00', $this->cal()->aggiungiGiorniLavorativi($this->t('2026-10-26 10:00'), 1));
        $this->assertMomento('2026-11-02 14:00', $this->cal()->aggiungiGiorniLavorativi($this->t('2026-10-30 13:00'), 1));
        $this->assertMomento('2026-10-26 14:00', $this->cal()->aggiungiGiorniLavorativi($this->t('2026-10-24 10:00'), 1));
    }

    public function test_pasqua_e_pasquetta_di_piu_anni(): void
    {
        $cal = $this->cal();
        foreach (['2026-04-05', '2027-03-28', '2028-04-16'] as $pasqua) {
            $this->assertTrue($cal->isFestivo($this->t($pasqua)), $pasqua);
            $this->assertTrue($cal->isFestivo($this->t($pasqua)->addDay()), "Pasquetta $pasqua");
        }
        $this->assertFalse($cal->isFestivo($this->t('2026-04-07')));
    }

    public function test_festivi_fissi(): void
    {
        $cal = $this->cal();
        $this->assertTrue($cal->isFestivo($this->t('2026-06-02')));
        $this->assertTrue($cal->isFestivo($this->t('2026-04-25')));
        $this->assertNull($cal->fascia($this->t('2026-06-02')));
        // 2/6/2026 martedì festivo: lun 13:00 + 2h → lun 14:00 (1h) + mer 08:00-09:00
        $this->assertMomento('2026-06-03 09:00', $cal->aggiungiOreLavorative($this->t('2026-06-01 13:00'), 2));
    }

    public function test_patrono_festivo(): void
    {
        $cal = $this->cal('13/06');
        $this->assertTrue($cal->isFestivo($this->t('2027-06-13')));
        $this->assertFalse($this->cal()->isFestivo($this->t('2027-06-14')));
        // 12/06/2026 venerdì, 13/06/2026 sabato (patrono di sabato): nessun effetto, lunedì aperto
        $this->assertMomento('2026-06-15 09:00', $cal->aggiungiOreLavorative($this->t('2026-06-12 13:00'), 2));
    }

    public function test_patrono_di_domenica_o_su_festivo_non_duplica(): void
    {
        $cal = $this->cal('14/06'); // domenica nel 2026
        $this->assertMomento('2026-06-15 09:00', $cal->aggiungiOreLavorative($this->t('2026-06-12 13:00'), 2));
        $festivi = $this->cal('25/12')->festivi(2026);
        $natale = array_filter($festivi, fn ($g) => $g->format('m-d') === '12-25');
        $this->assertCount(1, $natale);
        $this->assertCount(12, $festivi);
        $this->assertCount(13, $this->cal('13/06')->festivi(2026));
    }

    public function test_festivi_ordinati(): void
    {
        $date = array_map(fn ($g) => $g->format('Y-m-d'), $this->cal('13/06')->festivi(2026));
        $ordinate = $date;
        sort($ordinate);
        $this->assertSame($ordinate, $date);
        $this->assertContains('2026-04-06', $date);
    }

    public function test_fine_ora_legale_ore_esatte(): void
    {
        // 25/10/2026 domenica, cambio ora: ven 13:00 + 2h → ven 14:00 + lun 09:00
        $cal = $this->cal();
        $this->assertMomento('2026-10-26 09:00', $cal->aggiungiOreLavorative($this->t('2026-10-23 13:00'), 2));
        $this->assertSame(2.0, $cal->oreLavorativeTra($this->t('2026-10-23 13:00'), $this->t('2026-10-26 09:00')));
    }

    public function test_inizio_ora_legale_ore_esatte(): void
    {
        // 29/03/2026 domenica: lun 30/03 aperto 08-14 = 6 ore
        $this->assertSame(6.0, $this->cal()->oreLavorativeTra($this->t('2026-03-27 14:00'), $this->t('2026-03-30 14:00')));
    }

    public function test_orario_tutto_vuoto_ritorna_null(): void
    {
        $vuoto = array_fill_keys(array_keys(self::ORARIO), '');
        $this->assertNull($this->cal(null, $vuoto)->aggiungiOreLavorative($this->t('2026-10-26 10:00'), 1));
        $this->assertNull($this->cal(null, $vuoto)->aggiungiGiorniLavorativi($this->t('2026-10-26 10:00'), 1));
    }

    public function test_ore_lavorative_tra(): void
    {
        $cal = $this->cal();
        $this->assertSame(2.0, $cal->oreLavorativeTra($this->t('2026-10-26 13:00'), $this->t('2026-10-27 09:00')));
        $this->assertSame(0.0, $cal->oreLavorativeTra($this->t('2026-10-27 09:00'), $this->t('2026-10-26 13:00')));
    }

    public function test_valida(): void
    {
        $this->assertTrue(CalendarioUfficio::valida('08:00-14:00'));
        $this->assertFalse(CalendarioUfficio::valida('14:00-08:00'));
        $this->assertFalse(CalendarioUfficio::valida('8-14'));
        $this->assertFalse(CalendarioUfficio::valida('08:00-25:00'));
        $this->assertTrue(CalendarioUfficio::validaPatrono('29/02'));
        $this->assertTrue(CalendarioUfficio::validaPatrono('13/06'));
        $this->assertFalse(CalendarioUfficio::validaPatrono('31/02'));
        $this->assertFalse(CalendarioUfficio::validaPatrono('13-06'));
    }
}
