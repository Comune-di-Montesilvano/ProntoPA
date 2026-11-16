<?php

namespace App\Support\Segnalazioni;

use App\Enums\SegnalazioneStato;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Services\Sla\CalendarioUfficio;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Com'è andata finora": la vita della segnalazione su una barra in tempo reale, già pronta
 * per la vista (posizioni in percentuale, fumetti in parole). Nessun HTML qui.
 *
 * Scala reale, non in ore lavorative: i giorni chiusi restano visibili, velati.
 *
 * @phpstan-type Tratto array{stato: ?SegnalazioneStato, nome: string, classe: string, chi: ?string, deciso_da: ?string, da: Carbon, a: Carbon, sinistra: float, larghezza: float, durata: string, lavorativi: int, fumetto: string}
 * @phpstan-type Marcatore array{tipo: string, posizione: float, quanti: int, fumetto: string}
 */
class Cronogramma
{
    /** Tratti "Sospesa" più corti di così sono una pausa (marcatore), non un tratto. */
    private const PAUSA_BREVE_SECONDI = 3600;

    /** Marcatori più vicini di così (punti percentuali) diventano uno con contatore. */
    private const RAGGRUPPA_ENTRO = 1.5;

    /** Etichetta di una fascia chiusa solo se è larga almeno così. */
    private const LARGHEZZA_ETICHETTA = 8.0;

    private const LEGENDA = [
        'nuova' => "Nuova, nessuno l'ha presa",
        'in-carico' => 'In carico',
        'assegnata' => 'Assegnata',
        'preventivo' => 'Preventivo in attesa',
        'sospesa' => 'In pausa',
        'attesa' => 'In attesa di risposta',
        'completata' => 'Completata',
        'finale' => 'Chiusa',
        'non-noto' => 'Storia non registrata',
        'risposta' => 'Risposta a chi ha segnalato',
        'risegnalata' => 'Segnalata di nuovo',
        'pausa' => 'Pausa breve',
        'chiuso' => 'Ufficio chiuso',
    ];

    private Carbon $da;

    private Carbon $a;

    private Carbon $fineReale;

    private float $durata;

    /** @var array<int, string> */
    private array $nomi = [];

    private function __construct(
        private readonly Segnalazione $s,
        private readonly User $lettore,
        private readonly Carbon $ora,
        private readonly CalendarioUfficio $calendario,
    ) {}

    /** @return array<string, mixed>|null */
    public static function per(Segnalazione $s, User $lettore, ?CarbonInterface $ora = null): ?array
    {
        if ($s->data_segnalazione === null) {
            return null;
        }

        return (new self($s, $lettore, Carbon::instance($ora ?? now()), CalendarioUfficio::daImpostazioni()))->calcola();
    }

    /** @return array<string, mixed> */
    private function calcola(): array
    {
        $this->da = Carbon::parse($this->s->data_segnalazione);
        $chiusura = $this->s->data_chiusura ? Carbon::parse($this->s->data_chiusura) : null;
        $this->fineReale = $chiusura ?? $this->ora->copy();
        if ($this->fineReale->lt($this->da)) {
            $this->fineReale = $this->da->copy();
        }
        $scadenza = $this->s->data_scadenza_sla ? Carbon::parse($this->s->data_scadenza_sla) : null;
        $this->a = $scadenza && $scadenza->gt($this->fineReale) ? $scadenza->copy() : $this->fineReale->copy();
        $this->durata = (float) max(1, $this->a->getTimestamp() - $this->da->getTimestamp());

        $storico = StoricoOrdinato::di($this->s);
        [$tratti, $pause] = $this->tratti($storico);
        $marcatori = $this->raggruppa(array_merge($pause, $this->marcatori()));
        $presenti = array_unique(array_merge(array_column($tratti, 'classe'), array_column($marcatori, 'tipo')));
        $chiusi = $this->chiusi();
        if ($chiusi !== []) {
            $presenti[] = 'chiuso';
        }

        $superata = $scadenza !== null && $scadenza->lt($this->fineReale);

        return [
            'da' => $this->da,
            'a' => $this->a,
            'tratti' => $tratti,
            'marcatori' => $marcatori,
            'chiusi' => $chiusi,
            'tacche' => $this->tacche(),
            'ora' => $chiusura === null ? $this->posizione($this->ora) : null,
            'scadenza' => $scadenza ? $this->posizione($scadenza) : null,
            'superata_da' => $superata ? $this->posizione($scadenza) : null,
            'fine_reale' => $this->posizione($this->fineReale),
            'riassunto' => $this->riassunto($tratti, $chiusura),
            'legenda' => array_values(array_intersect_key(self::LEGENDA, array_flip($presenti))),
        ];
    }

