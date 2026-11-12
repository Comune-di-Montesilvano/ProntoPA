<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Services\Scuole\AnagrafeMiur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class EntiController extends Controller
{
    /** Campi che per un ente fonte_dati = miur vengono dall'anagrafe MIUR. */
    public const CAMPI_MIUR = ['descrizione', 'codice_meccanografico', 'email'];

    public function __construct(private AnagrafeMiur $anagrafe) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $filtroCategoria = $request->filled('categoria') ? (int) $request->query('categoria') : null;

        $enti = Istituto::query()
            ->select('istituti.*')
            ->join('categorie_enti', 'categorie_enti.id_categoria', '=', 'istituti.id_categoria')
            ->with(['categoria', 'plessi' => fn ($p) => $p->orderBy('nome')])
            ->when($filtroCategoria, fn ($qb) => $qb->where('istituti.id_categoria', $filtroCategoria))
            ->when($q !== '', function ($qb) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $qb->where(fn ($w) => $w->where('istituti.descrizione', 'like', $like)
                    ->orWhere('istituti.codice_meccanografico', 'like', $like));
            })
            ->orderBy('categorie_enti.ordine')
            ->orderBy('categorie_enti.nome')
            ->orderBy('istituti.descrizione')
            ->paginate(50)
            ->withQueryString();

        $categorie = CategoriaEnte::withCount('istituti')->orderBy('ordine')->orderBy('nome')->get();

        return view('admin.enti.index', [
            'enti' => $enti,
            'categorie' => $categorie,
            'q' => $q,
            'filtroCategoria' => $filtroCategoria,
            'statoMiur' => $this->anagrafe->stato(),
            'inCorsoMiur' => $this->anagrafe->inCorso(),
        ]);
    }

    public function create(): View
    {
        $categorie = CategoriaEnte::orderBy('ordine')->orderBy('nome')->get();
        // Ente nuovo: attivo, senza richieste SPID dall'esterno (come prima per le organizzazioni a mano).
        $ente = new Istituto([
            'id_categoria' => $categorie->first()?->id_categoria,
            'attivo' => true,
            'richieste_delega_spid' => false,
        ]);

        return view('admin.enti.create', compact('categorie', 'ente'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->regole());

        $data['attivo'] = (bool) ($data['attivo'] ?? false);
        $data['richieste_delega_spid'] = (bool) ($data['richieste_delega_spid'] ?? false);
        $data['fonte_dati'] = 'manuale';

        $ente = Istituto::create($data);

        return redirect()->route('admin.enti.show', $ente)
            ->with('success', "Ente \"{$ente->descrizione}\" creato. Ora puoi aggiungere le sedi.");
    }

    public function show(Istituto $ente): View
    {
        $categorie = CategoriaEnte::orderBy('ordine')->orderBy('nome')->get();
        $ente->load(['categoria', 'plessi' => fn ($p) => $p->orderBy('nome')]);

        return view('admin.enti.show', compact('ente', 'categorie'));
    }

    public function update(Request $request, Istituto $ente): RedirectResponse
    {
        $regole = $this->regole();

        // validate() restituisce solo le chiavi validate: i campi MIUR inviati vengono scartati.
        if ($ente->isMiur()) {
            $regole = Arr::except($regole, self::CAMPI_MIUR);
        }

        $data = $request->validate($regole);

        $data['attivo'] = (bool) ($data['attivo'] ?? false);
        $data['richieste_delega_spid'] = (bool) ($data['richieste_delega_spid'] ?? false);

        $ente->update($data);

        return redirect()->route('admin.enti.show', $ente)
            ->with('success', "Ente \"{$ente->descrizione}\" aggiornato.");
    }

    public function destroy(Istituto $ente): RedirectResponse
    {
        if ($ente->plessi()->exists()) {
            return back()->with('error', "Impossibile eliminare: l'ente ha sedi associate. Eliminale o uniscile prima.");
        }

        $nome = $ente->descrizione;
        $ente->delete();

        return redirect()->route('admin.enti.index')
            ->with('success', "Ente \"{$nome}\" eliminato.");
    }

    public function storeSede(Request $request, Istituto $ente): RedirectResponse
    {
        $data = $request->validateWithBag('sede', [
            'nome'                  => ['required', 'string', 'max:255'],
            'codice_meccanografico' => ['nullable', 'string', 'max:50'],
            'indirizzo'             => ['nullable', 'string', 'max:255'],
            'referente'             => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:50'],
            'recapiti'              => ['nullable', 'string', 'max:50'],
        ]);

        $data['id_istituto'] = $ente->id_istituto;
        $data['fonte_dati'] = 'manuale';
        $sede = Plesso::create($data);

        return redirect()->route('admin.enti.show', $ente)
            ->with('success', "Sede \"{$sede->nome}\" aggiunta.");
    }

    /** @return array<string, array<int, mixed>> */
    private function regole(): array
    {
        return [
            'descrizione'           => ['required', 'string', 'max:255'],
            'id_categoria'          => ['required', 'integer', 'exists:categorie_enti,id_categoria'],
            'codice_meccanografico' => ['nullable', 'string', 'max:50'],
            'partita_iva'           => ['nullable', 'digits:11'],
            'codice_fiscale'        => ['nullable', 'string', 'max:16'],
            'dirigente'             => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:50'],
            'domini_email_istituzionali' => ['nullable', 'string', 'max:255'],
            'recapiti'              => ['nullable', 'string', 'max:50'],
            'attivo'                => ['nullable', 'boolean'],
            'richieste_delega_spid' => ['nullable', 'boolean'],
        ];
    }
}
