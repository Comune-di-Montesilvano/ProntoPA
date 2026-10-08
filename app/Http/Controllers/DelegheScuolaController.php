<?php

namespace App\Http\Controllers;

use App\Models\Delega;
use App\Models\Istituto;
use App\Services\Deleghe\DelegaService;
use App\Services\Deleghe\RichiestaDelegaRifiutata;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DelegheScuolaController extends Controller
{
    public function __construct(private DelegaService $deleghe) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isSpid(), 403);

        $q = trim((string) $request->query('q', ''));
        $istituti = $q === '' ? collect() : Istituto::query()
            ->where('attivo', true)
            ->whereHas('plessi')
            ->where(fn ($w) => $w->where('descrizione', 'like', "%{$q}%")->orWhere('codice_meccanografico', 'like', "%{$q}%"))
            ->orderBy('descrizione')
            ->limit(30)
            ->get();

        return view('scuola.deleghe.index', [
            'deleghe' => $user->deleghe()->with(['istituto', 'plesso'])->latest()->get(),
            'q' => $q,
            'istituti' => $istituti,
        ]);
    }

    public function create(Request $request, Istituto $istituto): View
    {
        abort_unless($request->user()->isSpid(), 403);
        abort_unless($istituto->attivo && $istituto->plessi()->exists(), 404);

        return view('scuola.deleghe.create', [
            'istituto' => $istituto,
            'plessi' => $istituto->plessi()->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request, Istituto $istituto): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSpid(), 403);

        $data = $request->validate([
            'tutto' => ['nullable', 'boolean'],
            'plessi' => ['required_without:tutto', 'array'],
            'plessi.*' => ['integer'],
        ], ['plessi.required_without' => "Scegli almeno un plesso oppure tutto l'istituto."]);

        $idPlessi = ($data['tutto'] ?? false) ? [] : array_map('intval', $data['plessi'] ?? []);

        try {
            $esito = $this->deleghe->richiedi($user, $istituto, $idPlessi);
        } catch (RichiestaDelegaRifiutata $e) {
            return back()->with('error', $e->getMessage());
        }

        $messaggio = $esito['inviata']
            ? 'Richiesta inviata alla segreteria della scuola.'
            : 'Richiesta registrata: partirà verso la segreteria domattina.';
        if ($esito['esclusi'] > 0) {
            $messaggio .= " Esclusi {$esito['esclusi']} plessi per cui hai già una delega.";
        }

        return redirect()->route('scuola.deleghe.index')->with('success', $messaggio);
    }

    public function rinuncia(Request $request, Delega $delega): RedirectResponse
    {
        abort_unless($delega->user_id === $request->user()->id, 403);

        // Una richiesta multi-plesso è un'unica email alla segreteria: si annulla tutta.
        $righe = $delega->stato === Delega::RICHIESTA
            ? Delega::where('gruppo_richiesta', $delega->gruppo_richiesta)
                ->where('user_id', $request->user()->id)
                ->where('stato', Delega::RICHIESTA)
                ->get()
            : collect([$delega]);

        foreach ($righe as $riga) {
            $this->deleghe->revoca($riga, 'utente', $request->user(), 'rinuncia del delegato');
        }

        return redirect()->route('scuola.deleghe.index')->with('success', 'Delega chiusa.');
    }
}
