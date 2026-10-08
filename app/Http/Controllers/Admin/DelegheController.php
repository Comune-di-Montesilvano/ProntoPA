<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Delega;
use App\Models\Istituto;
use App\Models\User;
use App\Services\Deleghe\DelegaService;
use App\Services\Deleghe\RichiestaDelegaRifiutata;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DelegheController extends Controller
{
    public function __construct(private DelegaService $deleghe) {}

    public function index(Request $request): View
    {
        $filtri = $request->validate([
            'stato' => ['nullable', 'in:richiesta,attiva,rifiutata,revocata,scaduta'],
            'id_istituto' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $deleghe = Delega::query()
            ->with(['user', 'istituto', 'plesso'])
            ->when($filtri['stato'] ?? null, fn ($q, $stato) => $q->where('stato', $stato))
            ->when($filtri['id_istituto'] ?? null, fn ($q, $id) => $q->where('id_istituto', $id))
            ->when($filtri['q'] ?? null, fn ($q, $testo) => $q->where(fn ($w) => $w
                ->where('codice_fiscale', 'like', '%'.strtoupper($testo).'%')
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$testo}%"))))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.deleghe.index', [
            'deleghe' => $deleghe,
            'filtri' => $filtri,
            'istituti' => Istituto::where('attivo', true)->orderBy('descrizione')->get(['id_istituto', 'descrizione']),
            'senzaEmail' => Istituto::where('attivo', true)->whereHas('plessi')
                ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
                ->orderBy('descrizione')->get(),
            'bloccati' => User::where('auth_source', 'spid')->whereNotNull('bloccato_at')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.deleghe.create', [
            'istituti' => Istituto::where('attivo', true)->whereHas('plessi')->with('plessi')->orderBy('descrizione')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'codice_fiscale' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16}$/'],
            'id_istituto' => ['required', 'integer', 'exists:istituti,id_istituto'],
            'plessi' => ['nullable', 'array'],
            'plessi.*' => ['integer'],
            'motivo' => ['required', 'string', 'max:255'],
        ], ['codice_fiscale.regex' => 'Codice fiscale di 16 caratteri.']);

        try {
            $this->deleghe->predelega(
                $data['codice_fiscale'],
                Istituto::findOrFail($data['id_istituto']),
                array_map('intval', $data['plessi'] ?? []),
                $data['motivo'],
                $request->user(),
            );
        } catch (RichiestaDelegaRifiutata $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.deleghe.index')->with('success', 'Pre-delega creata: sarà agganciata al primo accesso SPID della persona.');
    }

    public function attiva(Request $request, string $gruppo): RedirectResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:255']]);
        $this->deleghe->attivaDUfficio($gruppo, $data['motivo'], $request->user());

        return back()->with('success', "Delega attivata d'ufficio.");
    }

    public function reinvia(string $gruppo): RedirectResponse
    {
        try {
            $this->deleghe->invia($gruppo, true);
        } catch (RichiestaDelegaRifiutata $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Email alla segreteria inviata di nuovo (il link precedente non vale più).');
    }

    public function revoca(Request $request, Delega $delega): RedirectResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:255']]);
        $this->deleghe->revoca($delega, 'admin', $request->user(), $data['motivo']);

        return back()->with('success', 'Delega revocata.');
    }

    public function sblocca(User $utente): RedirectResponse
    {
        $this->deleghe->sblocca($utente);

        return back()->with('success', "{$utente->name} sbloccato.");
    }
}