    private function posizione(CarbonInterface $t): float
    {
        $p = ($t->getTimestamp() - $this->da->getTimestamp()) / $this->durata * 100;

        return max(0.0, min(100.0, $p));
    }

    // ── Tratti ───────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, StoricoStatoSegnalazione>  $storico
     * @return array{0: list<Tratto>, 1: list<Marcatore>}
     */
    private function tratti(Collection $storico): array
    {
        if ($storico->isEmpty()) {
            $stato = $this->s->statoEnum();
            $noto = $stato === SegnalazioneStato::NUOVA;

            return [[$this->tratto($noto ? $stato : null, null, null, $this->da, $this->fineReale)], []];
        }

        // Permanenze: [stato, assegnatario, chi ha deciso, da]
        $permanenze = [[SegnalazioneStato::NUOVA, null, (int) $this->s->id_utente_segnalazione, $this->da->copy()]];
        $assegnatario = null;
        foreach ($storico as $evento) {
            $quando = Carbon::parse($evento->data_registrazione);
            $quando = $quando->lt($this->da) ? $this->da->copy() : $quando;
            $collegato = (int) $evento->id_utente_collegato;
            $appalto = (int) $evento->id_appalto;
            if ($collegato > 0 || $appalto > 0) {
                $assegnatario = $appalto > 0 ? $this->ditta($appalto) : $this->nome($collegato);
            }
            $permanenze[] = [SegnalazioneStato::tryFrom((int) $evento->id_stato_segnalazione), $assegnatario, (int) $evento->id_utente, $quando];
        }

        // Ultima permanenza fino alla fine reale; quelle a durata zero spariscono.
        $grezzi = [];
        foreach ($permanenze as $i => [$stato, $chi, $deciso, $inizio]) {
            $fine = isset($permanenze[$i + 1]) ? $permanenze[$i + 1][3] : $this->fineReale;
            if ($fine->gt($inizio)) {
                $grezzi[] = compact('stato', 'chi', 'deciso', 'inizio', 'fine');
            }
        }
        if ($grezzi === []) {
            $ultima = end($permanenze);

            return [[$this->tratto($ultima[0], $ultima[1], $ultima[2], $this->da, $this->fineReale)], []];
        }

        // Pause brevi: marcatore, e il tratto prima le assorbe.
        $pause = [];
        $tenuti = [];
        foreach ($grezzi as $g) {
            $breve = $g['stato'] === SegnalazioneStato::SOSPESA
                && $g['fine']->getTimestamp() - $g['inizio']->getTimestamp() < self::PAUSA_BREVE_SECONDI;
            if ($breve && $tenuti !== []) {
                $tenuti[count($tenuti) - 1]['fine'] = $g['fine'];
                $tenuti[count($tenuti) - 1]['pause'][] = $g;
                $pause[] = [
                    'tipo' => 'pausa', 'posizione' => $this->posizione($g['inizio']), 'quanti' => 1,
                    'fumetto' => 'Pausa di '.$this->durataBreve($g['inizio'], $g['fine']).' · '.$this->momento($g['inizio']),
                ];

                continue;
            }
            $ultimo = $tenuti === [] ? null : $tenuti[count($tenuti) - 1];
            if ($ultimo !== null && $ultimo['stato'] === $g['stato'] && $ultimo['chi'] === $g['chi']) {
                $tenuti[count($tenuti) - 1]['fine'] = $g['fine'];

                continue;
            }
            $tenuti[] = $g + ['pause' => []];
        }

        $tratti = array_map(fn ($t) => $this->tratto($t['stato'], $t['chi'], $t['deciso'], $t['inizio'], $t['fine'], $t['pause']), $tenuti);

        return [$tratti, $pause];
    }

