<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GeocodificaSedi;
use App\Services\Sedi\Geocoding;
use Illuminate\Http\JsonResponse;
use App\Models\Delega;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SediController extends Controller
{
    /** Campi che per una sede fonte_dati = miur vengono dall'anagrafe MIUR. */
    public const CAMPI_MIUR = ['id_istituto', 'nome', 'codice_meccanografico', 'indirizzo', 'email'];

    public function edit(Plesso $sede): View
    {
        $organizzazioni = Istituto::with('categoria')->orderBy('descrizione')->get()
            ->sortBy([['categoria.ordine', 'asc'], ['categoria.nome', 'asc'], ['descrizione', 'asc']])->values();
        $altreSedi = Plesso::where('id_istituto', $sede->id_istituto)
            ->whereKeyNot($sede->id_plesso)->orderBy('nome')->get();
        $storico = $this->storico($sede);

        return view('admin.sedi.edit', compact('sede', 'organizzazioni', 'altreSedi', 'storico'));
    }

    /**
     * Doppione (tipico: plesso del vecchio programma accanto a quello MIUR):
     * segnalazioni e deleghe passano alla sede scelta, poi la sede sparisce.
     * Stesso istituto, altrimenti le deleghe punterebbero a un plesso di un'altra scuola.
     */
    public function unisci(Request $request, Plesso $sede): RedirectResponse
    {
        $data = $request->validate([
            'id_destinazione' => ['required', 'integer', Rule::exists('plessi', 'id_plesso')
                ->where('id_istituto', $sede->id_istituto)->whereNot('id_plesso', $sede->id_plesso)],
        ]);
        $destinazione = Plesso::findOrFail($data['id_destinazione']);

        $spostate = DB::transaction(function () use ($sede, $destinazione) {
            $n = Segnalazione::where('id_plesso', $sede->id_plesso)->update(['id_plesso' => $destinazione->id_plesso]);
            Delega::where('id_plesso', $sede->id_plesso)->update(['id_plesso' => $destinazione->id_plesso]);
            $sede->delete();

            return $n;
        });

        return redirect()->route('admin.enti.show', $destinazione->id_istituto)
            ->with('success', "Sede \"{$sede->nome}\" unita a \"{$destinazione->nome}\": {$spostate} segnalazioni spostate.");
    }

    /** @return array{segnalazioni: int, deleghe: int} */
    private function storico(Plesso $sede): array
    {
        return [
            'segnalazioni' => Segnalazione::where('id_plesso', $sede->id_plesso)->count(),
            'deleghe' => Delega::where('id_plesso', $sede->id_plesso)->count(),
        ];
    }

    public function update(Request $request, Plesso $sede): RedirectResponse
    {
        $regole = [
            'id_istituto'           => ['required', 'integer', 'exists:istituti,id_istituto'],
            'nome'                  => ['required', 'string', 'max:255'],
            'codice_meccanografico' => ['nullable', 'string', 'max:50'],
            'indirizzo'             => ['nullable', 'string', 'max:255'],
            'referente'             => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:50'],
            'recapiti'              => ['nullable', 'string', 'max:50'],
            'latitudine'            => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitudine'],
            'longitudine'           => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitudine'],
        ];

        // validate() restituisce solo le chiavi validate: i campi MIUR inviati vengono scartati.
        if ($sede->isMiur()) {
            $regole = Arr::except($regole, self::CAMPI_MIUR);
        }

        $data = $request->validate($regole);

        $sede->update($data);

        return redirect()->route('admin.enti.show', $sede->id_istituto)
            ->with('success', "Sede \"{$sede->nome}\" aggiornata.");
    }

    /** "Trova dall'indirizzo": propone la posizione, si salva col form. */
    public function geocodifica(Request $request, Plesso $sede, Geocoding $geocoding): JsonResponse
    {
        $indirizzo = (string) ($request->input('indirizzo') ?: $sede->indirizzo);
        $esito = $geocoding->esegui($indirizzo);

        return match ($esito['stato']) {
            Geocoding::TROVATA => response()->json(['lat' => $esito['lat'], 'lng' => $esito['lng']]),
            Geocoding::NON_TROVATA => response()->json(['message' => 'Indirizzo non trovato: sposta il segnaposto a mano.'], 422),
            default => response()->json(['message' => 'Servizio di ricerca non raggiungibile, riprova più tardi.'], 503),
        };
    }

    public function geocodificaMancanti(): RedirectResponse
    {
        GeocodificaSedi::dispatch();

        return back()->with('success', "Ricerca delle posizioni avviata: una sede al secondo, l'esito è in Stato del sistema.");
    }

    public function destroy(Plesso $sede): RedirectResponse
    {
        // Le segnalazioni non hanno FK sul plesso: eliminarlo le lascerebbe senza sede.
        $storico = $this->storico($sede);
        if ($storico['segnalazioni'] + $storico['deleghe'] > 0) {
            return redirect()->route('admin.sedi.edit', $sede)
                ->with('error', "La sede ha {$storico['segnalazioni']} segnalazioni e {$storico['deleghe']} deleghe: usa \"Unisci in un'altra sede\".");
        }

        $nome = $sede->nome;
        $idIstituto = $sede->id_istituto;
        $sede->delete();

        return redirect()->route('admin.enti.show', $idIstituto)
            ->with('success', "Sede \"{$nome}\" eliminata.");
    }
}
