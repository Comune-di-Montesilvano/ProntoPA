<?php

namespace App\Http\Controllers;

use App\Models\Provenienza;
use App\Models\Segnalazione;
use App\Models\Specializzazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class GestioneController extends Controller
{
    /**
     * Dashboard gestione con tab + ricerca
     */
    public function index(Request $request): View
    {
        $user = auth()->user();
        $tab  = $request->get('tab', 'aperte');
        $q    = trim($request->get('q', ''));
        $idTipologia      = $request->get('id_tipologia');
        $idProvenienza    = $request->get('id_provenienza');
        $idOperatore      = $request->get('id_operatore');
        $dataDa           = $request->get('data_da');
        $dataA            = $request->get('data_a');
        $livelloPriorita  = $request->get('livello_priorita');
        $idSpecializzazione = $request->get('id_specializzazione');

        $base = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia', 'operatore', 'provenienza']);

        // Applica filtri ricerca
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
                      ->orWhere('segnalante', 'like', "%{$q}%")
                      ->orWhere('id_segnalazione', $q);
            });
        }
        if ($idTipologia) {
            $base->where('id_tipologia_segnalazione', $idTipologia);
        }
        if ($idProvenienza) {
            $base->where('id_provenienza', $idProvenienza);
        }
        if ($idOperatore) {
            $base->where('id_operatore_assegnato', $idOperatore);
        }
        if ($dataDa) {
            $base->whereDate('data_segnalazione', '>=', $dataDa);
        }
        if ($dataA) {
            $base->whereDate('data_segnalazione', '<=', $dataA);
        }
        if ($livelloPriorita) {
            $base->where('livello_priorita', $livelloPriorita);
        }
        if ($idSpecializzazione) {
            $base->where('id_specializzazione', $idSpecializzazione);
        }

        $segnalazioni = match($tab) {
            'evidenza'    => (clone $base)->inEvidenza()
                                ->orderByDesc('data_segnalazione')->paginate(30),
            'in_carico'   => (clone $base)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('in_carico', true))
                                ->orderByDesc('data_segnalazione')->paginate(30),
            'in_gestione' => (clone $base)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('id_gestione', true))
                                ->orderByDesc('data_segnalazione')->paginate(30),
            'chiuse'      => (clone $base)->whereNotNull('data_chiusura')
                                ->orderByDesc('data_chiusura')->paginate(30),
            default       => (clone $base)->aperte()
                                ->orderByDesc('data_segnalazione')->paginate(30),
        };

        // Conteggi (senza filtro ricerca per non distorcere i badge)
        $baseCount = Segnalazione::visibileA($user);
        $conteggi  = [
            'evidenza'    => (clone $baseCount)->inEvidenza()->count(),
            'in_carico'   => (clone $baseCount)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('in_carico', true))->count(),
            'in_gestione' => (clone $baseCount)->aperte()
                                ->whereHas('stato', fn ($q) => $q->where('id_gestione', true))->count(),
            'aperte'      => (clone $baseCount)->aperte()->count(),
            'chiuse'      => (clone $baseCount)->whereNotNull('data_chiusura')->count(),
            'sla_rischio' => (clone $baseCount)->aperte()->slaArischio()->count(),
            'sla_violato' => (clone $baseCount)->aperte()->slaViolato()->count(),
        ];

        $tipologie        = TipologiaSegnalazione::orderBy('descrizione')->get();
        $provenienze      = Provenienza::orderBy('descrizione')->get();
        $specializzazioni = Specializzazione::orderBy('descrizione')->get();
        $operatori        = User::where(function ($q) {
                                $q->where('gestore_segnalazioni', true)
                                  ->orWhere('amministratore', true);
                            })->orderBy('name')->get();

        $workflow = app(\App\Services\SegnalazioneWorkflowService::class);
        $azioniRapidePerSegnalazione = $segnalazioni->getCollection()
            ->mapWithKeys(fn ($s) => [
                $s->id_segnalazione => $workflow->getAzioniRapide($s, $user),
            ]);

        return view('gestione.dashboard', compact(
            'segnalazioni', 'tab', 'conteggi',
            'q', 'idTipologia', 'idProvenienza',
            'idOperatore', 'dataDa', 'dataA', 'livelloPriorita', 'idSpecializzazione',
            'tipologie', 'provenienze', 'specializzazioni', 'operatori',
            'azioniRapidePerSegnalazione'
        ));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $user = auth()->user();
        $tab             = $request->get('tab', 'aperte');
        $q               = trim($request->get('q', ''));
        $idTipologia     = $request->get('id_tipologia');
        $idProvenienza   = $request->get('id_provenienza');
        $idOperatore     = $request->get('id_operatore');
        $dataDa          = $request->get('data_da');
        $dataA           = $request->get('data_a');
        $livelloPriorita = $request->get('livello_priorita');
        $idSpec          = $request->get('id_specializzazione');

        $base = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia', 'plesso', 'operatore', 'appalto.impresa', 'specializzazione']);

        if ($q !== '') {
            $base->where(function ($query) use ($q) {
                $query->where('testo_segnalazione', 'like', "%{$q}%")
                      ->orWhere('segnalante', 'like', "%{$q}%")
                      ->orWhere('id_segnalazione', $q);
            });
        }
        if ($idTipologia)     { $base->where('id_tipologia_segnalazione', $idTipologia); }
        if ($idProvenienza)   { $base->where('id_provenienza', $idProvenienza); }
        if ($idOperatore)     { $base->where('id_operatore_assegnato', $idOperatore); }
        if ($dataDa)          { $base->whereDate('data_segnalazione', '>=', $dataDa); }
        if ($dataA)           { $base->whereDate('data_segnalazione', '<=', $dataA); }
        if ($livelloPriorita) { $base->where('livello_priorita', $livelloPriorita); }
        if ($idSpec)          { $base->where('id_specializzazione', $idSpec); }

        $segnalazioni = match($tab) {
            'evidenza'    => (clone $base)->inEvidenza()->orderByDesc('data_segnalazione')->get(),
            'in_carico'   => (clone $base)->aperte()->whereHas('stato', fn ($q) => $q->where('in_carico', true))->orderByDesc('data_segnalazione')->get(),
            'in_gestione' => (clone $base)->aperte()->whereHas('stato', fn ($q) => $q->where('id_gestione', true))->orderByDesc('data_segnalazione')->get(),
            'chiuse'      => (clone $base)->whereNotNull('data_chiusura')->orderByDesc('data_chiusura')->get(),
            default       => (clone $base)->aperte()->orderByDesc('data_segnalazione')->get(),
        };

        $filename = 'segnalazioni-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($segnalazioni) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF"); // BOM UTF-8 per Excel
            fputcsv($handle, [
                'ID', 'Data', 'Tipologia', 'Plesso', 'Stato', 'Operatore',
                'Priorità', 'Specializzazione', 'Urgente',
                'Importo preventivo', 'Importo liquidato', 'Data chiusura', 'Testo',
            ], ';');
            foreach ($segnalazioni as $s) {
                fputcsv($handle, [
                    $s->id_segnalazione,
                    $s->data_segnalazione?->format('d/m/Y'),
                    $s->tipologia?->descrizione,
                    $s->plesso?->nome,
                    $s->stato?->descrizione,
                    $s->operatore?->name,
                    $s->label_priorita,
                    $s->specializzazione?->descrizione,
                    $s->segnalazione_urgente ? 'Sì' : 'No',
                    $s->importo_preventivo ? number_format((float) $s->importo_preventivo, 2, ',', '') : '',
                    $s->importo_liquidato  ? number_format((float) $s->importo_liquidato,  2, ',', '') : '',
                    $s->data_chiusura?->format('d/m/Y'),
                    mb_substr((string) $s->testo_segnalazione, 0, 200),
                ], ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function stampaLista(Request $request): \Illuminate\View\View
    {
        $user = auth()->user();
        $tab  = $request->get('tab', 'aperte');
        $q    = trim($request->get('q', ''));
        $idTipologia   = $request->get('id_tipologia');
        $idProvenienza = $request->get('id_provenienza');

        $base = Segnalazione::visibileA($user)
            ->with(['stato', 'tipologia', 'operatore', 'provenienza', 'plesso.istituto', 'utente']);

        if ($q !== '') {
            $base->where(function ($query) use ($q) {
                $query->where('testo_segnalazione', 'like', "%{$q}%")
                      ->orWhere('segnalante', 'like', "%{$q}%")
                      ->orWhere('id_segnalazione', $q);
            });
        }
        if ($idTipologia)   { $base->where('id_tipologia_segnalazione', $idTipologia); }
        if ($idProvenienza) { $base->where('id_provenienza', $idProvenienza); }

        $segnalazioni = match($tab) {
            'evidenza'    => (clone $base)->inEvidenza()->orderByDesc('data_segnalazione')->get(),
            'in_carico'   => (clone $base)->aperte()->whereHas('stato', fn ($q) => $q->where('in_carico', true))->orderByDesc('data_segnalazione')->get(),
            'in_gestione' => (clone $base)->aperte()->whereHas('stato', fn ($q) => $q->where('id_gestione', true))->orderByDesc('data_segnalazione')->get(),
            'chiuse'      => (clone $base)->whereNotNull('data_chiusura')->orderByDesc('data_chiusura')->get(),
            default       => (clone $base)->aperte()->orderByDesc('data_segnalazione')->get(),
        };

        $tabLabels = [
            'aperte' => 'Aperte', 'in_carico' => 'In carico',
            'in_gestione' => 'In gestione', 'evidenza' => 'In evidenza', 'chiuse' => 'Chiuse',
        ];

        return view('gestione.print', compact('segnalazioni', 'tab', 'tabLabels', 'q'));
    }
}