    /**
     * @param  list<array<string, mixed>>  $pause
     * @return Tratto
     */
    private function tratto(?SegnalazioneStato $stato, ?string $chi, ?int $deciso, Carbon $da, Carbon $a, array $pause = []): array
    {
        $nome = $stato?->etichetta() ?? 'Storia non registrata';
        $decisoDa = $deciso ? $this->nome($deciso) : null;
        $inCorso = $a->getTimestamp() >= $this->fineReale->getTimestamp() && $this->s->data_chiusura === null;
        $durata = $this->durata($da, $a);
        $lavorativi = $this->lavorativi($da, $a);

        $righe = match (true) {
            $stato === null => ['Storia non registrata', 'dal '.$this->momento($da).' al '.$this->momento($a)],
            $stato === SegnalazioneStato::NUOVA => array_filter([
                "Nuova · nessuno l'ha presa",
                'dal '.$this->momento($da).($inCorso ? '' : ' al '.$this->momento($a)),
                $this->avvisati(),
            ]),
            default => [
                $nome.($chi ? ' a '.$chi : ''),
                $decisoDa !== null && $decisoDa === $chi
                    ? 'presa in carico '.$this->momento($da)
                    : ($decisoDa ? 'affidata da '.$decisoDa.' '.$this->momento($da) : $this->momento($da)),
            ],
        };
        $righe[] = ($inCorso ? 'da ' : 'per ').$durata.' ('.$lavorativi.' '.($lavorativi === 1 ? 'lavorativo' : 'lavorativi').')';
        foreach ($pause as $p) {
            $righe[] = 'pausa di '.$this->durataBreve($p['inizio'], $p['fine']).' '.$this->momento($p['inizio']);
        }

        return [
            'stato' => $stato,
            'nome' => $nome,
            'classe' => self::classe($stato),
            'chi' => $chi,
            'deciso_da' => $decisoDa,
            'da' => $da,
            'a' => $a,
            'sinistra' => $this->posizione($da),
            // Segnalazione appena aperta (intervallo nullo): un tratto pieno, non una barra vuota.
            'larghezza' => $this->da->eq($this->a) ? 100.0 : $this->posizione($a) - $this->posizione($da),
            'durata' => $durata,
            'lavorativi' => $lavorativi,
            'fumetto' => implode(' · ', $righe),
        ];
    }

    public static function classe(?SegnalazioneStato $stato): string
    {
        return match ($stato) {
            null => 'non-noto',
            SegnalazioneStato::NUOVA => 'nuova',
            SegnalazioneStato::IN_CARICO => 'in-carico',
            SegnalazioneStato::ASSEGNATA_OPERATORE, SegnalazioneStato::ASSEGNATA_IMPRESA => 'assegnata',
            SegnalazioneStato::PREVENTIVO_IN_ATTESA => 'preventivo',
            SegnalazioneStato::SOSPESA => 'sospesa',
            SegnalazioneStato::COMPLETATA => 'completata',
            default => 'finale',
        };
    }

    private function avvisati(): ?string
    {
        $n = \App\Models\NotificaSegnalazione::where('id_segnalazione', $this->s->id_segnalazione)
            ->where('tipo', 'NuovaSegnalazioneNotification')->distinct()->count('id_utente');

        return $n > 0 ? 'avvisati: '.$n.' '.($n === 1 ? 'gestore' : 'gestori').' della tipologia' : null;
    }

    // ── Marcatori ────────────────────────────────────────────────────────────

    /** @return list<Marcatore> */
    private function marcatori(): array
    {
        $marcatori = [];
        $autore = (int) $this->s->id_utente_segnalazione;

        $note = $this->s->note()->visibiliA($this->lettore)->reorder('data')->get();
        foreach ($note as $nota) {
            $tipo = $this->tipoMarcatore($nota, $autore);
            if ($tipo === null) {
                continue;
            }
            $marcatori[] = [
                'tipo' => $tipo[0],
                'posizione' => $this->posizione(Carbon::parse($nota->data)),
                'quanti' => 1,
                'fumetto' => $this->nome((int) $nota->id_utente).' · '.$this->momento(Carbon::parse($nota->data)).' · '.$tipo[1],
            ];
        }

        foreach ($this->s->duplicati()->get() as $accorpata) {
            $quando = Carbon::parse($accorpata->data_segnalazione);
            $marcatori[] = [
                'tipo' => 'risegnalata',
                'posizione' => $this->posizione($quando),
                'quanti' => 1,
                'fumetto' => $this->nome((int) $accorpata->id_utente_segnalazione).' · '.$this->momento($quando)
                    .' · segnalata di nuovo (#'.$accorpata->id_segnalazione.')',
            ];
        }

        return $marcatori;
    }

