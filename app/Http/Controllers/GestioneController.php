<?php

namespace App\Http\Controllers;

use App\Enums\SegnalazioneStato;
use App\Models\Appalto;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class GestioneController extends Controller
{
    private const TAB_LABELS = [
        'nuove' => 'Nuove', 'aperte' => 'Aperte', 'in_carico' => 'In carico',
        'in_gestione' => 'In gestione', 'evidenza' => 'In evidenza', 'chiuse' => 'Chiuse',
    ];

    private const ORDINI = ['urgenti', 'recenti', 'vecchie'];

    private const FILTRI_RAPIDI = ['scadute', 'entro24h', 'sollecitate', 'ricorrenti'];

    private const STATI_IN_CARICO = [SegnalazioneStato::IN_CARICO, SegnalazioneStato::SOSPESA];

    private const STATI_IN_GESTIONE = [
        SegnalazioneStato::ASSEGNATA_OPERATORE, SegnalazioneStato::ASSEGNATA_IMPRESA, SegnalazioneStato::PREVENTIVO_IN_ATTESA,
    ];

    /**
     * Dashboard gestione con tab + ricerca
     */
    public function index(Request $request): View
    {
        $user = auth()->user();
        $tab  = $request->get('tab', 'aperte');
        $q    = trim($request->get('q', ''));
        $idTipologia      = $request->get('id_tipologia');
        $idOperatore      = $request->get('id_operatore');
        $dataDa           = $request->get('data_da');
        $dataA            = $request->get('data_a');
        $livelloPriorita  = $request->get('livello_priorita');
        $ordine = in_array($request->get('ordine'), self::ORDINI, true) ? $request->get('ordine') : 'urgenti';
        $filtro = in_array($request->get('filtro'), self::FILTRI_RAPIDI, true) ? $request->get('filtro') : null;

        $segnalazioni = $this->elencoFiltrato($request)
            ->with(['tipologia', 'operatore', 'plesso', 'squadraAssegnata', 'appalto.impresa'])
            ->withCount('adesioni')
            ->paginate(30);

        // Conteggi (senza filtro ricerca per non distorcere i badge)
        $baseCount = Segnalazione::visibileA($user);
        $conteggi  = [
            'nuove'       => (clone $baseCount)->where('id_stato_segnalazione', SegnalazioneStato::NUOVA->value)->count(),
            'evidenza'    => (clone $baseCount)->inEvidenza()->count(),
            'in_carico'   => (clone $baseCount)->aperte()->whereIn('id_stato_segnalazione', self::valori(self::STATI_IN_CARICO))->count(),
            'in_gestione' => (clone $baseCount)->aperte()->whereIn('id_stato_segnalazione', self::valori(self::STATI_IN_GESTIONE))->count(),
            'aperte'      => (clone $baseCount)->aperte()->count(),
            'chiuse'      => (clone $baseCount)->whereNotNull('data_chiusura')->count(),
            // "Da guardare": stessi criteri dei filtri rapidi
            'scadute'     => self::filtroRapido(clone $baseCount, 'scadute')->count(),
            'entro24h'    => self::filtroRapido(clone $baseCount, 'entro24h')->count(),
        ];

        $tipologie        = TipologiaSegnalazione::orderBy('descrizione')->get();
        $operatori        = User::where(function ($q) {
                                $q->where('gestore_segnalazioni', true)
                                  ->orWhere('amministratore', true);
                            })->orderBy('name')->get();

        $workflow = app(\App\Services\SegnalazioneWorkflowService::class);
        $azioniRapidePerSegnalazione = $segnalazioni->getCollection()
            ->mapWithKeys(fn ($s) => [
                $s->id_segnalazione => $workflow->getAzioniRapide($s, $user),
            ]);

        $filtri = $this->parametriFiltro($request);

        // Pannelli delle azioni multiple: stessi elenchi del pannello Assegna della scheda.
        $operatoriAssegnabili = User::assegnabili()->orderBy('name')->get();
        $squadre = Impostazione::get('squadre_enabled', false)
            ? Squadra::where('attiva', true)->orderBy('nome')->get()
            : collect();
        $appaltiValidi = Appalto::where('valido', true)->with(['impresa', 'tipologie'])->orderBy('descrizione')->get();

        return view('gestione.dashboard', compact(
            'segnalazioni', 'tab', 'conteggi',
            'q', 'idTipologia',
            'idOperatore', 'dataDa', 'dataA', 'livelloPriorita',
            'ordine', 'filtro', 'tipologie', 'operatori',
            'azioniRapidePerSegnalazione', 'filtri', 'operatoriAssegnabili', 'squadre', 'appaltiValidi'
        ));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $segnalazioni = $this->elencoFiltrato($request)
            ->with(['tipologia', 'plesso', 'operatore', 'appalto.impresa'])
            ->get();

        $filename = 'segnalazioni-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($segnalazioni) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF"); // BOM UTF-8 per Excel
            fputcsv($handle, [
                'ID', 'Data', 'Tipologia', 'Plesso', 'Stato', 'Operatore',
                'Priorità',
                'Importo preventivo', 'Importo liquidato', 'Data chiusura', 'Testo',
            ], ';');
            foreach ($segnalazioni as $s) {
                fputcsv($handle, [
                    $s->id_segnalazione,
                    $s->data_segnalazione?->format('d/m/Y'),
                    $s->tipologia?->descrizione,
                    $s->plesso?->nome,
                    $s->statoEnum()->label(),
                    $s->operatore?->name,
                    $s->label_priorita,
                    $s->importo_preventivo ? number_format((float) $s->importo_preventivo, 2, ',', '') : '',
                    $s->importo_liquidato  ? number_format((float) $s->importo_liquidato,  2, ',', '') : '',
                    $s->data_chiusura?->format('d/m/Y'),
                    mb_substr((string) $s->testo_segnalazione, 0, 200),
                ], ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function stampaLista(Request $request): View
    {
        $segnalazioni = $this->elencoFiltrato($request)
            ->with(['tipologia', 'operatore', 'plesso.istituto', 'utente'])
            ->get();

        return view('gestione.print', [
            'segnalazioni' => $segnalazioni,
            'tab' => $request->get('tab', 'aperte'),
            'tabLabels' => self::TAB_LABELS,
            'q' => trim($request->get('q', '')),
        ]);
    }

    /** Tutte le schede dell'elenco filtrato, una per pagina, in un unico documento. */
    public function stampaSchede(Request $request): View
    {
        // Selezione dall'elenco (azioni multiple): solo quelle, raggruppate per chi lavora e sede.
        $ids = array_slice(array_filter(array_map('intval', (array) $request->query('ids', []))), 0, 100);
        $query = $ids
            ? Segnalazione::visibileA($request->user())->whereIn('id_segnalazione', $ids)
            : $this->elencoFiltrato($request);

        // Stesse relazioni di SegnalazioneController::stampa (scheda singola).
        $segnalazioni = $query
            ->with([
                'stato', 'tipologia', 'plesso.istituto',
                'operatore', 'utente', 'appalto.impresa', 'squadraAssegnata',
                'note' => fn ($q) => $q->visibiliA($request->user()),
                'storicoStati.stato', 'storicoStati.utente',
            ])
            ->get();

        if ($ids) {
            // Un mazzo di fogli per chi va sul posto: per nome di chi lavora, poi per sede.
            $segnalazioni = $segnalazioni->sortBy([
                fn ($a, $b) => self::chiLavora($a) <=> self::chiLavora($b),
                fn ($a, $b) => strcasecmp((string) $a->plesso?->nome, (string) $b->plesso?->nome),
                fn ($a, $b) => $a->id_segnalazione <=> $b->id_segnalazione,
            ])->values();
        }

        return view('segnalazioni.print-multiple', [
            'segnalazioni' => $segnalazioni,
            'tab' => $request->get('tab', 'aperte'),
            'tabLabels' => self::TAB_LABELS,
        ]);
    }

    /**
     * Chiave di ordinamento: non assegnate in fondo, poi ditta, squadra o persona per nome.
     *
     * @return array{0: int, 1: string}
     */
    private static function chiLavora(Segnalazione $s): array
    {
        $nome = $s->appalto->impresa->ragione_sociale
            ?? $s->squadraAssegnata->nome
            ?? $s->operatore->name
            ?? null;

        return $nome === null ? [1, ''] : [0, mb_strtolower((string) $nome)];
    }

    /**
     * Query dell'elenco: visibilità, filtri e tab, già ordinata. Unica per dashboard, CSV e stampe.
     *
     * @return Builder<Segnalazione>
     */
    private function elencoFiltrato(Request $request): Builder
    {
        $q = trim($request->get('q', ''));
        $base = Segnalazione::visibileA($request->user());

        if ($q !== '') {
            $base->where(function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                        // FULLTEXT per la ricerca in produzione (dati committed);
                        // LIKE come fallback corretto (RefreshDatabase usa transazioni,
                        // InnoDB FULLTEXT non vede righe uncommitted).
                        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
                            $qq->where('testo_segnalazione', 'like', "%{$q}%");
                        } else {
                            $qq->whereFullText('testo_segnalazione', $q)
                               ->orWhere('testo_segnalazione', 'like', "%{$q}%");
                        }
                    })
                      ->orWhere('titolo', 'like', "%{$q}%")
                      ->orWhereHas('utente', fn ($u) => $u->where('name', 'like', "%{$q}%"))
                      ->orWhere('id_segnalazione', $q);
            });
        }

        $colonne = [
            'id_tipologia' => 'id_tipologia_segnalazione',
            'id_operatore' => 'id_operatore_assegnato',
            'livello_priorita' => 'livello_priorita',
        ];
        foreach ($colonne as $parametro => $colonna) {
            if ($request->filled($parametro)) {
                $base->where($colonna, $request->get($parametro));
            }
        }
        if ($request->filled('data_da')) {
            $base->whereDate('data_segnalazione', '>=', $request->get('data_da'));
        }
        if ($request->filled('data_a')) {
            $base->whereDate('data_segnalazione', '<=', $request->get('data_a'));
        }

        if (in_array($request->get('filtro'), self::FILTRI_RAPIDI, true)) {
            self::filtroRapido($base, $request->get('filtro'));
        }

        $tab = $request->get('tab', 'aperte');
        match ($tab) {
            'nuove'       => $base->where('id_stato_segnalazione', SegnalazioneStato::NUOVA->value),
            'evidenza'    => $base->inEvidenza(),
            'in_carico'   => $base->aperte()->whereIn('id_stato_segnalazione', self::valori(self::STATI_IN_CARICO)),
            'in_gestione' => $base->aperte()->whereIn('id_stato_segnalazione', self::valori(self::STATI_IN_GESTIONE)),
            'chiuse'      => $base->whereNotNull('data_chiusura'),
            default       => $base->aperte(),
        };

        if ($tab === 'chiuse') {
            return $base->orderByDesc('data_chiusura');
        }

        return match ($request->get('ordine')) {
            'recenti' => $base->orderByDesc('data_segnalazione'),
            'vecchie' => $base->orderBy('data_segnalazione'),
            default   => $base->ordinaUrgenti(),
        };
    }

    /**
     * @param  Builder<Segnalazione>  $query
     * @return Builder<Segnalazione>
     */
    private static function filtroRapido(Builder $query, string $filtro): Builder
    {
        return match ($filtro) {
            'scadute'     => $query->aperte()->where('data_scadenza_sla', '<', now()),
            'entro24h'    => $query->aperte()->whereBetween('data_scadenza_sla', [now(), now()->addDay()]),
            'sollecitate' => $query->aperte()->has('adesioni'),
            'ricorrenti'  => $query->whereNotNull('id_segnalazione_correlata'),
            default       => $query,
        };
    }

    /** @return array<string, string> filtri attivi, per i link di stampa/export */
    private function parametriFiltro(Request $request): array
    {
        return array_filter($request->only([
            'tab', 'q', 'id_tipologia', 'id_operatore',
            'data_da', 'data_a', 'livello_priorita', 'ordine', 'filtro',
        ]), fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  list<SegnalazioneStato>  $stati
     * @return list<int>
     */
    private static function valori(array $stati): array
    {
        return array_map(fn (SegnalazioneStato $s) => $s->value, $stati);
    }
}
