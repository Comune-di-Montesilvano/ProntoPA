<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\BackupInfo;
use App\Services\Backup\Retention;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    private function info(string $quando, string $tipo = 'notturno', bool $ok = true): BackupInfo
    {
        $t = CarbonImmutable::parse($quando);

        return new BackupInfo(
            nome: 'prontopa-'.$t->format('Ymd-His').'-dev-'.$tipo.'.zip',
            tipo: $tipo,
            creatoIl: $t,
            versione: 'dev',
            dimensione: 100,
            verificato: $ok,
            errore: $ok ? null : 'boom',
            durataSecondi: 1,
        );
    }

    private function nome(string $giorno, string $tipo = 'notturno'): string
    {
        return 'prontopa-'.str_replace('-', '', $giorno).'-020000-dev-'.$tipo.'.zip';
    }

    public function test_quaranta_notturni_conservano_7_giorni_poi_uno_a_settimana(): void
    {
        $ora = CarbonImmutable::parse('2026-10-22 03:00');
        $tutti = collect();
        for ($d = CarbonImmutable::parse('2026-09-13 02:00'); $d <= CarbonImmutable::parse('2026-10-22 02:00'); $d = $d->addDay()) {
            $tutti->push($this->info($d->toDateTimeString()));
        }
        $this->assertCount(40, $tutti);

        // Conservati: 10-16..10-22 (ultimi 7 giorni) + 09-27, 10-04, 10-11, 10-15
        // (l'ultimo di ogni settimana ISO W39..W42 tra 8 e 30 giorni fa).
        // Eliminati: tutto il resto, compreso ciò che precede 09-23 (oltre 30 giorni).
        $attesi = [];
        foreach ([
            '2026-09-13', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20', '2026-09-21', '2026-09-22',
            '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26',
            '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03',
            '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10',
            '2026-10-12', '2026-10-13', '2026-10-14',
        ] as $g) {
            $attesi[] = $this->nome($g);
        }

        $eliminati = (new Retention)->daEliminare($tutti, $ora);
        sort($eliminati);
        sort($attesi);
        $this->assertSame($attesi, $eliminati);
    }

    public function test_unico_backup_vecchio_non_si_elimina(): void
    {
        $ora = CarbonImmutable::parse('2026-10-22 03:00');
        $this->assertSame([], (new Retention)->daEliminare(collect([$this->info('2026-07-24 02:00')]), $ora));
    }

    public function test_ultimo_pre_ripristino_protetto_senza_notturni_verificati_successivi(): void
    {
        $ora = CarbonImmutable::parse('2026-10-22 03:00');
        $pre = $this->info('2026-09-07 10:00', 'pre-ripristino');
        $recente = $this->info('2026-10-20 02:00', 'manuale');
        $fallito = $this->info('2026-10-21 02:00', 'notturno', false);

        $this->assertSame([], (new Retention)->daEliminare(collect([$pre, $recente, $fallito]), $ora));
    }

    public function test_pre_ripristino_eliminato_se_c_e_un_notturno_piu_recente(): void
    {
        $ora = CarbonImmutable::parse('2026-10-22 03:00');
        $pre = $this->info('2026-09-07 10:00', 'pre-ripristino');
        $notturno = $this->info('2026-10-21 02:00');

        $this->assertSame([$pre->nome], (new Retention)->daEliminare(collect([$pre, $notturno]), $ora));
    }

    public function test_falliti_non_sono_l_ultimo_e_invecchiano_come_gli_altri(): void
    {
        $ora = CarbonImmutable::parse('2026-10-22 03:00');
        $vecchioBuono = $this->info('2026-08-01 02:00');
        $fallitoVecchio = $this->info('2026-08-20 02:00', 'notturno', false);
        $fallitoRecente = $this->info('2026-10-21 02:00', 'notturno', false);

        // Il buono resta perché è l'ultimo verificato; il fallito vecchio muore; il recente resta.
        $this->assertSame(
            [$fallitoVecchio->nome],
            (new Retention)->daEliminare(collect([$vecchioBuono, $fallitoVecchio, $fallitoRecente]), $ora),
        );
    }

    public function test_in_settimana_vince_il_verificato_sul_fallito_piu_recente(): void
    {
        $ora = CarbonImmutable::parse('2026-10-22 03:00');
        $buono = $this->info('2026-10-06 02:00');   // martedì W41
        $fallito = $this->info('2026-10-08 02:00', 'notturno', false); // giovedì W41, più recente ma fallito
        $recente = $this->info('2026-10-21 02:00');

        $this->assertSame([$fallito->nome], (new Retention)->daEliminare(collect([$buono, $fallito, $recente]), $ora));
    }
}
