<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ScaricaAnagrafeMiur;
use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\SedeNonTrovata;
use App\Services\Scuole\SincronizzaScuole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AnagrafeMiurController extends Controller
{
    public function __construct(private AnagrafeMiur $anagrafe, private SincronizzaScuole $sincronizza) {}

    public function index(Request $request): View
    {
        $stato = $this->anagrafe->stato();
        $url = (string) Impostazione::get('miur_anagrafe_url', '');
        $q = trim((string) $request->query('q', ''));
        $comune = trim((string) $request->query('comune', Impostazione::get('miur_comune_default', '')));

        $risultati = $this->anagrafe->haIndice() && ($q !== '' || $comune !== '')
            ? $this->anagrafe->cerca($q, $comune)
            : null;

        $presenti = $risultati === null ? [] : Istituto::query()
            ->whereNotNull('codice_meccanografico')
            ->pluck('codice_meccanografico')
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->flip()
            ->all();

        return view('admin.anagrafe-miur.index', [
            'stato' => $stato,
            'url' => $url,
            'nuovoLink' => $stato['url'] !== null && $url !== '' && $url !== $stato['url'],
            'haIndice' => $this->anagrafe->haIndice(),
            'q' => $q,
            'comune' => $comune,
            'risultati' => $risultati,
            'presenti' => $presenti,
            'mancanti' => $this->anagrafe->haIndice() ? $this->sincronizza->nonPiuPresenti() : [],
        ]);
    }

    public function scarica(): RedirectResponse
    {
        if ($this->anagrafe->stato()['stato'] === 'in_corso') {
            return back()->with('error', 'Download già in corso.');
        }

        $url = (string) Impostazione::get('miur_anagrafe_url', '');
        if ($url === '') {
            return back()->with('error', 'Configura il link in Impostazioni → scuole.');
        }

        $this->anagrafe->salvaStato(['stato' => 'in_corso', 'messaggio' => null]);
        ScaricaAnagrafeMiur::dispatch($url);

        return back()->with('success', 'Download avviato: può richiedere qualche minuto. Ricarica la pagina per vedere lo stato.');
    }

    public function show(string $codice): View
    {
        $istituto = $this->anagrafe->istituto($codice);
        abort_if($istituto === null, 404);

        $presenti = Plesso::query()
            ->whereIn(DB::raw('UPPER(TRIM(codice_meccanografico))'), array_column($istituto['sedi'], 'codice'))
            ->pluck('codice_meccanografico')
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->flip()
            ->all();

        return view('admin.anagrafe-miur.show', [
            'istituto' => $istituto,
            'presenti' => $presenti,
            'locale' => Istituto::conCodice($istituto['codice'])->first(),
            'comune' => mb_strtoupper((string) Impostazione::get('miur_comune_default', '')),
        ]);
    }

    public function salva(Request $request, string $codice): RedirectResponse
    {
        $data = $request->validate([
            'sedi' => ['nullable', 'array'],
            'sedi.*' => ['string', 'size:10'],
        ]);

        try {
            $esito = $this->sincronizza->applicaSelezione($codice, $data['sedi'] ?? []);
        } catch (SedeNonTrovata $e) {
            return back()->with('error', $e->getMessage().'. Ricarica la pagina.');
        }

        $redirect = redirect()->route('admin.anagrafe-miur.show', strtoupper($codice))
            ->with('success', sprintf(
                'Salvato. Sedi create: %d, aggiornate: %d, adottate da anagrafica esistente: %d.',
                count($esito['creati']), count($esito['aggiornati']), count($esito['adottati'])
            ));

        if ($esito['non_selezionati'] !== []) {
            $redirect->with('warning', 'Sedi presenti in ProntoPA ma non selezionate (non rimosse, eliminale da Sedi se serve): '
                .implode(', ', array_map(fn (Plesso $p) => $p->nome, $esito['non_selezionati'])));
        }

        return $redirect;
    }
}