    /** @return array{0: string, 1: string}|null tipo e descrizione */
    private function tipoMarcatore(NotaSegnalazione $nota, int $autore): ?array
    {
        return match ($nota->origine) {
            NotaSegnalazione::ORIGINE_SOLLECITO => ['risegnalata', 'ha sollecitato'],
            NotaSegnalazione::ORIGINE_INTEGRAZIONE => ['risegnalata', 'ha aggiunto informazioni'],
            null => $nota->visibile_web && (int) $nota->id_utente > 0 && (int) $nota->id_utente !== $autore
                && ! $this->scrittaDaSegnalante($nota) ? ['risposta', 'risposta a chi ha segnalato'] : null,
            default => null,
        };
    }

    private function scrittaDaSegnalante(NotaSegnalazione $nota): bool
    {
        $utente = User::find((int) $nota->id_utente);

        return $utente !== null && LetturaScheda::per($utente) === 'segnalante';
    }

    /**
     * @param  list<Marcatore>  $marcatori
     * @return list<Marcatore>
     */
    private function raggruppa(array $marcatori): array
    {
        usort($marcatori, fn ($x, $y) => $x['posizione'] <=> $y['posizione']);
        $gruppi = [];
        foreach ($marcatori as $m) {
            $i = count($gruppi) - 1;
            if ($i >= 0 && $gruppi[$i]['tipo'] === $m['tipo'] && $m['posizione'] - $gruppi[$i]['posizione'] < self::RAGGRUPPA_ENTRO) {
                $gruppi[$i]['quanti']++;
                $gruppi[$i]['fumetto'] .= "\n".$m['fumetto'];

                continue;
            }
            $gruppi[] = $m;
        }

        return $gruppi;
    }

    // ── Giorni chiusi e tacche ───────────────────────────────────────────────

    /** @return list<array{sinistra: float, larghezza: float, etichetta: ?string}> */
    private function chiusi(): array
    {
        $fasce = [];
        $corrente = null;
        for ($g = $this->da->copy()->startOfDay(); $g->lt($this->a); $g = $g->copy()->addDay()) {
            if ($this->calendario->fascia($g) !== null) {
                $corrente = null;

                continue;
            }
            $inizio = $g->lt($this->da) ? $this->da->copy() : $g->copy();
            $fine = $g->copy()->addDay()->gt($this->a) ? $this->a->copy() : $g->copy()->addDay();
            if ($corrente !== null) {
                $fasce[$corrente]['fine'] = $fine;
                $fasce[$corrente]['giorni'][] = $g->copy();
            } else {
                $fasce[] = ['inizio' => $inizio, 'fine' => $fine, 'giorni' => [$g->copy()]];
                $corrente = count($fasce) - 1;
            }
        }

        return array_map(function ($f) {
            $sinistra = $this->posizione($f['inizio']);
            $larghezza = $this->posizione($f['fine']) - $sinistra;

            return [
                'sinistra' => $sinistra,
                'larghezza' => $larghezza,
                'etichetta' => $larghezza >= self::LARGHEZZA_ETICHETTA ? $this->etichettaChiuso($f['giorni']) : null,
            ];
        }, $fasce);
    }

    /** @param  list<Carbon>  $giorni */
    private function etichettaChiuso(array $giorni): string
    {
        foreach ($giorni as $g) {
            if ($this->calendario->isFestivo($g)) {
                return 'FESTIVO';
            }
        }
        $nomi = array_unique(array_map(fn (Carbon $g) => mb_strtoupper($g->copy()->locale('it')->dayName), $giorni));
        if (count($nomi) <= 2 && array_diff($nomi, ['SABATO', 'DOMENICA']) === []) {
            return implode(' E ', $nomi);
        }

        return 'UFFICIO CHIUSO';
    }

