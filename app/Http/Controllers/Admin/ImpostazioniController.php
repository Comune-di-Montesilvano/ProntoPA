<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Impostazione;
use App\Models\StatoSegnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImpostazioniController extends Controller
{
    public function index(): View
    {
        $impostazioni = Impostazione::orderBy('gruppo')->orderBy('chiave')->get()
            ->groupBy('gruppo');

        $statiSegnalazioni = StatoSegnalazione::orderBy('id_stato')->get();

        return view('admin.impostazioni', compact('impostazioni', 'statiSegnalazioni'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'impostazioni'          => ['required', 'array'],
            'impostazioni.*'        => ['nullable', 'string', 'max:500'],
        ]);

        // Chiavi di tipo password: non sovrascrivere se il campo è stato lasciato vuoto
        $passwordChiavi = Impostazione::where('tipo', 'password')->pluck('chiave')->all();

        foreach ($data['impostazioni'] as $chiave => $valore) {
            if (($valore === null || $valore === '') && in_array($chiave, $passwordChiavi, true)) {
                continue;
            }

            if ($chiave === 'publication_auto_state_id' && $valore !== '' && $valore !== null) {
                $valore = (int) $valore;
            }

            Impostazione::set($chiave, $valore);
        }

        return back()->with('success', 'Impostazioni salvate.');
    }
}
