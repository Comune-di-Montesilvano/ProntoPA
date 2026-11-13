<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipologiaSegnalazione;
use App\Services\Tipologie\UnisciTipologie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin → Tipologie: un solo elenco, uguale per tutte le sedi (1.0.0). */
class TipologieController extends Controller
{
    public function index(): View
    {
        $tipologie = TipologiaSegnalazione::withCount('segnalazioni')->orderBy('descrizione')->get();

        return view('admin.tipologie.index', compact('tipologie'));
    }

    public function store(Request $request): RedirectResponse
    {
        $dati = $this->valida($request);
        TipologiaSegnalazione::create($dati + ['attiva' => true]);

        return redirect()->route('admin.tipologie.index')->with('success', "Tipologia \"{$dati['descrizione']}\" aggiunta.");
    }

    public function update(Request $request, TipologiaSegnalazione $tipologia): RedirectResponse
    {
        $dati = $this->valida($request, $tipologia) + ['attiva' => $request->boolean('attiva')];
        $tipologia->update($dati);

        return redirect()->route('admin.tipologie.index')->with('success', "Tipologia \"{$tipologia->descrizione}\" aggiornata.");
    }

    public function unisci(Request $request, TipologiaSegnalazione $tipologia, UnisciTipologie $servizio): RedirectResponse
    {
        $dati = $request->validate([
            'id_destinazione' => ['required', 'integer', Rule::exists('tipologie_segnalazioni', 'id_tipologia_segnalazione'),
                Rule::notIn([$tipologia->id_tipologia_segnalazione])],
        ], ['id_destinazione.not_in' => 'Scegli un\'altra tipologia.']);

        $destinazione = TipologiaSegnalazione::findOrFail($dati['id_destinazione']);
        $servizio->unisci($tipologia->id_tipologia_segnalazione, $destinazione->id_tipologia_segnalazione);

        return redirect()->route('admin.tipologie.index')
            ->with('success', "\"{$tipologia->descrizione}\" unita in \"{$destinazione->descrizione}\": le segnalazioni sono passate lì.");
    }

    /** @return array{descrizione: string, icona: ?string} */
    private function valida(Request $request, ?TipologiaSegnalazione $ignora = null): array
    {
        $unica = Rule::unique('tipologie_segnalazioni', 'descrizione');
        if ($ignora) {
            $unica->ignore($ignora->id_tipologia_segnalazione, 'id_tipologia_segnalazione');
        }

        /** @var array{descrizione: string, icona: ?string} */
        $dati = $request->validate([
            // La colonna è varchar(50) e icona NOT NULL default '' (MariaDB strict).
            'descrizione' => ['required', 'string', 'max:50', $unica],
            'icona' => ['nullable', 'string', 'max:50', 'regex:/^fa[bsr]? fa-[a-z0-9-]+$/'],
        ]);
        $dati['icona'] = $dati['icona'] ?? '';

        return $dati;
    }
}
