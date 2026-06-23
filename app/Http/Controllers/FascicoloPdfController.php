<?php

namespace App\Http\Controllers;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class FascicoloPdfController extends Controller
{
    public function fascicolo(Segnalazione $segnalazione): Response
    {
        $this->authorize('view', $segnalazione);

        $segnalazione->load([
            'stato',
            'tipologia.gruppo',
            'provenienza',
            'plesso.istituto',
            'operatore',
            'utente',
            'appalto.impresa',
            'note.autore',
            'storicoStati.stato',
            'storicoStati.utente',
            'allegati',
        ]);

        $nomeEnte = Impostazione::get('ente_nome', 'ProntoPA');

        $pdf = Pdf::loadView('segnalazioni.fascicolo-pdf', compact('segnalazione', 'nomeEnte'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("fascicolo-segnalazione-{$segnalazione->id_segnalazione}.pdf");
    }
}