    /** @return list<array{posizione: float, testo: string}> */
    private function tacche(): array
    {
        $giorni = $this->da->copy()->startOfDay()->diffInDays($this->a->copy()->startOfDay());
        $tacche = [];

        if ($giorni <= 14) {
            for ($g = $this->da->copy()->startOfDay(); $g->lte($this->a); $g = $g->copy()->addDay()) {
                $tacche[] = ['posizione' => $this->posizione($g), 'testo' => $g->copy()->locale('it')->isoFormat('ddd D')];
            }

            return $tacche;
        }

        $settimanali = $giorni <= 90;
        $g = $settimanali
            ? $this->da->copy()->startOfDay()->next(CarbonInterface::MONDAY)
            : $this->da->copy()->startOfMonth()->addMonth();
        for (; $g->lte($this->a); $g = $settimanali ? $g->copy()->addWeek() : $g->copy()->addMonth()) {
            $tacche[] = [
                'posizione' => $this->posizione($g),
                'testo' => $g->copy()->locale('it')->isoFormat($settimanali ? 'ddd D/M' : 'MMM'),
            ];
        }

        return $tacche;
    }

    // ── Riassunto ────────────────────────────────────────────────────────────

    /** @param  list<Tratto>  $tratti */
    private function riassunto(array $tratti, ?Carbon $chiusura): string
    {
        if ($chiusura !== null) {
            return 'Chiusa in '.$this->giorni($this->da, $chiusura);
        }

        $parti = [];
        $nuova = $tratti[0]['stato'] === SegnalazioneStato::NUOVA ? $tratti[0] : null;
        if ($nuova !== null && Giorni::giorniDiCalendario($nuova['da'], $nuova['a']) >= 1) {
            $parti[] = $this->giorni($nuova['da'], $nuova['a'])." senza che nessuno la aprisse";
        }
        $attuale = $tratti[count($tratti) - 1];
        if ($attuale['stato'] !== null && $attuale['stato'] !== SegnalazioneStato::NUOVA) {
            $chiusi = $this->giorniChiusi($attuale['da'], $attuale['a']);
            $parti[] = $this->giorni($attuale['da'], $attuale['a']).' '.mb_strtolower($attuale['nome'])
                .($chiusi > 0 ? ' (di cui '.$chiusi.' di fine settimana)' : '');
        }

        return implode(' · ', $parti);
    }

    // ── Parole ───────────────────────────────────────────────────────────────

    private function giorni(CarbonInterface $da, CarbonInterface $a): string
    {
        $n = Giorni::giorniDiCalendario($da, $a);

        return match (true) {
            $n <= 0 => 'meno di un giorno',
            $n === 1 => '1 giorno',
            default => $n.' giorni',
        };
    }

    private function durata(CarbonInterface $da, CarbonInterface $a): string
    {
        return Giorni::giorniDiCalendario($da, $a) >= 1 ? $this->giorni($da, $a) : $this->durataBreve($da, $a);
    }

    private function durataBreve(CarbonInterface $da, CarbonInterface $a): string
    {
        $secondi = max(0, $a->getTimestamp() - $da->getTimestamp());
        if ($secondi < 3600) {
            $m = max(1, intdiv($secondi, 60));

            return $m.' '.($m === 1 ? 'minuto' : 'minuti');
        }
        $h = intdiv($secondi, 3600);

        return $h.' '.($h === 1 ? 'ora' : 'ore');
    }

    /** Giorni lavorativi dopo quello d'inizio, fino a quello di fine compreso. */
    private function lavorativi(CarbonInterface $da, CarbonInterface $a): int
    {
        $n = 0;
        for ($g = Carbon::instance($da)->startOfDay()->addDay(); $g->lte($a); $g = $g->copy()->addDay()) {
            if ($this->calendario->fascia($g) !== null) {
                $n++;
            }
        }

        return $n;
    }

    private function giorniChiusi(CarbonInterface $da, CarbonInterface $a): int
    {
        $n = 0;
        for ($g = Carbon::instance($da)->startOfDay()->addDay(); $g->lte($a); $g = $g->copy()->addDay()) {
            if ($this->calendario->fascia($g) === null) {
                $n++;
            }
        }

        return $n;
    }

    /** "ven 13, 08:16" */
    private function momento(CarbonInterface $t): string
    {
        return Carbon::instance($t)->locale('it')->isoFormat('ddd D, HH:mm');
    }

    private function nome(int $id): string
    {
        if ($id <= 0) {
            return 'Sistema';
        }

        return $this->nomi[$id] ??= (User::whereKey($id)->value('name') ?: 'Utente #'.$id);
    }

    private function ditta(int $idAppalto): string
    {
        $idImpresa = Appalto::whereKey($idAppalto)->value('id_impresa');

        return ($idImpresa ? Impresa::whereKey($idImpresa)->value('ragione_sociale') : null) ?: 'una ditta';
    }
}
